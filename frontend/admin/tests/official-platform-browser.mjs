import assert from 'node:assert/strict'
import { readFile, mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = process.env.ADMIN_SCREENSHOT_DIR ?? '/tmp/howstack-official-browser'
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ channel: process.env.HOWSTACK_BROWSER_CHANNEL ?? 'chrome', headless: true })
const passed = []
const check = (name) => { passed.push(name); console.log(`通过：${name}`) }
const noOverflow = async (page) => assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), '页面不能横向溢出')
const settledNarrowLayout = async (page) => page.waitForFunction(() => {
  const card = document.querySelector('.page-card')?.getBoundingClientRect()
  return card && card.x <= 24 && card.width >= innerWidth - 48
})
const errors = []
try {
  const anonymous = await browser.newContext()
  const anonymousPage = await anonymous.newPage()
  await anonymousPage.goto(`${base}/maintenance`)
  await anonymousPage.waitForURL(`${base}/login`)
  check('真实Webman匿名访问授权页面跳转登录')
  await anonymous.close()
  if (process.env.ADMIN_PREVIEW_COOKIE_FILE) {
    const cookie = JSON.parse(await readFile(process.env.ADMIN_PREVIEW_COOKIE_FILE, 'utf8'))
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } })
    await context.addCookies([{ ...cookie, domain: '127.0.0.1', path: '/', httpOnly: true, secure: false, sameSite: 'Lax' }])
    const page = await context.newPage()
    page.on('pageerror', (error) => errors.push(error.message))
    await page.goto(`${base}/maintenance`)
    await page.getByText('无需商业授权', { exact: true }).waitFor()
    await page.getByRole('button', { name: '授权码激活', exact: true }).click()
    await page.getByRole('button', { name: '绑定账号', exact: true }).waitFor()
    assert.equal(await page.getByRole('button', { name: '绑定账号', exact: true }).isDisabled(), true)
    check('真实后台默认社区版与未配置连接状态')
    await page.getByRole('dialog').getByRole('button', { name: 'Close', exact: true }).click()
    await page.locator('.official-account-panel').waitFor({ state: 'hidden' })
    const unsafe = await context.request.post(`${new URL(base).origin}/api/admin/v1/system-updates/check`)
    assert.equal(unsafe.status(), 403)
    const denied = await unsafe.json()
    assert.equal(denied.request_id, unsafe.headers()['x-request-id'])
    assert.notEqual(denied.code, 0)
    check('真实后台写接口拒绝缺失Origin且保持统一错误与请求编号')
    await noOverflow(page)
    await page.screenshot({ path: `${output}/maintenance-1440.png`, fullPage: true })
    await page.getByText('版本信息', { exact: true }).waitFor()
    await page.getByText('0.1.0', { exact: true }).waitFor()
    await page.getByRole('button', { name: '检查更新', exact: true }).click()
    await page.getByText('请先连接皓量云擎账号', { exact: true }).waitFor()
    assert.equal(await page.getByRole('button', { name: '立即更新', exact: true }).isDisabled(), true)
    check('没有官网连接时检查失败，不能显示已最新或启动更新')
    await page.setViewportSize({ width: 390, height: 844 })
    await settledNarrowLayout(page)
    await noOverflow(page)
    await page.screenshot({ path: `${output}/updates-390.png`, fullPage: true })
    check('390px授权与更新描述列表及表格无页面溢出')
    await page.goto(`${base}/application-center`)
    await page.getByRole('heading', { name: '应用中心', exact: true }).waitFor()
    await page.getByText('请先连接皓量云擎账号', { exact: true }).waitFor()
    await settledNarrowLayout(page)
    await noOverflow(page)
    await page.screenshot({ path: `${output}/applications-390.png`, fullPage: true })
    check('真实应用中心显示连接要求，390px无横向溢出')
    await context.close()
  }
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } })
  const page = await context.newPage()
  page.on('pageerror', (error) => errors.push(error.message))
  let activations = 0
  let connected = false
  let reconnectRequired = false
  let connectionFailure = false
  let installedState = 'installed'
  let installedRevision = 1
  let newInstalled = false
  let catalogRequests = 0
  let installRequests = 0
  let uninstallRequests = 0
  const account = () => ({ configured: true, origin: 'https://official.example', software_code: 'howstack-bos', installation_id: '1ba62342-c4a0-4a80-868e-657cbc8336de', connected,
    reconnect_required: reconnectRequired, checked_at: null, connection: connected ? { connection_id: 'test-connection', license_no: 'TEST', license_valid: true, license_expires_at: null, connection_mode: 'licensed', account_name: '模拟官网会员', edition_code: 'premium', edition_name: '旗舰版' } : null })
  let permissions = ['admin.license.view', 'admin.license.manage', 'admin.updates.view', 'admin.updates.check', 'admin.updates.execute', 'admin.platform.connect', 'admin.app-center.view', 'admin.app-center.install', 'admin.app-center.uninstall']
  await context.route('**/api/admin/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/admin/v1', '')
    let status = 200
    let data = null
    let message = '成功'
    if (path === '/auth/me') data = { user: { id: 1, username: 'mock-admin', nickname: '模拟管理员', is_founder: false }, permissions }
    else if (path === '/official-platform/connection') {
      if (connectionFailure) { status = 503; message = '连接状态暂时不可用' }
      else data = account()
    }
    else if (path === '/official-platform/capabilities') data = { capabilities: {}, entitlement_revision: 0, next_check_at: null, next_check_in_seconds: null }
    else if (path === '/official-platform/license') data = { edition: 'community', edition_name: '社区版', status: 'community', status_text: '无需商业授权', installation_id: 'test-instance', entitlement_revision: 0, entitlements: [], license_no: null, offline_until: null, verified_at: null, last_error: null, account: account() }
    else if (path === '/official-platform/license/activate') {
      assert.match(route.request().postDataJSON().activation_code, /^HS-(?:[A-F0-9]{8}-){5}[A-F0-9]{8}$/)
      activations++; connected = true
      data = { edition: 'premium', edition_name: '旗舰版', status: 'active', status_text: '授权有效', installation_id: account().installation_id, entitlement_revision: 1, entitlements: [], license_no: 'TEST', offline_until: null, verified_at: null, last_error: null, account: account() }
    }
    else if (path === '/system-updates/notice') data = { available: false, current_version: '0.1.0', latest_version: null, checked_at: null, last_attempt_at: null, last_error: null, force_update: false, changelog: { added: [], improved: [], fixed: [], notices: [] } }
    else if (path === '/system-updates') data = { current_version: '0.1.0', update: null, jobs: [] }
    else if (path === '/system-updates/check') { status = 503; message = '官网尚未提供所需的 v1 接口，请按接入要求完成配置' }
    else if (path === '/application-center/catalog' || path === '/application-center/checkout') {
      catalogRequests++; status = 404; message = '接口不存在'
    }
    else if (path === '/application-center/store-link') data = { store_url: 'https://official.example/applications' }
    else if (path === '/application-center/owned') data = { items: [
      { application_id: 1, name: '已安装供应插件', code: 'mock_provider', kind: 'plugin', use_expires_at: null, available_version_id: 101, available_version: '1.0.0', local_version: installedState === 'uninstalled' ? null : '1.0.0', local_state: installedState, local_revision: installedRevision, local_category: 'provider', unavailable_reason: '' },
      { application_id: 2, name: '新供应插件', code: 'new_provider', kind: 'plugin', use_expires_at: null, available_version_id: 102, available_version: '1.0.0', local_version: newInstalled ? '1.0.0' : null, local_state: newInstalled ? 'installed' : 'not_installed', local_revision: newInstalled ? 1 : 0, local_category: 'provider', unavailable_reason: '' },
    ], jobs: [] }
    else if (path === '/application-center/prepare') data = { application_code: 'new_provider', version_id: 102, version: '1.0.0', kind: 'plugin', permissions: [], sha256: 'a'.repeat(64), bytes: 1024, force_update: true, changelog: { added: ['新增供应平台支持'], improved: [], fixed: [], notices: [] } }
    else if (path === '/application-center/install') {
      installRequests++
      const body = route.request().postDataJSON()
      assert.equal(body.version_id, 102)
      assert.equal(body.action, 'install')
      assert.equal(body.sha256, 'a'.repeat(64))
      newInstalled = true
      data = { jobs: [] }
    }
    else if (path === '/application-center/uninstall') {
      uninstallRequests++
      assert.deepEqual(route.request().postDataJSON(), { code: 'mock_provider', revision: installedRevision })
      installedState = 'uninstalled'
      installedRevision++
      data = { state: installedState, revision: installedRevision }
    }
    else { status = 404; message = '接口未模拟' }
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ code: status === 200 ? 0 : 50300, message, data, request_id: 'test-request' }) })
  })
  await page.goto(`${base}/maintenance`)
  const entry = page.getByRole('button', { name: '授权码激活', exact: true })
  await entry.waitFor()
  const bindingStatus = page.getByRole('status', { name: '授权激活状态', exact: true })
  await bindingStatus.getByText('社区版', { exact: true }).waitFor()
  await page.getByText('授权信息', { exact: true }).waitFor()
  await page.getByText('版本信息', { exact: true }).waitFor()
  assert.equal(await page.getByRole('tab').count(), 0)
  assert.equal(await page.locator('.official-account-panel:visible').count(), 0)
  const heading = await page.locator('.system-maintenance-header').boundingBox()
  const entryBox = await entry.boundingBox()
  assert.ok(heading && entryBox && entryBox.x > heading.x + heading.width / 2 && entryBox.y < heading.y + 12, '账号入口应在页面右上角')
  await entry.click()
  assert.equal(await page.getByRole('dialog').count(), 1)
  await page.getByRole('button', { name: '激活授权', exact: true }).click()
  await page.getByText('请输入授权码', { exact: true }).waitFor()
  assert.equal(activations, 0, '前端校验失败不能提交')
  await page.getByPlaceholder('请输入软件授权码').fill('HS-' + Array(6).fill('AAAAAAAA').join('-'))
  await page.getByRole('button', { name: '激活授权', exact: true }).click()
  await bindingStatus.getByText('授权有效', { exact: true }).waitFor()
  assert.equal(activations, 1)
  assert.equal(await page.getByPlaceholder('请输入软件授权码').inputValue(), '')
  assert.equal(await page.getByText('生成确认码', { exact: true }).count(), 0)
  const shortValuesStayOnOneLine = async () => {
    assert.equal(await page.locator('.official-account-panel').evaluate(element => element.scrollWidth <= element.clientWidth), true)
  }
  await shortValuesStayOnOneLine()
  assert.equal(await page.getByText('接入地址', { exact: true }).count(), 0)
  await noOverflow(page)
  await page.screenshot({ path: `${output}/connection-auto-1440.png`, fullPage: true })
  await page.setViewportSize({ width: 390, height: 844 })
  await settledNarrowLayout(page)
  await shortValuesStayOnOneLine()
  await noOverflow(page)
  await page.screenshot({ path: `${output}/connection-auto-390.png`, fullPage: true })
  await page.locator('.official-account-panel').getByText('旗舰版', { exact: true }).waitFor()
  await page.getByRole('dialog').getByRole('button', { name: 'Close', exact: true }).click()
  await page.locator('.official-account-panel').waitFor({ state: 'hidden' })
  assert.equal(await page.locator('.official-account-panel:visible').count(), 0)
  for (const width of [390, 768]) {
    await page.setViewportSize({ width, height: 844 })
    await page.waitForFunction(() => {
      const header = document.querySelector('.system-maintenance-header')
      return header && getComputedStyle(header).flexDirection === 'column'
    })
    const narrowHeading = await page.locator('.system-maintenance-header').boundingBox()
    const narrowEntry = await entry.boundingBox()
    const narrowStatus = await bindingStatus.boundingBox()
    assert.ok(narrowHeading && narrowEntry && narrowStatus
      && Math.abs(narrowEntry.x - narrowHeading.x) < 2
      && narrowStatus.x > narrowEntry.x + narrowEntry.width
      && Math.abs(narrowStatus.y + narrowStatus.height / 2 - narrowEntry.y - narrowEntry.height / 2) < 2,
    '窄屏账号按钮应靠左，状态紧随按钮并垂直居中')
    await noOverflow(page)
    await page.screenshot({ path: `${output}/maintenance-heading-${width}.png`, fullPage: true })
  }
  await page.setViewportSize({ width: 390, height: 844 })
  await settledNarrowLayout(page)
  await page.getByRole('button', { name: '授权码激活', exact: true }).click()
  await page.locator('.official-account-panel').getByText('旗舰版', { exact: true }).waitFor()
  await noOverflow(page)
  check('右上角账号入口在单个弹窗中配置，连接后可重新打开，桌面及390px无溢出')
  await page.goto(`${base}/application-center`)
  await page.getByText('已安装供应插件', { exact: true }).waitFor()
  assert.equal(await page.locator('.official-account-panel').count(), 0)
  assert.equal(await page.getByRole('button', { name: '重新绑定', exact: true }).count(), 0)
  assert.equal(await page.getByRole('tab', { name: '应用商店', exact: true }).count(), 0)
  assert.equal(await page.getByRole('link', { name: '获取', exact: true }).count(), 0)
  const store = page.getByRole('link', { name: '官方应用商店', exact: true })
  await store.waitFor()
  assert.equal(await store.getAttribute('href'), 'https://official.example/applications')
  assert.equal(await store.getAttribute('target'), '_blank')
  assert.equal(catalogRequests, 0)
  await page.setViewportSize({ width: 1440, height: 900 })
  await page.waitForFunction(() => {
    const card = document.querySelector('.page-card')?.getBoundingClientRect()
    return card && card.x >= 200 && card.right <= innerWidth
  })
  await noOverflow(page)
  await page.screenshot({ path: `${output}/owned-applications-1440.png`, fullPage: true })
  const newRow = page.getByRole('row').filter({ has: page.getByText('新供应插件', { exact: true }) })
  await newRow.getByRole('link', { name: '安装', exact: true }).click()
  await page.getByRole('dialog').getByText('新增供应平台支持', { exact: true }).waitFor()
  assert.equal(await page.getByRole('dialog').getByText('优化改进', { exact: true }).count(), 0, '空日志分类应隐藏')
  await page.getByRole('dialog').getByRole('button', { name: '确定', exact: true }).click()
  await newRow.getByText('已安装', { exact: true }).waitFor()
  assert.equal(installRequests, 1)
  const installedRow = page.getByRole('row').filter({ has: page.getByText('已安装供应插件', { exact: true }) })
  await installedRow.getByRole('link', { name: '卸载', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: '确定', exact: true }).click()
  await installedRow.getByText('已卸载', { exact: true }).waitFor()
  await installedRow.getByRole('link', { name: '安装', exact: true }).waitFor()
  assert.equal(uninstallRequests, 1)
  check('仅加载已获取应用，官方商店使用超链接，模拟安装与卸载成功后刷新状态')
  await page.setViewportSize({ width: 390, height: 844 })
  await settledNarrowLayout(page)
  await noOverflow(page)
  await page.evaluate(() => document.querySelectorAll('.arco-table-content-scroll').forEach((element) => { element.scrollLeft = 0 }))
  await page.screenshot({ path: `${output}/owned-applications-390.png`, fullPage: true })
  permissions = ['admin.license.view', 'admin.updates.view', 'admin.app-center.view']
  await page.goto(`${base}/maintenance?tab=updates`)
  await page.getByText('版本信息', { exact: true }).waitFor()
  await page.getByRole('button', { name: '授权码激活', exact: true }).click()
  await page.locator('.official-account-panel').getByText('旗舰版', { exact: true }).waitFor()
  for (const name of ['检查更新', '立即更新', '激活授权', '同步授权']) {
    assert.equal(await page.getByRole('button', { name, exact: true }).count(), 0)
  }
  await page.goto(`${base}/application-center`)
  await page.getByText('已安装供应插件', { exact: true }).waitFor()
  assert.equal(await page.getByRole('link', { name: '安装', exact: true }).count(), 0)
  assert.equal(await page.getByRole('link', { name: '卸载', exact: true }).count(), 0)
  await page.getByRole('link', { name: '官方应用商店', exact: true }).waitFor()
  assert.equal(catalogRequests, 0)
  check('仅查看权限时隐藏连接、更新、安装与卸载操作，仍可访问官方商店')
  reconnectRequired = true
  await page.goto(`${base}/maintenance`)
  await bindingStatus.getByText('需要核查', { exact: true }).waitFor()
  await noOverflow(page)
  connectionFailure = true
  await page.reload()
  await bindingStatus.getByText('状态获取失败', { exact: true }).waitFor()
  assert.equal(await bindingStatus.getByText('社区版', { exact: true }).count(), 0)
  check('授权状态与读取失败分开展示')
  await context.close()
  assert.deepEqual(errors, [])
  console.log(JSON.stringify({ passed: passed.length, results: passed, browserErrors: errors.length }))
} finally { await browser.close() }
