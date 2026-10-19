import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
await mkdir('/tmp/bos-community-scope', { recursive: true })
const permissions = ['admin.page.website.view', 'admin.page.website.activate', 'admin.page.website.install', 'admin.page.website.uninstall', 'admin.page.dashboard.view', 'admin.page.settings.view', 'admin.api-docs.view', 'admin.tickets.list.view', 'admin.tickets.types.view', 'admin.tickets.quick-replies.view', 'admin.members.view', 'admin.member-groups.view', 'admin.member-levels.view', 'admin.member-levels.create', 'admin.services.cloud-servers.view', 'admin.services.physical-servers.view', 'admin.services.server-colocations.view', 'admin.app-center.view', 'admin.app-center.install', 'admin.updates.view', 'admin.license.view', ...['invoices', 'withdrawals', 'payments', 'recharges', 'refunds', 'transactions'].flatMap(kind => ['view', 'create'].map(action => `admin.finance.${kind}.${action}`))]
try {
 for (const width of [1440, 390]) {
  const context = await browser.newContext({ viewport: { width, height: 960 } }), page = await context.newPage()
  const errors = [], requests = []
  page.on('pageerror', error => errors.push(error.message))
  await context.route('**/api/admin/v1/**', async route => {
    const path = new URL(route.request().url()).pathname.replace('/api/admin/v1', '')
    requests.push([route.request().method(), path])
    let data = { data: [], total: 0 }
    if (path === '/auth/me') data = { user: { id: 1, username: 'fixture', nickname: '验收管理员', is_founder: false }, permissions }
    if (path === '/themes') data = [1,2].map(id => ({ id, theme_id: id === 1 ? 'default' : 'extra', name: id === 1 ? '默认官方主题' : '额外主题', version: '2.0.0', digest: 'a'.repeat(64), components: ['website','pc','mobile'], active: id === 1 ? ['website','pc'] : [], builtin: id === 1, cover: '/theme-assets/default/cover.svg' }))
    if (path === '/themes/bindings') data = { release_id: 1, default_release_id: 1, revision: 1, mobile_enabled: false, website: { release_id: 1, fallback: false }, console: { release_id: 1, fallback: false, mobile_available: true } }
    if (path === '/system/version') data = { current_version: '0.1.0' }
    if (path === '/messages/summary') data = { unread: 0, categories: {}, topics: {} }
    if (path === '/capabilities') data = { capabilities: {} }
    if (path === '/application-center/owned') data = { items: [], jobs: [] }
    await route.fulfill({ json: { code: 0, message: 'ok', data, request_id: 'scope-admin' } })
  })
  await page.goto('http://127.0.0.1:5175/admin/maintenance')
  await page.getByText('当前版本', { exact: true }).waitFor()
  await page.getByText('0.1.0', { exact: true }).waitFor()
  if (width === 390) await page.getByRole('button', { name: '打开导航菜单', exact: true }).click()
  const menus = page.locator('.arco-menu')
  for (const group of ['客户管理', '财务管理', '业务管理', '服务管理', '系统管理']) {
    const node = menus.getByText(group, { exact: true }).first()
    if (await node.isVisible()) await node.click()
  }
  for (const text of ['会员等级', '发票记录', '提现记录', '物理服务器', '服务器托管', '应用中心', '授权与更新']) assert.equal(await menus.getByText(text, { exact: true }).count(), 0, text)
  assert.ok(await menus.getByText('工单管理', { exact: true }).count())
  assert.ok(await menus.getByText('充值退还', { exact: true }).count())
  await page.screenshot({ path: `/tmp/bos-community-scope/admin-${width}.png`, fullPage: true })
  if (width === 390) await page.getByRole('button', { name: '关闭导航菜单', exact: true }).first().click()
  for (const [path, hidden] of [['invoices','申请开票'], ['member-levels','新增会员等级']]) {
    await page.goto(`http://127.0.0.1:5175/admin/${path}`)
    await page.getByText('暂无数据', { exact: true }).first().waitFor()
    assert.equal(await page.getByRole('button', { name: hidden, exact: true }).count(), 0)
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false)
  }
  await page.goto('http://127.0.0.1:5175/admin/withdrawals')
  await page.getByRole('button', { name: '申请充值退还', exact: true }).waitFor()
  await page.goto('http://127.0.0.1:5175/admin/themes')
  await page.getByText('默认官方主题', { exact: true }).waitFor()
  assert.equal(await page.getByText('额外主题', { exact: true }).count(), 0)
  assert.equal(await page.getByRole('button', { name: '上传主题', exact: true }).count(), 0)
  assert.equal(await page.getByText('启用', { exact: true }).count(), 0)
  assert.ok(await page.getByText('配置', { exact: true }).count())
  await page.getByRole('switch', { name: '启用独立移动端模板', exact: true }).waitFor()
  await page.goto('http://127.0.0.1:5175/admin/application-center')
  await page.getByText('安装记录', { exact: true }).waitFor()
  assert.equal(await page.getByText('官方应用商店', { exact: true }).count(), 0)
  assert.equal(requests.some(([,path]) => path === '/application-center/store-link'), false)
  assert.equal(requests.some(([method,path]) => method !== 'GET' || (path.startsWith('/official-platform') && path !== '/official-platform/capabilities') || path.startsWith('/system-updates')), false, '不触发商业连接/升级或业务写入')
  assert.deepEqual(errors, [])
  await context.close()
  console.log(`后台 ${width}px：日常菜单收口、工单保留、历史记录可读/新增隐藏、本机版本及无溢出通过（模拟 API）`)
 }
} finally { await browser.close() }
