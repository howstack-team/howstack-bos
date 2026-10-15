import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = '/tmp/howstack-update-browser'
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
const passed = []
const check = (name) => { passed.push(name); console.log(`通过：${name}`) }
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } })
  const page = await context.newPage()
  const errors = []
  page.on('pageerror', (error) => errors.push(error.message))
  let latest = '0.2.0'
  let available = true
  let failed = false
  let forceUpdate = false
  let starts = 0
  let noticeReads = 0
  let editionName = '官网社区体验版'
  let permissions = ['admin.license.view', 'admin.updates.view', 'admin.updates.check', 'admin.updates.execute']
  const requests = []
  page.on('request', (request) => requests.push(request.url()))
  const update = () => ({ available, current_version: '0.1.0', target_version: latest, checked_at: '2026-10-04 12:00:00', force_update: forceUpdate,
    changelog: { added: ['新增版本提醒'], improved: ['优化云服务器配置'], fixed: ['修复更新提醒'], notices: ['请备份数据', '<img src=x onerror="alert(1)">', '长文本'.repeat(60)] },
    sha256: 'a'.repeat(64), bytes: 2048, expires_at: 1 })
  const state = () => ({ current_version: '0.1.0', update: update(), jobs: [], last_attempt_at: failed ? '2026-10-04 13:00:00' : '2026-10-04 12:00:00', last_error: failed ? '官网请求未完成，请稍后重试或查看接入状态' : null })
  await context.route('**/api/admin/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/admin/v1', '')
    let status = 200
    let data
    if (path === '/auth/me') data = { user: { id: 1, username: 'mock-admin', nickname: '模拟管理员', is_founder: false }, permissions }
    else if (path === '/official-platform/connection') data = { configured: true, connected: true, reconnect_required: false, connection: null, pairing: null }
    else if (path === '/official-platform/capabilities') data = { capabilities: {}, entitlement_revision: 0, next_check_at: null, next_check_in_seconds: null }
    else if (path === '/official-platform/license') data = { edition: 'community', edition_name: editionName, status: 'community', status_text: '无需商业授权', installation_id: 'test-instance', entitlement_revision: 0, entitlements: [], license_no: null, offline_until: null, verified_at: null, last_error: null, account: { connected: true, configured: true, origin: 'https://official.example', software_code: 'howstack-bos', installation_id: 'test-instance', reconnect_required: false, checked_at: null, pairing: null, connection: null } }
    else if (path === '/system-updates/notice') {
      noticeReads++
      data = { available, current_version: '0.1.0', latest_version: available ? latest : null, force_update: forceUpdate, changelog: update().changelog, checked_at: update().checked_at, last_attempt_at: state().last_attempt_at, last_error: state().last_error }
    }
    else if (path === '/messages/summary') data = { unread: 0, categories: {}, topics: {} }
    else if (path === '/messages') data = { data: [], total: 0, page: 1, page_size: 10 }
    else if (path === '/orders' || path === '/real-name-authentications') data = { data: [], total: 0, page: 1, page_size: 10 }
    else if (path === '/system-updates') data = state()
    else if (path === '/system-updates/check') { failed = true; status = 503 }
    else if (path === '/system-updates/start') {
      starts++
      const body = route.request().postDataJSON()
      assert.match(body.request_id, /^[a-f0-9-]{36}$/)
      assert.equal(body.sha256, 'a'.repeat(64))
      assert.equal(body.target_version, latest)
      data = state()
    }
    else { status = 404 }
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ code: status === 200 ? 0 : 50300, message: status === 200 ? '成功' : '官网请求未完成，请稍后重试或查看接入状态', data, request_id: 'test-update' }) })
  })
  await page.goto(`${base}/maintenance?notificationMock=0`)
  const updateMenu = page.locator('.arco-menu-item').filter({ hasText: '授权与更新' })
  await page.getByText('发现新版本 v0.2.0', { exact: true }).waitFor()
  await updateMenu.locator('.arco-badge-dot').waitFor({ state: 'visible' })
  assert.equal(noticeReads, 1, '菜单徽标和顶部提醒复用同一次缓存查询')
  const notifications = page.getByRole('button', { name: '通知', exact: true })
  assert.equal(await page.locator('.arco-icon-language').count(), 0, '右上角不展示语言切换按钮')
  await notifications.click()
  await selectSystemTopic()
  async function selectSystemTopic() {
    if (page.url().includes('notificationMock=1')) return
    const category = page.getByRole('complementary', { name: '消息分类', exact: true })
    if (await category.isVisible()) {
      await category.getByText('系统消息', { exact: true }).click()
    } else {
      await page.locator('.notification-center-mobile-filters').getByLabel('消息分类', { exact: true }).click()
      await page.getByRole('option', { name: /系统消息/ }).click()
    }
  }
  const notificationList = page.getByRole('region', { name: '通知列表', exact: true })
  await notificationList.getByText('发现新版本 v0.2.0', { exact: true }).click()
  assert.equal(noticeReads, 1, '通知面板复用相同摘要，不重复请求')
  await notificationList.getByRole('link', { name: '查看详情', exact: true }).click()
  await notificationList.waitFor({ state: 'hidden' })
  check('通知入口展示真实版本提醒并跳转，隐藏语言按钮且不重复查询')
  await page.getByRole('link', { name: '查看更新', exact: true }).click()
  await page.waitForURL(`${base}/maintenance`)
  await page.evaluate(() => history.replaceState(null, '', '?notificationMock=0'))
  await page.getByText('授权信息', { exact: true }).waitFor()
  await page.getByText('官网社区体验版', { exact: true }).waitFor()
  assert.equal(await page.getByRole('tab').count(), 0, '授权与版本信息在同一页面展示')
  await page.getByText('更新日志', { exact: true }).waitFor()
  for (const name of ['新增功能', '优化改进', '问题修复', '升级注意事项', '新增版本提醒', '请备份数据']) {
    await page.getByText(name, { exact: true }).waitFor()
  }
  assert.equal(await page.locator('.release-changelog-items img').count(), 0, '更新说明只能渲染文本')
  await page.getByText('升级记录', { exact: true }).waitFor()
  await page.getByText('有可用更新', { exact: true }).waitFor()
  assert.equal(await page.getByRole('button', { name: '立即更新', exact: true }).isDisabled(), false)
  check('顶部提醒跳转更新页，日志可见，旧清单过期仍允许重新检查后更新')
  await page.getByRole('link', { name: '稍后提醒', exact: true }).click()
  await page.getByText('发现新版本 v0.2.0', { exact: true }).waitFor({ state: 'hidden' })
  assert.equal(await updateMenu.locator('.arco-badge-dot').count(), 1, '稍后提醒不清除可用更新的菜单徽标')
  const until = await page.evaluate(() => Number(localStorage.getItem('howstack-update-reminder:1:0.1.0:0.2.0')))
  assert.ok(until > Date.now() + 23 * 3600000 && until <= Date.now() + 24 * 3600000)
  await page.reload()
  await page.getByText('有可用更新', { exact: true }).waitFor()
  assert.equal(await page.getByRole('link', { name: '稍后提醒', exact: true }).count(), 0)
  check('稍后提醒保存二十四小时，刷新后保持隐藏')
  await page.evaluate(() => localStorage.setItem('howstack-update-reminder:1:0.1.0:0.2.0', '1'))
  await page.reload()
  await page.getByText('发现新版本 v0.2.0', { exact: true }).waitFor()
  await page.getByRole('link', { name: '稍后提醒', exact: true }).click()
  latest = '0.3.0'
  await page.reload()
  await page.getByText('发现新版本 v0.3.0', { exact: true }).waitFor()
  check('提醒到期后恢复，新版本不受旧版本稍后提醒影响')
  await page.getByRole('link', { name: '稍后提醒', exact: true }).click()
  forceUpdate = true
  await page.reload()
  await page.getByText('此版本要求更新', { exact: true }).waitFor()
  await page.getByText('强制更新', { exact: true }).waitFor()
  assert.equal(await page.getByRole('link', { name: '稍后提醒', exact: true }).count(), 0)
  assert.equal(starts, 0, '强制更新不能自动提交安装')
  check('强制更新覆盖已有稍后提醒偏好，持续提示且不会自动安装')
  await page.getByRole('button', { name: '检查更新', exact: true }).click()
  await page.getByText('检查失败，保留上次结果', { exact: true }).waitFor()
  await page.getByText('2026-10-04 13:00:00', { exact: true }).waitFor()
  assert.equal(await page.getByText('当前版本已最新', { exact: true }).count(), 0)
  await page.getByText('最新版本', { exact: true }).waitFor()
  check('手动检查失败保留版本及日志，展示成功和失败时间，不误报已最新')
  await page.getByRole('button', { name: '立即更新', exact: true }).click()
  await page.getByRole('dialog').waitFor()
  await page.getByRole('dialog').getByText('请先在版本信息下方阅读更新日志，确认后开始升级。', { exact: true }).waitFor()
  assert.equal(await page.getByRole('dialog').locator('.release-changelog-items').count(), 0, '确认弹窗不重复塞入长更新日志')
  assert(await page.locator('.system-maintenance-section').getByText('新增版本提醒', { exact: true }).isVisible(), '完整更新日志仍保留在页面')
  await page.locator('.arco-modal-wrapper').click({ position: { x: 8, y: 8 } })
  await page.getByRole('dialog').waitFor({ state: 'hidden' })
  assert.equal(starts, 0)
  await page.getByRole('button', { name: '立即更新', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: '确定', exact: true }).click()
  await page.getByText('更新任务已受理', { exact: true }).waitFor()
  assert.equal(starts, 1)
  await page.getByRole('dialog').waitFor({ state: 'hidden' })
  check('遮罩可取消更新，确认才提交目标版本、包摘要及幂等编号')
  await page.screenshot({ path: `${output}/updates-1440.png`, fullPage: true })
  await page.setViewportSize({ width: 390, height: 844 })
  await page.waitForFunction(() => {
    const card = document.querySelector('.page-card')?.getBoundingClientRect()
    return card && card.x <= 24 && card.width >= innerWidth - 48
  })
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
  await page.screenshot({ path: `${output}/updates-390.png`, fullPage: true })
  await notifications.click()
  await selectSystemTopic()
  await notificationList.getByText('发现新版本 v0.3.0', { exact: true }).click()
  await notificationList.getByText('此版本要求更新', { exact: true }).waitFor()
  await page.waitForTimeout(300)
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
  await page.screenshot({ path: `${output}/notifications-390.png` })
  await page.locator('.arco-modal-wrapper').click({ position: { x: 8, y: 8 } })
  await notificationList.waitFor({ state: 'hidden' })
  await page.getByRole('button', { name: '打开导航菜单', exact: true }).click()
  await updateMenu.locator('.arco-badge-dot').waitFor({ state: 'visible' })
  await page.waitForFunction(() => {
    const sider = document.querySelector('.arco-layout-sider')?.getBoundingClientRect()
    return sider && Math.abs(sider.x) < 1 && sider.width === 220
  })
  await page.screenshot({ path: `${output}/update-badge-menu-390.png`, fullPage: true })
  await page.locator('button[aria-label="关闭导航菜单"][aria-expanded="true"]').click()
  check('390px提醒、日志与更新页面无横向溢出')
  permissions = ['admin.license.view', 'admin.updates.view']
  await page.reload()
  await page.getByText('更新日志', { exact: true }).waitFor()
  await page.getByText('发现新版本 v0.3.0', { exact: true }).waitFor()
  for (const name of ['检查更新', '立即更新']) assert.equal(await page.getByRole('button', { name, exact: true }).count(), 0)
  available = false
  failed = false
  forceUpdate = false
  await page.reload()
  await page.getByText('当前版本已最新', { exact: true }).waitFor()
  await notifications.click()
  await selectSystemTopic()
  await notificationList.getByText('暂无通知', { exact: true }).waitFor()
  await page.locator('.arco-modal-wrapper').click({ position: { x: 8, y: 8 } })
  await notificationList.waitFor({ state: 'hidden' })
  assert.equal(await page.getByText('更新日志', { exact: true }).count(), 0)
  assert.equal(await page.getByText('强制更新', { exact: true }).count(), 0)
  await page.evaluate(() => window.dispatchEvent(new Event('admin:updates-changed')))
  await updateMenu.locator('.arco-badge-dot').waitFor({ state: 'hidden' })
  assert.equal(await page.getByRole('link', { name: '查看更新', exact: true }).count(), 0)
  available = true
  await page.evaluate(() => localStorage.removeItem('howstack-update-reminder:1:0.1.0:0.3.0'))
  await page.evaluate(() => window.dispatchEvent(new Event('admin:updates-changed')))
  await page.getByText('发现新版本 v0.3.0', { exact: true }).waitFor()
  assert.equal(await updateMenu.locator('.arco-badge-dot').count(), 1)
  check('桌面与390px导航展示更新徽标，无可用更新时同步清除徽标和顶部提醒')
  permissions = ['admin.license.view']
  const reads = noticeReads
  await page.goto(`${base}/maintenance?notificationMock=0`)
  await page.getByText('授权信息', { exact: true }).waitFor()
  assert.equal(noticeReads, reads)
  assert.equal(await page.getByText('版本信息', { exact: true }).count(), 0)
  assert.equal(await page.getByRole('link', { name: '查看更新', exact: true }).count(), 0)
  assert.equal(await updateMenu.locator('.arco-badge-dot').count(), 0)
  check('仅查看权限隐藏执行操作，无更新查看权限不请求提醒接口')
  for (const missingName of [undefined, '', '   ']) {
    editionName = missingName
    const response = page.waitForResponse((value) => value.url().endsWith('/official-platform/license'))
    await page.reload()
    await response
    await page.getByText('授权权益响应格式无效', { exact: true }).waitFor()
    await page.getByText('读取失败', { exact: true }).waitFor()
  }
  check('缺失或空白版本名称拒绝响应，不把异常误报为社区版')
  permissions = ['admin.license.view', 'admin.updates.view', 'admin.updates.check', 'admin.updates.execute']
  permissions.push('admin.page.order-list.view', 'admin.real-name-authentications.view')
  forceUpdate = true
  editionName = '官网社区体验版'
  await page.setViewportSize({ width: 1440, height: 900 })
  await page.goto(`${base}/maintenance?notificationMock=1`)
  await page.getByText('此版本要求更新', { exact: true }).waitFor()
  await page.locator('.notification-center-trigger-badge[aria-label="17条未读消息"] .arco-badge-number').waitFor({ state: 'visible' })
  assert.equal(await notifications.getAttribute('aria-expanded'), 'false', '消息中心未打开时已显示未读徽标')
  await notifications.click()
  await selectSystemTopic()
  const center = page.getByRole('region', { name: '消息中心', exact: true })
  await page.getByText('含演示数据', { exact: true }).waitFor()
  const firstOrder = notificationList.getByText('新订单：DEMO202610050001，金额：¥299.00', { exact: true })
  await firstOrder.waitFor()
  await page.waitForTimeout(400)
  await page.screenshot({ path: `${output}/message-center-1440.png` })
  assert.equal(await notificationList.locator('.notification-center-message-header').count(), 10)
  assert.equal(await notificationList.locator('.is-unread').count(), 6)
  assert.equal(await notificationList.getByRole('link', { name: '查看详情', exact: true }).count(), 0, '折叠标题不含详情链接')
  await firstOrder.click()
  await notificationList.getByText(/演示会员 1 购买了/).waitFor()
  assert.equal(await notificationList.locator('.is-unread').count(), 5)
  const orderDetail = notificationList.getByRole('link', { name: '查看详情', exact: true })
  assert.equal(await orderDetail.getAttribute('href'), '/admin/orders')
  await orderDetail.click()
  await page.waitForURL(`${base}/orders`)
  await center.waitFor({ state: 'hidden' })
  await page.goto(`${base}/maintenance?notificationMock=1`)
  await notifications.click()
  await selectSystemTopic()
  await firstOrder.waitFor()
  await center.locator('.arco-pagination-item-next').click()
  await notificationList.getByText('新订单：DEMO202610050011，金额：¥599.00', { exact: true }).waitFor()
  assert.equal(await notificationList.locator('.notification-center-message-header').count(), 6)
  await center.locator('.notification-center-categories .arco-menu-item').filter({ hasText: '财务消息' }).click()
  await notificationList.getByText('会员充值到账：¥500.00', { exact: true }).waitFor()
  await center.locator('.notification-center-topics .arco-menu-item').filter({ hasText: '退款申请' }).click()
  await notificationList.getByText('新增退款申请：¥89.00', { exact: true }).waitFor()
  await center.getByRole('button', { name: '全部已读', exact: true }).click()
  assert.equal(await center.locator('.is-unread').count(), 0)
  assert.equal(await center.getByRole('button', { name: '全部已读', exact: true }).isDisabled(), true)
  assert.equal(await page.getByText('此版本要求更新', { exact: true }).isVisible(), true, '全部已读不关闭强制更新提醒')
  await page.waitForTimeout(300)
  await page.locator('.arco-modal-wrapper').click({ position: { x: 8, y: 8 } })
  await center.waitFor({ state: 'hidden' })
  check('消息中心演示分类、子分类、展开已读、10条分页和全部已读正常，保持强制更新提醒')
  await page.setViewportSize({ width: 390, height: 844 })
  await page.reload()
  await notifications.click()
  await selectSystemTopic()
  await firstOrder.waitFor()
  await page.waitForFunction(() => {
    const node = document.querySelector('.notification-center')
    return node && Math.abs(node.getBoundingClientRect().width - node.clientWidth) < 0.1
  })
  assert.ok(await page.evaluate(() => {
    const node = document.querySelector('.notification-center')
    const box = node.getBoundingClientRect()
    return node.scrollWidth <= node.clientWidth && Math.round(box.x) >= 16 && Math.round(box.right) <= innerWidth - 16 && Math.round(box.bottom) <= innerHeight - 16
  }), '390px消息中心不超出视口，无横向溢出')
  const mobile = center.locator('.notification-center-mobile-filters')
  await page.screenshot({ path: `${output}/message-center-orders-390.png` })
  await mobile.locator('.arco-select').first().click()
  await page.locator('.arco-select-option').filter({ hasText: '服务消息' }).click()
  await notificationList.getByText('您有一条待回复工单', { exact: true }).waitFor()
  await mobile.locator('.arco-select').nth(1).click()
  await page.locator('.arco-select-option').filter({ hasText: '实名认证' }).click()
  await notificationList.getByText('新增企业实名认证申请', { exact: true }).click()
  await notificationList.getByText('演示企业会员已提交认证资料，等待审核。', { exact: true }).waitFor()
  await page.waitForTimeout(400)
  await page.screenshot({ path: `${output}/message-center-390.png` })
  const authenticationDetail = notificationList.getByRole('link', { name: '查看详情', exact: true })
  assert.equal(await authenticationDetail.getAttribute('href'), '/admin/real-name-authentications')
  await authenticationDetail.click()
  await page.waitForURL(`${base}/real-name-authentications`)
  await center.waitFor({ state: 'hidden' })
  check('桌面和390px展开内容内的详情链接跳转对应页面，未打开消息中心就显示未读徽标')
  permissions = ['admin.license.view', 'admin.updates.view']
  await page.goto(`${base}/maintenance?notificationMock=1`)
  await notifications.click()
  await selectSystemTopic()
  await firstOrder.click()
  await notificationList.getByText(/演示会员 1 购买了/).waitFor()
  assert.equal(await notificationList.getByRole('link', { name: '查看详情', exact: true }).count(), 0)
  check('没有目标页面权限时不展示消息详情跳转入口')
  assert.deepEqual(errors, [])
  assert.ok(requests.every((url) => new URL(url).origin === new URL(base).origin), '浏览器只能访问本机，不能请求官网凭证或检查接口')
  console.log(JSON.stringify({ passed: passed.length, results: passed, browserErrors: errors.length, externalRequests: 0 }))
  await context.close()
} finally { await browser.close() }
