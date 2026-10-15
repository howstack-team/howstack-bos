import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = process.env.FINANCE_BROWSER_OUTPUT ?? '/tmp/howstack-finance-ui'
await mkdir(output, { recursive: true })
const resources = ['recharges', 'payments', 'transactions', 'refunds', 'invoices', 'withdrawals']
const permissions = resources.flatMap(resource => ['view', 'create', 'review', 'execute', 'issue', 'red', 'payout'].map(action => `admin.finance.${resource}.${action}`))
const errors = [], writes = [], reads = []
async function setup(readOnly = false) {
  const context = await browser.newContext(), page = await context.newPage()
  page.on('pageerror', error => errors.push(error.message))
  const records = Object.fromEntries(resources.map(resource => [resource, [{ id: 1, member_id: 1, amount_minor: 500, currency: 'CNY', status: 'pending', operator_id: 1,
    source_type: 'order_unit', source_id: 1, destination: 'wallet', title: '测试企业', invoice_type: 'electronic', channel: 'offline',
    refund_no: 'RF-TEST', invoice_no: 'IV-TEST', withdrawal_no: 'WD-TEST', recharge_no: 'RC-TEST', payment_no: 'PAY-TEST', entry_no: 'WL-TEST',
    created_at: '2026-10-08 12:00:00', review_reason: '', reason: '业务测试' }]]))
  await page.route('**/api/admin/v1/**', async route => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/admin/v1', '')
    let data = null
    if (path === '/auth/me') data = { user: { id: 1, username: 'mock', nickname: '模拟财务管理员', is_founder: false }, permissions: readOnly ? permissions.filter(code => code.endsWith('.view')) : permissions }
    const parts = path.split('/').filter(Boolean), resource = parts[1]
    if (parts[0] === 'finance') {
      if (request.method() === 'GET') {
        reads.push({ resource, query: Object.fromEntries(url.searchParams) })
        if (parts[2] === 'members') data = [{ id: 1, label: '测试会员（ID：1）' }]
        else if (parts[2] === 'options') data = [{ id: 1, label: 'ORDER-TEST · 实例 1', available_minor: 1200 }]
        else if (parts[2]) data = records[resource][0]
        else data = { data: records[resource], total: 1 }
      } else {
        const body = request.postDataJSON(); writes.push({ resource, action: parts[3] ?? 'create', body })
        assert.match(body.request_key, /^[a-f0-9-]{36}$/)
        if (parts[3]) records[resource][0].status = { approve: 'approved', reject: 'rejected', execute: 'completed', issue: 'issued', red: 'red', payout: 'paid' }[parts[3]]
        data = { id: 1 }
      }
    }
    await route.fulfill({ json: { code: 0, message: 'ok', data, request_id: 'finance-browser' } })
  })
  return { context, page }
}
async function drawer(page, title) {
  const target = page.locator('.arco-drawer').filter({ has: page.locator('.arco-drawer-header-title', { hasText: title }) })
  await target.waitFor()
  await target.getByRole('button', { name: `确认${title}`, exact: true }).waitFor()
  return target
}
async function choose(target, index, label) {
  await target.locator('.arco-select').nth(index).click()
  await target.page().locator('.arco-select-option').filter({ hasText: label }).click()
}
try {
  for (const width of [1440, 390]) {
    const { context, page } = await setup()
    await page.setViewportSize({ width, height: 1000 })
    for (const resource of resources) {
      await page.goto(`${base}/${resource}`)
      await page.getByRole('button', { name: '查询', exact: true }).waitFor()
      await page.getByRole('link', { name: '详情', exact: true }).waitFor()
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${resource} 页面溢出`)
    }
    await page.goto(`${base}/recharges`)
    await page.getByRole('button', { name: '线下充值入账', exact: true }).click()
    const deposit = await drawer(page, '线下充值入账')
    await deposit.getByRole('button', { name: '确认线下充值入账' }).click()
    assert.equal(writes.filter(write => write.resource === 'recharges').length, width === 1440 ? 0 : 1)
    await choose(deposit, 0, '测试会员')
    await deposit.getByPlaceholder('请输入金额', { exact: true }).fill('12.34')
    await deposit.getByPlaceholder('请输入真实收款凭证编号').fill(`BANK-${width}`)
    await deposit.getByPlaceholder('请输入备注（选填）').fill('财务登记')
    await deposit.getByText('备注', { exact: true }).click()
    await page.waitForFunction(() => !document.querySelector('.arco-form-item-status-error'))
    await page.waitForFunction(() => !document.querySelector('.arco-select-error, .arco-input-error, .arco-input-number-error'))
    await page.waitForTimeout(400)
    await page.screenshot({ path: `${output}/recharge-${width}.png`, fullPage: true })
    await deposit.getByRole('button', { name: '确认线下充值入账' }).click()
    await deposit.waitFor({ state: 'hidden' })
    assert.equal(writes.at(-1).body.amount_minor, 1234)
    await page.goto(`${base}/refunds`)
    await page.getByRole('button', { name: '申请退款', exact: true }).click()
    const apply = await drawer(page, '申请退款')
    await choose(apply, 0, '测试会员')
    await choose(apply, 1, 'ORDER-TEST')
    await apply.getByPlaceholder('请输入金额', { exact: true }).fill('5.00')
    await apply.getByPlaceholder('请输入申请原因').fill('实例已终止')
    await apply.getByRole('button', { name: '确认申请退款', exact: true }).click()
    await apply.waitFor({ state: 'hidden' })
    await page.getByRole('link', { name: '通过', exact: true }).click()
    const approve = await drawer(page, '审核通过')
    await approve.getByRole('button', { name: '确认审核通过', exact: true }).click()
    await approve.waitFor({ state: 'hidden' })
    await page.getByRole('link', { name: '执行退款', exact: true }).click()
    const execute = await drawer(page, '执行余额退款')
    await execute.getByRole('button', { name: '确认执行余额退款', exact: true }).click()
    await execute.waitFor({ state: 'hidden' })
    await page.getByText('已完成', { exact: true }).waitFor()
    for (const resource of ['invoices', 'withdrawals']) {
      await page.goto(`${base}/${resource}`)
      const createTitle = resource === 'invoices' ? '申请开票' : '申请提现'
      await page.getByRole('button', { name: createTitle, exact: true }).click()
      const create = await drawer(page, createTitle)
      await choose(create, 0, '测试会员')
      await choose(create, 1, 'ORDER-TEST')
      await create.getByPlaceholder('请输入金额', { exact: true }).fill('5.00')
      if (resource === 'invoices') await create.getByPlaceholder('请输入发票抬头').fill('测试企业')
      else await create.getByPlaceholder('请输入申请原因').fill('退还未消费充值')
      await create.getByRole('button', { name: `确认${createTitle}`, exact: true }).click()
      await create.waitFor({ state: 'hidden' })
      await page.getByRole('link', { name: '通过', exact: true }).click()
      const approve = await drawer(page, '审核通过')
      await approve.getByRole('button', { name: '确认审核通过', exact: true }).click()
      await approve.waitFor({ state: 'hidden' })
      const nextTitle = resource === 'invoices' ? '登记开票' : '登记打款'
      await page.getByRole('link', { name: nextTitle, exact: true }).click()
      const record = await drawer(page, nextTitle)
      await record.getByPlaceholder(resource === 'invoices' ? '请输入真实发票号码' : '请输入真实打款凭证编号').fill(`EXTERNAL-${width}-${resource}`)
      await record.getByRole('button', { name: `确认${nextTitle}`, exact: true }).click()
      await record.waitFor({ state: 'hidden' })
      if (resource === 'invoices') {
        await page.getByRole('link', { name: '登记红冲', exact: true }).click()
        const red = await drawer(page, '登记红冲')
        await red.getByPlaceholder('请输入真实红字发票号码').fill(`RED-${width}`)
        await red.getByRole('button', { name: '确认登记红冲', exact: true }).click()
        await red.waitFor({ state: 'hidden' })
      }
    }
    await page.goto(`${base}/refunds`)
    await page.getByText('已完成', { exact: true }).waitFor()
    if (width === 1440) {
      await page.getByRole('textbox', { name: '单号', exact: true }).fill('RF-TEST')
      await page.getByRole('textbox', { name: '会员 ID', exact: true }).fill('1')
      await page.getByRole('button', { name: '查询', exact: true }).click()
      await page.waitForURL(/number=RF-TEST.*member_id=1/)
      await page.waitForFunction(() => !document.querySelector('.arco-spin-loading'))
      await page.getByRole('button', { name: '重置', exact: true }).click()
      await page.waitForURL(url => !url.searchParams.has('number') && !url.searchParams.has('member_id'))
    }
    await page.screenshot({ path: `${output}/refunds-${width}.png`, fullPage: true })
    await context.close()
  }
  const { context, page } = await setup(true)
  await page.goto(`${base}/refunds`)
  await page.getByRole('link', { name: '详情', exact: true }).waitFor()
  assert.equal(await page.getByRole('button', { name: '申请退款', exact: true }).count(), 0)
  assert.equal(await page.getByRole('link', { name: '通过', exact: true }).count(), 0)
  await page.getByRole('link', { name: '详情', exact: true }).click()
  await page.getByText('退款记录详情', { exact: true }).waitFor()
  assert.equal(await page.locator('.arco-drawer').count(), 1)
  await context.close()
  assert.deepEqual(errors, [])
  assert.ok(reads.some(read => read.resource === 'refunds' && read.query.number === 'RF-TEST' && read.query.member_id === '1'))
  console.log(JSON.stringify({ mocked: true, realWrites: 0, writes: writes.length, widths: [1440, 390], screenshots: output }))
} finally { await browser.close() }
