import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = process.env.WORKPLACE_BROWSER_OUTPUT ?? '/tmp/howstack-workplace-ui'
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
const dashboardPermission = 'admin.page.dashboard.view'
const permissions = [dashboardPermission, 'admin.members.view', 'admin.page.order-list.view', 'admin.tickets.list.view',
  'admin.real-name-authentications.view', 'admin.finance.refunds.view', 'admin.finance.invoices.view', 'admin.finance.withdrawals.view',
  'admin.services.cloud-servers.view', 'admin.services.physical-servers.view', 'admin.services.server-colocations.view',
  'admin.finance.recharges.view', 'admin.provider-connections.view', 'admin.page.settings.view', 'admin.roles.view', 'admin.page.operation-logs.view', 'admin.profile.view']
const orders = Array.from({ length: 5 }, (_, i) => ({ id: i + 1, order_no: `ORDER-WORKPLACE-${i + 1}`, member_id: 1,
  member_mobile_snapshot: '13800000000', member_nickname_snapshot: '测试会员', order_type: 'purchase', status: 'fulfilling',
  payment_status: 'paid', currency: 'CNY', payable_minor: 12345, paid_minor: 12345, created_at: '2026-10-09 10:00:00' }))
const pageErrors = [], writes = []
async function setup(initialPermissions = permissions) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } }), page = await context.newPage()
  page.setDefaultTimeout(10000)
  const state = { permissions: initialPermissions, userId: 1, failure: false, malformed: false, delayed: false, release: null, reads: [] }
  page.on('pageerror', error => pageErrors.push(error.message))
  await context.route('**/api/admin/v1/**', async route => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/admin/v1', '')
    if (request.method() !== 'GET') writes.push(request.method() + ' ' + path)
    let data = { data: [], total: 0 }, status = 200, code = 0
    if (path === '/auth/me') data = { user: { id: state.userId, username: 'admin-test', nickname: '运营管理员', is_founder: false, last_login_at: '2026-10-09 09:30:00' }, permissions: state.permissions }
    else {
      if (['/members', '/orders', '/tickets', '/real-name-authentications', '/service-instances', '/finance/refunds', '/finance/invoices', '/finance/withdrawals'].includes(path)) state.reads.push({ path, params: Object.fromEntries(url.searchParams) })
      if (path === '/members') {
        if (state.delayed) await new Promise(resolve => { state.release = resolve })
        data.total = state.malformed ? -1 : 88
      }
      if (path === '/orders') data = { data: url.searchParams.get('status') ? [] : orders, total: url.searchParams.get('status') === 'fulfilling' ? 3 : 21 }
      if (path === '/tickets') {
        data.total = 4
        if (state.failure) { status = 500; code = 50000 }
      }
      if (path === '/real-name-authentications') data.total = 2
      if (path.startsWith('/finance/')) data.total = path === '/finance/refunds' ? 1 : 0
      if (path === '/service-instances') data.total = { cloud_server: 10, physical_server: 2, server_colocation: 1 }[url.searchParams.get('product_type')] ?? 0
    }
    await route.fulfill({ status, json: { code, message: code ? '加载失败' : '成功', data, request_id: 'workplace-browser' } })
  })
  const region = name => page.getByRole('region', { name, exact: true })
  async function ready() {
    await region('欢迎卡片').waitFor()
    await page.getByRole('button', { name: '刷新数据', exact: true }).waitFor()
    await page.waitForFunction(() => !document.querySelector('.workplace-page .arco-spin-loading, .workplace-page .arco-skeleton'))
  }
  return { context, page, state, region, ready }
}
try {
  const { context, page, state, region, ready } = await setup()
  await page.goto(`${base}/dashboard`)
  await ready()
  for (const name of ['业务摘要', '待办事项', '最近订单', '快捷操作', '产品服务']) await region(name).waitFor()
  assert.equal(await region('常用管理').count(), 0)
  assert.equal(await region('待办事项').getByRole('link').count(), 6)
  assert.equal(await region('产品服务').getByRole('link').count(), 3)
  assert.equal(await region('产品服务').getByText('已开通实例', { exact: true }).count(), 1)
  assert.equal(await region('快捷操作').getByRole('button', { name: '系统设置', exact: true }).count(), 0)
  await region('快捷操作').getByRole('button', { name: '查看更多', exact: true }).click()
  await region('快捷操作').getByRole('button', { name: '系统设置', exact: true }).waitFor()
  await region('快捷操作').getByRole('button', { name: '收起', exact: true }).click()
  assert.equal(await region('最近使用').count(), 0)
  await region('最近订单').getByText('ORDER-WORKPLACE-5', { exact: true }).waitFor()
  assert.equal(await region('最近订单').getByText('¥123.45 元', { exact: true }).count(), 5)
  assert.equal(await page.getByText(/演示|成功率|今日销售|销售排行/).count(), 0)
  assert.equal(state.reads.length, 11, '全部有权限时应只读取11个数据源')
  assert(state.reads.every(read => read.params.page === '1' && ['1', '5'].includes(read.params.page_size)))
  assert(state.reads.some(read => read.path === '/orders' && read.params.sort_order === 'desc' && read.params.sort_by === 'created_at' && read.params.page_size === '5'))
  assert(state.reads.some(read => read.path === '/tickets' && read.params.status === 'open'))
  assert(state.reads.some(read => read.path === '/real-name-authentications' && read.params.status === 'pending'))
  assert(state.reads.filter(read => read.path.startsWith('/finance/')).every(read => read.params.status === 'pending_review'))
  assert(state.reads.filter(read => read.path === '/service-instances').every(read => read.params.status === 'active'))
  const invoice = region('待办事项').locator('.workplace-task').filter({ hasText: '发票审核' })
  assert.match(await invoice.innerText(), /\n0\n/, '有效零值应显示0')
  for (const width of [1440, 1024, 390, 320]) {
    await page.setViewportSize({ width, height: 1000 })
    await page.waitForFunction(() => document.querySelector('.workplace-page').getBoundingClientRect().width >= (innerWidth <= 768 ? innerWidth - 48 : innerWidth - 270))
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${width}px 页面不能横向溢出`)
    await page.evaluate(() => window.scrollTo(0, 0))
    await page.screenshot({ path: `${output}/workplace-${width}.png`, fullPage: true })
    if ([1440, 390].includes(width)) await region('快捷操作').screenshot({ path: `${output}/shortcuts-${width}.png` })
    if (width === 390) {
      await page.getByRole('button', { name: '打开导航菜单', exact: true }).click()
      await page.getByRole('navigation', { name: '后台导航', exact: true }).getByText('工作台', { exact: true }).waitFor()
      await page.keyboard.press('Escape')
    }
  }
  await page.setViewportSize({ width: 1440, height: 1000 })
  // 使用真实配置入口切换，避免依赖存储枚举值。
  await page.getByRole('button', { name: '页面配置', exact: true }).click()
  await page.getByText('双列布局', { exact: true }).click()
  await page.mouse.click(10, 120)
  await page.getByText('双列布局', { exact: true }).waitFor({ state: 'hidden' })
  await page.getByRole('navigation', { name: '一级导航', exact: true }).waitFor()
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
  await page.screenshot({ path: `${output}/workplace-double-1440.png`, fullPage: true })
  await page.evaluate(() => document.body.setAttribute('arco-theme', 'dark'))
  await page.screenshot({ path: `${output}/workplace-dark-1440.png`, fullPage: true })
  await page.evaluate(() => document.body.removeAttribute('arco-theme'))
  state.failure = true
  await page.getByRole('button', { name: '刷新数据', exact: true }).click()
  await page.getByText('部分业务数据加载失败', { exact: true }).waitFor()
  await ready()
  const ticketMetric = region('业务摘要').locator('.workplace-card').filter({ hasText: '待处理工单' })
  assert.match(await ticketMetric.innerText(), /--/)
  const failedTask = region('待办事项').getByRole('link', { name: '查看待处理工单', exact: true })
  assert.match(await failedTask.innerText(), /--/)
  assert.match(await failedTask.innerText(), /加载失败/)
  assert.match(await region('业务摘要').locator('.workplace-card').filter({ hasText: '会员总数' }).innerText(), /88/)
  state.failure = false; state.malformed = true
  await page.getByRole('button', { name: '刷新数据', exact: true }).click(); await ready()
  assert.match(await region('业务摘要').locator('.workplace-card').filter({ hasText: '会员总数' }).innerText(), /--/)
  state.malformed = false
  await page.getByRole('button', { name: '刷新数据', exact: true }).click(); await ready()
  assert.equal(await page.getByText('部分业务数据加载失败', { exact: true }).count(), 0)
  const refundLink = region('待办事项').getByRole('link', { name: '查看退款审核', exact: true })
  assert.equal(new URL(await refundLink.getAttribute('href'), base).searchParams.get('status'), 'pending_review')
  await refundLink.focus(); await refundLink.press('Enter')
  await page.waitForURL('**/admin/refunds?status=pending_review')
  assert.equal(await page.getByRole('button', { name: '申请退款', exact: true }).count(), 0, '只查看权限不能创建单据')
  await page.goBack(); await ready()
  await region('产品服务').getByRole('link', { name: '查看物理服务器', exact: true }).click()
  await page.waitForURL('**/admin/services/physical-servers?status=active')
  await page.goBack(); await ready()
  await region('快捷操作').getByRole('button', { name: '会员管理', exact: true }).click()
  await page.waitForURL('**/admin/members')
  await page.goBack(); await ready()
  assert.equal(await region('最近使用').count(), 0)
  state.permissions = [dashboardPermission]
  await page.evaluate(() => window.dispatchEvent(new Event('admin:access-changed')))
  await page.getByText('暂无可用的快捷操作', { exact: true }).waitFor()
  assert.equal(await region('快捷操作').getByRole('button', { name: '会员管理', exact: true }).count(), 0)
  await context.close()
  console.log('通过：真实接口查询口径、零值、金额、待办筛选跳转、只读入口、部分失败和刷新恢复、1440/1024/390/320px及双列/深色布局')

  const restricted = await setup([dashboardPermission])
  await restricted.page.goto(`${base}/dashboard`); await restricted.ready()
  assert.equal(restricted.state.reads.length, 0)
  assert.equal(await restricted.region('最近订单').count(), 0)
  await restricted.page.getByText('暂无可查看的业务摘要', { exact: true }).waitFor()
  await restricted.context.close()
  const race = await setup([dashboardPermission, 'admin.members.view'])
  race.state.delayed = true
  await race.page.goto(`${base}/dashboard`)
  await race.region('欢迎卡片').waitFor()
  await race.page.waitForFunction(() => Boolean(document.querySelector('.workplace-metric-header')))
  assert(race.state.release, '待验证请求已发出')
  race.state.permissions = [dashboardPermission]
  await race.page.evaluate(() => window.dispatchEvent(new Event('admin:access-changed')))
  await race.page.getByText('暂无可查看的业务摘要', { exact: true }).waitFor()
  race.state.release()
  // 等待延迟接口完成，验证旧响应不能恢复已撤销权限的数据。
  await race.page.waitForTimeout(300)
  assert.equal(await race.region('业务摘要').getByText('会员总数', { exact: true }).count(), 0)
  await race.context.close()
  const denied = await setup([])
  await denied.page.goto(`${base}/dashboard`)
  await denied.page.getByText('对不起，您没有访问该资源的权限', { exact: true }).waitFor()
  assert.equal(await denied.region('欢迎卡片').count(), 0)
  await denied.context.close()
  assert.deepEqual(pageErrors, []); assert.deepEqual(writes, [])
  console.log('通过：无权限不请求业务接口、页面拒绝访问、撤销权限后延迟响应不可见；无写请求、无浏览器运行错误')
} finally { await browser.close() }
