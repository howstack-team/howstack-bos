import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
import { analysisToday } from '../src/utils/businessAnalysis.ts'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = process.env.ANALYSIS_BROWSER_OUTPUT ?? '/tmp/howstack-business-analysis-ui'
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
const analysisPermission = 'admin.page.analysis.view', today = analysisToday()
const addDays = (day, offset) => new Date(Date.parse(`${day}T00:00:00Z`) + offset * 86400000).toISOString().slice(0, 10)
const summary = { paid_amount_minor: 15000, refund_amount_minor: 20000, net_paid_amount_minor: -5000, average_paid_order_minor: 7500,
  created_orders: 3, paid_orders: 2, paid_members: 1, new_members: 5 }
const previous = { ...summary, paid_amount_minor: 10000, refund_amount_minor: 0, net_paid_amount_minor: 10000, average_paid_order_minor: 5000 }
function fixture(params, empty = false) {
  const end = params.end_date ?? today, start = params.start_date ?? addDays(end, -29), granularity = params.granularity ?? 'day'
  const days = (Date.parse(end) - Date.parse(start)) / 86400000 + 1
  const dates = [...new Set(Array.from({ length: days }, (_, i) => addDays(start, i).slice(0, granularity === 'month' ? 7 : 10)))]
  const zero = Object.fromEntries(Object.keys(summary).map(key => [key, 0]))
  return { period: { start_date: start, end_date: end, previous_start_date: addDays(start, -days), previous_end_date: addDays(start, -1),
    granularity, timezone: 'Asia/Shanghai', currency: 'CNY', generated_at: `${today} 10:30:00` },
  summary: empty ? zero : { ...summary }, previous_summary: empty ? zero : { ...previous },
  trend: dates.map((date, i) => ({ date, ...Object.fromEntries(['created_orders', 'paid_orders', 'paid_amount_minor', 'refund_amount_minor', 'net_paid_amount_minor', 'new_members']
    .map(key => [key, !empty && i === dates.length - 1 ? summary[key] : 0])) })),
  order_types: empty ? [] : [{ order_type: 'purchase', created_orders: 2, paid_orders: 2, paid_amount_minor: 15000 }, { order_type: 'trial', created_orders: 1, paid_orders: 0, paid_amount_minor: 0 }],
  order_statuses: empty ? [] : [{ status: 'cancelled', count: 1 }, { status: 'completed', count: 2 }] }
}
const errors = [], writes = []
async function setup(permissions = [analysisPermission]) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } }), page = await context.newPage()
  page.setDefaultTimeout(15000)
  const state = { permissions, status: 200, empty: false, malformed: false, delay: false, release: null, queries: [], otherReads: [] }
  page.on('pageerror', error => errors.push(error.message))
  await context.route('**/api/admin/v1/**', async route => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/admin/v1', '')
    if (request.method() !== 'GET') writes.push(`${request.method()} ${path}`)
    let data = null, status = 200, code = 0, message = '成功'
    if (path === '/auth/me') data = { user: { id: 1, username: 'analysis-test', nickname: '经营分析管理员', is_founder: false, last_login_at: null }, permissions: state.permissions }
    else if (path === '/business-analysis') {
      const params = Object.fromEntries(url.searchParams); state.queries.push(params)
      status = state.status; code = status === 200 ? 0 : status * 100; message = status === 200 ? '成功' : '查询失败，请重试'
      data = fixture(params, state.empty)
      if (state.malformed) data.summary.paid_amount_minor = 100.5
      if (status === 422) data = { errors: { start_date: ['开始日期不在允许范围'] } }
      if (status === 403) state.permissions = []
      if (state.delay) await new Promise(resolve => { state.release = resolve })
    } else {
      state.otherReads.push(path)
      if (path === '/roles') data = [{ id: 1, name: '分析角色', code: 'analyst', status: 1, is_system: false, sort_order: 0, description: '', data_scope: 'self', department_ids: [], permissions: [] }]
      if (path === '/roles/1') data = { id: 1, name: '分析角色', code: 'analyst', status: 1, is_system: false, sort_order: 0, description: '', data_scope: 'self', department_ids: [], permissions: [] }
      if (path === '/permissions') data = { [analysisPermission]: { label: '查看经营分析', group: '总览' } }
    }
    await route.fulfill({ status, json: { code, message, data, request_id: 'analysis-browser' } })
  })
  const region = name => page.getByRole('region', { name, exact: true })
  async function ready() { await region('经营指标').waitFor().catch(async error => { console.log('页面诊断', page.url(), state.queries, await page.locator('body').innerText()); await page.screenshot({ path: `${output}/failure.png`, fullPage: true }); throw error }); await page.waitForFunction(() => document.querySelectorAll('.analysis-chart[data-rendered="true"]').length > 0) }
  return { context, page, state, region, ready }
}
try {
  const { context, page, state, region, ready } = await setup()
  await page.goto(`${base}/analysis`); await ready()
  await page.getByRole('navigation', { name: '后台导航', exact: true }).getByText('经营分析', { exact: true }).waitFor()
  assert.equal(state.queries.length, 1)
  assert.deepEqual(state.queries[0], { granularity: 'day' })
  assert(state.otherReads.every(path => !['/members', '/orders', '/finance/refunds', '/tickets'].includes(path)), '独立分析权限不能请求业务明细')
  const metric = name => region('经营指标').locator('.analysis-card').filter({ hasText: name })
  assert.match(await metric('订单实付').innerText(), /150\.00/); assert.doesNotMatch(await metric('订单实付').innerText(), /150\.00\.00/)
  assert.match(await metric('已完成退款').innerText(), /200\.00/)
  assert.match(await metric('净实付').innerText(), /-50\.00/)
  assert.match(await metric('已完成退款').innerText(), /暂无对比/)
  assert.match(await metric('付费会员').innerText(), /\n1\n/)
  assert.equal(await page.getByText(/演示|今日销售|转化率|利润预估/).count(), 0)
  await page.getByText('统计范围包含今天，当天数据尚未完整。', { exact: true }).waitFor()
  await region('实付与退款趋势').getByText('查看趋势数据', { exact: true }).click()
  await region('实付与退款趋势').getByRole('columnheader', { name: '日期', exact: true }).waitFor()
  assert.equal(await region('实付与退款趋势').locator('tbody tr').count(), 10)
  await region('实付与退款趋势').getByText('查看趋势数据', { exact: true }).click()
  for (const width of [1440, 1024, 390, 320]) {
    await page.setViewportSize({ width, height: 1000 })
    await page.waitForFunction(() => document.querySelector('.analysis-page').getBoundingClientRect().width >= (innerWidth <= 768 ? innerWidth - 48 : innerWidth - 270))
    await page.waitForTimeout(150)
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${width}px 页面溢出`)
    if (width <= 390) {
      await page.getByPlaceholder('开始日期', { exact: true }).click()
      const panel = page.locator('.arco-picker-container:visible')
      await panel.waitFor()
      const bounds = await panel.boundingBox()
      assert(bounds.x >= 0 && bounds.x + bounds.width <= width + 1, `${width}px 日历弹层溢出`)
      await page.mouse.click(width - 10, 130)
      await panel.waitFor({ state: 'hidden' })
    }
    await page.evaluate(() => window.scrollTo(0, 0))
    await page.screenshot({ path: `${output}/analysis-${width}.png`, fullPage: true })
  }
  await page.setViewportSize({ width: 1440, height: 1000 })
  await page.getByRole('button', { name: '页面配置', exact: true }).click()
  await page.getByText('双列布局', { exact: true }).click(); await page.mouse.click(10, 120)
  await page.getByText('双列布局', { exact: true }).waitFor({ state: 'hidden' })
  await page.getByRole('navigation', { name: '二级导航', exact: true }).getByText('经营分析', { exact: true }).waitFor()
  await page.screenshot({ path: `${output}/analysis-double-1440.png`, fullPage: true })
  const themeButton = page.locator('button').filter({ has: page.locator('svg.arco-icon-moon-fill') })
  await themeButton.click()
  await page.waitForFunction(() => document.body.getAttribute('arco-theme') === 'dark')
  await page.waitForFunction(() => [...document.querySelectorAll('.analysis-chart')].every(element => element.dataset.rendered === 'true'))
  await page.screenshot({ path: `${output}/analysis-dark-1440.png`, fullPage: true })
  await page.locator('button').filter({ has: page.locator('svg.arco-icon-sun-fill') }).click()
  const query = page.getByRole('form', { name: '经营分析查询' })
  const start = query.getByPlaceholder('开始日期', { exact: true }), end = query.getByPlaceholder('结束日期', { exact: true })
  await start.click(); await start.fill(addDays(today, -6)); await start.press('Enter')
  await end.click(); await end.fill(today); await end.press('Enter')
  await query.getByText('按月', { exact: true }).click()
  await query.getByRole('button', { name: '查询', exact: true }).click()
  await page.waitForURL(url => url.searchParams.get('granularity') === 'month'); await ready()
  assert.deepEqual(state.queries.at(-1), { start_date: addDays(today, -6), end_date: today, granularity: 'month' })
  await page.getByText('月度趋势只汇总所选日期覆盖的部分月份，不自动扩大为完整月份。', { exact: true }).waitFor()
  await page.reload(); await ready()
  assert.equal(await start.inputValue(), addDays(today, -6))
  assert.equal(await end.inputValue(), today)
  await query.getByRole('button', { name: '重置', exact: true }).click(); await page.waitForURL(`${base}/analysis`); await ready()
  await page.goBack(); await ready(); assert.equal(new URL(page.url()).searchParams.get('granularity'), 'month')
  await page.goForward(); await ready()
  state.status = 500
  await page.getByRole('button', { name: '刷新数据', exact: true }).click()
  await page.getByText('经营分析加载失败', { exact: true }).waitFor()
  assert.equal(await region('经营指标').count(), 0, '失败不能保留旧统计或显示伪零值')
  state.status = 422
  await page.getByRole('button', { name: '重试', exact: true }).click()
  await page.getByText('开始日期不在允许范围', { exact: true }).waitFor()
  state.status = 200; state.malformed = true
  await page.getByRole('button', { name: '重试', exact: true }).click()
  await page.getByText('经营分析响应格式无效，请重试', { exact: true }).waitFor()
  state.malformed = false; state.empty = true
  await page.getByRole('button', { name: '重试', exact: true }).click(); await ready()
  assert.match(await metric('订单实付').innerText(), /0\.00/)
  await page.getByText('暂无订单状态数据', { exact: true }).waitFor()
  await page.screenshot({ path: `${output}/analysis-empty.png`, fullPage: true })
  state.empty = false
  const beforeInvalid = state.queries.length
  await page.goto(`${base}/analysis?start_date=2026-02-30&end_date=${today}&granularity=year`)
  await page.getByText('查询条件无效，请调整日期范围或趋势粒度后重新查询。', { exact: true }).waitFor()
  assert.equal(state.queries.length, beforeInvalid)
  await page.getByRole('button', { name: '重置', exact: true }).click(); await ready()
  state.status = 403
  await page.getByRole('button', { name: '刷新数据', exact: true }).click()
  await page.getByText('对不起，您没有访问该资源的权限', { exact: true }).waitFor()
  assert.equal(await region('经营指标').count(), 0)
  await context.close()
  console.log('通过：独立分析权限、真实聚合契约、负净实付/零值/前期零值、URL查询/月粒度/重置返回、失败重试/422字段/无效响应/403、1440/1024/390/320px与双列深色VChart')

  const denied = await setup(['admin.page.dashboard.view'])
  await denied.page.goto(`${base}/analysis`)
  await denied.page.getByText('对不起，您没有访问该资源的权限', { exact: true }).waitFor()
  assert.equal(denied.state.queries.length, 0)
  assert.equal(await denied.page.getByRole('navigation', { name: '后台导航', exact: true }).getByText('经营分析', { exact: true }).count(), 0)
  await denied.context.close()
  const race = await setup()
  race.state.delay = true
  await race.page.goto(`${base}/analysis`)
  await race.page.getByLabel('经营分析加载中', { exact: true }).waitFor()
  assert(race.state.release)
  await race.page.goto(`${base}/analysis?granularity=bad`)
  race.state.release()
  await race.page.getByText('查询条件无效，请调整日期范围或趋势粒度后重新查询。', { exact: true }).waitFor()
  assert.equal(await race.region('经营指标').count(), 0)
  await race.context.close()
  const roles = await setup([analysisPermission, 'admin.roles.view', 'admin.roles.update'])
  await roles.page.goto(`${base}/roles`)
  await roles.page.getByRole('link', { name: '分配权限', exact: true }).click()
  const permission = roles.page.getByRole('checkbox', { name: '选中菜单：经营分析', exact: true })
  await permission.waitFor({ state: 'attached' }); assert(await permission.isEnabled())
  await permission.locator('..').click(); assert(await permission.isChecked())
  assert.equal(await roles.page.getByRole('checkbox', { name: /新增经营分析|修改经营分析|删除经营分析/ }).count(), 0)
  await roles.context.close()
  assert.deepEqual(errors, []); assert.deepEqual(writes, [])
  console.log('通过：工作台权限不能访问分析、旧请求取消隔离、角色授权含总览/经营分析唯一查看权限，无写请求和浏览器运行错误')
} finally { await browser.close() }
