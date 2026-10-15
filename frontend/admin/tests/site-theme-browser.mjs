import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = '/tmp/howstack-unified-theme-ui'
const browser = await chromium.launch({ headless: true }), errors = []
const themes = [
  { id: 1, theme_id: 'default', name: '默认官方主题', version: '2.0.0', digest: 'a'.repeat(64), components: ['website', 'pc', 'mobile'], active: ['website', 'pc'], builtin: true, cover: '/theme-assets/default/cover.svg' },
  { id: 2, theme_id: 'only-console', name: '会员主题', version: '1.0.0', digest: 'b'.repeat(64), components: ['pc', 'mobile'], active: [], builtin: false, cover: '/theme-assets/only-console/cover.svg' },
  { id: 3, theme_id: 'only-website', name: '官网主题', version: '1.0.0', digest: 'c'.repeat(64), components: ['website'], active: [], builtin: false, cover: '/theme-assets/only-website/cover.svg' },
  { id: 4, theme_id: 'pc-only', name: '仅PC主题', version: '1.0.0', digest: 'd'.repeat(64), components: ['pc'], active: [], builtin: false, cover: '/theme-assets/pc-only/cover.svg' },
]
const schema = { type: 'object', additionalProperties: false, required: ['greeting'], properties: { greeting: { type: 'string', title: '欢迎文字', maxLength: 100, default: '欢迎使用' } } }
const permissions = ['admin.page.website.view', 'admin.page.website.activate', 'admin.page.website.update', 'admin.page.website.install', 'admin.page.website.uninstall']
async function setup(grants = permissions) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } }), page = await context.newPage()
  const state = { binding: { release_id: 1, default_release_id: 1, revision: 1, mobile_enabled: false, website: { release_id: 1, fallback: false }, console: { release_id: 1, fallback: false, mobile_available: true } }, writes: [], settingsRequests: [], status: 200 }
  page.on('pageerror', error => errors.push(error.message))
  await context.route('**/theme-assets/**', route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 60"><rect width="100" height="60" fill="#165dff"/></svg>' }))
  await context.route('**/api/admin/v1/**', async route => {
    const request = route.request(), path = new URL(request.url()).pathname.replace('/api/admin/v1', '')
    let status = 200, data = [], message = 'ok'
    if (path === '/auth/me') data = { user: { id: 1, username: 'themes', nickname: '主题管理员', is_founder: false, last_login_at: null }, permissions: grants }
    else if (path === '/themes') data = themes.map(theme => ({ ...theme, active: [...(state.binding.website.release_id === theme.id ? ['website'] : []), ...(state.binding.console.release_id === theme.id ? theme.components.filter(type => type !== 'website' && (type !== 'mobile' || state.binding.mobile_enabled)) : [])] }))
    else if (path === '/themes/bindings' && request.method() === 'PUT') {
      state.writes.push(request.postDataJSON()); status = state.status
      if (status === 409) { data = null; message = '修订已更新，请重新加载' }
      else if (status >= 500) { data = null; message = '主题保存失败' }
      else {
        const id = request.postDataJSON().release_id
        const consoleId = id === 3 ? 1 : id
        state.binding = { ...state.binding, release_id: id, revision: state.binding.revision + 1, mobile_enabled: request.postDataJSON().mobile_enabled, website: { release_id: [2, 4].includes(id) ? 1 : id, fallback: [2, 4].includes(id) }, console: { release_id: consoleId, fallback: id === 3, mobile_available: themes.find(theme => theme.id === consoleId).components.includes('mobile') } }; data = state.binding
      }
    } else if (path === '/themes/bindings') data = state.binding
    else if (path.endsWith('/settings')) { state.settingsRequests.push(path); data = { revision: 1, schema, settings: { greeting: '欢迎使用' } } }
    else if (path === '/website/images/availability') data = { available: false }
    await route.fulfill({ status, json: { code: status >= 400 ? status * 100 : 0, message, data, request_id: 'theme-browser' } })
  })
  await page.goto(`${base}/themes`)
  await page.getByText('已安装主题', { exact: true }).waitFor()
  await page.getByText('使用中', { exact: true }).waitFor()
  assert.equal(await page.getByText('使用主题', { exact: true }).count(), 0)
  assert.equal(await page.getByRole('button', { name: '保存', exact: true }).count(), 0)
  assert.equal(await page.locator('.website-page .arco-select').count(), 0)
  assert.equal(await page.getByText('版本记录', { exact: true }).count(), 0)
  assert.equal(await page.getByText('配置', { exact: true }).count(), 2)
  assert.equal(await card(page, '会员主题').getByText('配置', { exact: true }).count(), 0)
  return { context, page, state }
}
const card = (page, name) => page.locator('.theme-package').filter({ has: page.getByText(name, { exact: true }) })
async function activate(page, name) {
  await card(page, name).getByText('启用', { exact: true }).click()
  await card(page, name).getByText('使用中', { exact: true }).waitFor()
  await page.waitForFunction(() => !document.querySelector('[role="switch"]')?.disabled)
}
try {
  await mkdir(output, { recursive: true })
  const { context, page, state } = await setup()
  const mobile = page.getByRole('switch', { name: '启用独立移动端模板' })
  assert.equal(await mobile.isEnabled(), true)
  assert.equal(await mobile.isChecked(), false)
  await activate(page, '会员主题')
  assert.deepEqual(state.writes[0], { revision: 1, release_id: 2, mobile_enabled: false })
  assert.equal(await card(page, '会员主题').getByText('启用', { exact: true }).count(), 0)
  assert.equal(await page.getByText('使用中', { exact: true }).count(), 1)
  await mobile.click()
  await page.waitForFunction(() => document.querySelector('[role="switch"]')?.getAttribute('aria-checked') === 'true' && !document.querySelector('[role="switch"]')?.disabled)
  assert.deepEqual(state.writes[1], { revision: 2, release_id: 2, mobile_enabled: true })
  await mobile.click()
  await page.waitForFunction(() => document.querySelector('[role="switch"]')?.getAttribute('aria-checked') === 'false' && !document.querySelector('[role="switch"]')?.disabled)
  assert.deepEqual(state.writes[2], { revision: 3, release_id: 2, mobile_enabled: false })
  await card(page, '仅PC主题').getByText('启用', { exact: true }).click()
  await card(page, '仅PC主题').getByText('使用中', { exact: true }).waitFor()
  assert.equal(await mobile.isDisabled(), true)
  assert.equal(await mobile.isChecked(), false)
  assert.deepEqual(state.writes[3], { revision: 4, release_id: 4, mobile_enabled: false })
  await activate(page, '官网主题')
  assert.equal(await mobile.isEnabled(), true)
  await mobile.click()
  await page.waitForFunction(() => document.querySelector('[role="switch"]')?.getAttribute('aria-checked') === 'true' && !document.querySelector('[role="switch"]')?.disabled)
  assert.deepEqual(state.writes[5], { revision: 6, release_id: 3, mobile_enabled: true })
  assert.equal(state.binding.console.release_id, 1)
  await page.screenshot({ path: `${output}/desktop.png`, fullPage: true })
  await page.getByText('配置', { exact: true }).first().click()
  await page.getByRole('textbox', { name: '欢迎文字', exact: true }).waitFor()
  assert.equal(await page.getByRole('radio').count(), 0)
  await page.mouse.click(5, 180)
  await page.getByRole('textbox', { name: '欢迎文字', exact: true }).waitFor({ state: 'hidden' })
  await page.getByText('配置', { exact: true }).first().click()
  await page.getByRole('textbox', { name: '欢迎文字', exact: true }).fill('尚未保存的修改')
  await page.mouse.click(5, 180)
  await page.getByText('放弃修改', { exact: true }).waitFor()
  await page.getByRole('button', { name: '取消', exact: true }).last().click()
  await page.getByText('放弃修改', { exact: true }).waitFor({ state: 'hidden' })
  assert.equal(await page.getByRole('textbox', { name: '欢迎文字', exact: true }).inputValue(), '尚未保存的修改')
  await page.mouse.click(5, 180)
  await page.getByRole('button', { name: '确定', exact: true }).click()
  await page.getByRole('textbox', { name: '欢迎文字', exact: true }).waitFor({ state: 'hidden' })
  await page.setViewportSize({ width: 390, height: 844 })
  await page.getByText('配置', { exact: true }).first().click()
  await page.getByRole('textbox', { name: '欢迎文字', exact: true }).waitFor()
  await page.mouse.click(5, 180)
  await page.getByRole('textbox', { name: '欢迎文字', exact: true }).waitFor({ state: 'hidden' })
  await page.screenshot({ path: `${output}/mobile.png`, fullPage: true })
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true)
  assert.ok(state.settingsRequests.length > 0)
  assert.ok(state.settingsRequests.every(path => path.includes('/components/website/settings')))
  await context.close()
  const conflict = await setup(); conflict.state.status = 409
  await card(conflict.page, '会员主题').getByText('启用', { exact: true }).click()
  await conflict.page.getByText('修订已更新，请重新加载', { exact: true }).waitFor()
  assert.equal(await card(conflict.page, '默认官方主题').getByText('使用中', { exact: true }).count(), 1)
  assert.equal(await conflict.page.getByRole('switch').isChecked(), false)
  conflict.state.status = 200; conflict.state.binding.revision = 9
  await conflict.page.getByRole('button', { name: '重新加载', exact: true }).click()
  await conflict.page.getByText('修订已更新，请重新加载', { exact: true }).waitFor({ state: 'hidden' })
  await activate(conflict.page, '会员主题')
  assert.deepEqual(conflict.state.writes[1], { revision: 9, release_id: 2, mobile_enabled: false })
  conflict.state.status = 500
  await conflict.page.getByRole('switch').click()
  await conflict.page.getByText('主题保存失败', { exact: true }).waitFor()
  assert.equal(await conflict.page.getByRole('switch').isChecked(), false)
  await conflict.context.close()
  const readonly = await setup(['admin.page.website.view'])
  assert.equal(await readonly.page.getByText('启用', { exact: true }).count(), 0)
  assert.equal(await readonly.page.getByRole('switch').isDisabled(), true)
  await readonly.context.close()
  assert.deepEqual(errors, [])
  console.log('主题卡片模拟浏览器通过：直接启用、当前状态、移动开关即时保存、仅PC禁用、官网回退默认会员组件、失败保留真实状态、409重载修订、权限、官网配置与遮罩关闭、1440/390无溢出。')
} finally { await browser.close() }
