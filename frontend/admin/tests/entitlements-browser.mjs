import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const origin = process.env.ADMIN_ORIGIN ?? 'http://127.0.0.1:5175'
const output = '/tmp/howstack-entitlements-browser'
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
const errors = []
let actor = 1
let loggedIn = true
let allowed = true
let malformed = false
let deadline = null
let delayedActor = null
let uiStatus = 'community'
let capReads = 0
const account = { configured: false, origin: '', software_code: 'howstack-bos', installation_id: 'fixture-instance', connected: false,
  reconnect_required: false, connection: null, checked_at: null, pairing: null }
const fixture = `<!doctype html><html><body><div id="root"></div><script type="module">
import RefreshRuntime from '/admin/@react-refresh';
RefreshRuntime.injectIntoGlobalHook(window);
window.$RefreshReg$ = () => {}; window.$RefreshSig$ = () => (type) => type;
window.__vite_plugin_react_preamble_installed__ = true;
const React = (await import('/admin/node_modules/.vite/deps/react.js')).default;
const ReactDOM = await import('/admin/node_modules/.vite/deps/react-dom_client.js');
const createRoot = ReactDOM.createRoot ?? ReactDOM.default.createRoot;
const {AuthProvider} = await import('/admin/src/auth/AuthProvider.tsx');
const {useAdminAuth} = await import('/admin/src/auth/AuthContext.ts');
const {CapabilitiesProvider} = await import('/admin/src/auth/CapabilitiesProvider.tsx');
const {useCapabilities} = await import('/admin/src/auth/CapabilitiesContext.ts');
function Probe(){ const auth=useAdminAuth(); const caps=useCapabilities();
return React.createElement('main',null,
React.createElement('span',{id:'actor'},auth.user?.id??'none'),
React.createElement('span',{id:'error'},caps.error),
React.createElement('button',{id:'protected',disabled:!caps.can('fixture.advanced','start_new')},'高级操作'),
React.createElement('button',{id:'profile',onClick:()=>auth.refreshAccess()},'刷新主体'),
React.createElement('button',{id:'logout',onClick:()=>auth.logout()},'退出'));
}
createRoot(document.getElementById('root')).render(React.createElement(AuthProvider,null,React.createElement(CapabilitiesProvider,null,React.createElement(Probe))));
</script></body></html>`
try {
  const context = await browser.newContext()
  await context.route('**/admin/entitlements-fixture', (route) => route.fulfill({ contentType: 'text/html', body: fixture }))
  await context.route('**/api/admin/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/admin/v1','')
    let data = null
    if (path === '/auth/me' && !loggedIn) return route.fulfill({ status: 401, json: { code: 40100, message: '请登录', data: null, request_id: 'fixture-logout' } })
    if (path === '/auth/me') data = { user: { id: actor, username: `fixture-${actor}`, nickname: '测试', is_founder: false, department_id: null, last_login_at: null }, permissions: ['admin.license.view'] }
    else if (path === '/auth/logout') { loggedIn = false; data = null }
    else if (path === '/official-platform/capabilities') {
      capReads++
      const selected = actor
      const permit = allowed
      data = { capabilities: { 'fixture.advanced': { installed: true, status: 'active', operations: { start_new: malformed ? 'true' : permit }, reasons: { start_new: permit ? 'ALLOWED' : 'ENTITLEMENT_EXPIRED' } } }, entitlement_revision: selected, next_check_at: null, next_check_in_seconds: deadline }
      if (delayedActor === selected) await new Promise((resolve) => setTimeout(resolve, 500))
    } else if (path === '/official-platform/connection') data = account
    else if (path === '/official-platform/license') data = { software_code: 'howstack-bos', edition: uiStatus === 'community' ? 'community' : 'professional', edition_name: uiStatus === 'community' ? '社区版' : '专业版',
      status: uiStatus, status_text: uiStatus === 'community' ? '无需商业授权' : uiStatus === 'expired' ? '使用权益已到期' : '联网验证期限已到', license_no: uiStatus === 'community' ? null : 'TEST-ONLY',
      offline_until: '2030-01-01 00:00:00', verified_at: '2026-10-13 00:00:00', installation_id: 'fixture-instance', entitlement_revision: 2, last_error: null, account,
      entitlements: uiStatus === 'community' ? [] : [{ capability: 'analytics.advanced', name: 'analytics.advanced', implemented: false, installed: false, status: uiStatus, use_until: '2026-10-12 00:00:00', updates_until: '2030-01-01 00:00:00', limits: {} }] }
    else if (path === '/system-updates/notice') data = { available: false, current_version: '0.1.0', latest_version: null, changelog: { added: [], improved: [], fixed: [], notices: [] }, force_update: false, checked_at: null, last_attempt_at: null, last_error: null }
    else { await route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ code: 40400, message: 'mock only', data: null, request_id: 'fixture' }) }); return }
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, message: 'ok', data, request_id: 'fixture' }) }).catch(() => {})
  })
  const page = await context.newPage()
  page.on('pageerror', (error) => { errors.push(error.message); console.error(error.message) })
  page.on('requestfailed', (request) => console.error('请求失败', request.url(), request.failure()?.errorText))
  await page.goto(`${origin}/admin/entitlements-fixture`)
  await page.locator('#protected:enabled').waitFor({ timeout: 10000 })
  console.log('通过：实际能力Provider消费有效操作投影')
  malformed = true
  await page.evaluate(() => dispatchEvent(new Event('admin:entitlements-changed')))
  await page.locator('#protected:disabled').waitFor()
  await page.locator('#error').filter({ hasText: '响应格式无效' }).waitFor()
  console.log('通过：畸形布尔值失败关闭，不默认授予能力')
  malformed = false
  deadline = 1
  await page.evaluate(() => dispatchEvent(new Event('admin:entitlements-changed')))
  await page.locator('#protected:enabled').waitFor()
  const before = capReads
  allowed = false
  await page.waitForFunction(() => document.querySelector('#protected')?.disabled === true)
  assert.ok(capReads > before)
  console.log('通过：到期剩余秒数驱动重新判断')
  deadline = null
  allowed = true
  await page.evaluate(() => dispatchEvent(new Event('admin:entitlements-changed')))
  await page.locator('#protected:enabled').waitFor()
  delayedActor = 1
  await page.evaluate(() => dispatchEvent(new Event('admin:entitlements-changed')))
  actor = 2
  allowed = false
  await page.locator('#profile').click()
  await page.locator('#actor').filter({ hasText: '2' }).waitFor()
  await page.waitForTimeout(650)
  assert.equal(await page.locator('#protected').isDisabled(), true)
  console.log('通过：A到B主体切换取消旧请求，旧权益不能回写')
  await page.locator('#logout').click()
  await page.locator('#actor').filter({ hasText: 'none' }).waitFor()
  assert.equal(await page.locator('#protected').isDisabled(), true)
  console.log('通过：退出即时关闭商业操作')
  actor = 1
  loggedIn = true
  delayedActor = null
  const ui = await context.newPage()
  ui.on('pageerror', (error) => errors.push(error.message))
  await ui.goto(`${origin}/admin/maintenance`)
  await ui.getByText('暂无商业功能权益，社区版功能可正常使用。', { exact: true }).waitFor()
  console.log('通过：无商业权益保持社区功能提示')
  uiStatus = 'expired'
  await ui.reload()
  await ui.getByText('使用权益已到期', { exact: true }).waitFor()
  await ui.getByText('专业版', { exact: true }).waitFor()
  await ui.getByText('尚未接入', { exact: true }).waitFor()
  for (const width of [1440, 390]) {
    await ui.setViewportSize({ width, height: 900 })
    await ui.waitForTimeout(200)
    assert.ok(await ui.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
    await ui.screenshot({ path: `${output}/license-expired-${width}.png`, fullPage: true })
  }
  console.log('通过：到期保留购买版本，逐项期限及未接入状态展示，1440和390px无页面溢出')
  assert.deepEqual(errors, [])
  console.log(JSON.stringify({ realWrites: 0, mockedApi: true, screenshots: output }))
  await context.close()
} finally { await browser.close() }
