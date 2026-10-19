import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
import { consoleBootstrap } from './console-bootstrap-fixture.mjs'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
await mkdir('/tmp/howstack-renewal-browser', { recursive: true })
try {
  for (const [mobile, width] of [[false, 1440], [true, 390], [true, 320]]) {
    const context = await browser.newContext({ viewport: { width, height: 960 }, isMobile: mobile, hasTouch: mobile, ...(mobile ? { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1' } : {}) })
    const page = await context.newPage(), errors = [], creates = []
    page.on('pageerror', error => errors.push(error.message))
    let failOnce = true, pays = 0, cancelled = false
    const row = { id: 17, instance_no: 'bos-renewal-fixture', display_name: '原云服务器', status: 'active', provisioning_status: 'succeeded', addresses: ['192.0.2.10'], activated_at: '2026-10-18 10:00:00', expires_at: '2026-11-18 10:00:00', created_at: '2026-10-18 10:00:00', order_id: 9, order_no: 'ORIGINAL-9', can_renew: true, lifecycle_action: null, lifecycle_status: null, lifecycle_error: null, renewal_order_id: null, renewal_payment_pending: false }
    const durations = [{ months: 1, label: '月付' }, { months: 12, label: '年付' }]
    const ok = data => ({ json: { code: 0, message: 'ok', data, request_id: 'renewal-fixture' } })
    await context.route('**/api/console/v1/**', async route => {
      const path = new URL(route.request().url()).pathname.replace('/api/console/v1', '')
      if (path === '/bootstrap') return route.fulfill(ok(consoleBootstrap))
      if (path === '/auth/me') return route.fulfill(ok({ user: { id: '728461931', mobile: '13800138000', email: null, nickname: '测试会员' } }))
      if (path === '/services') return route.fulfill(ok({ data: [row], total: 1 }))
      if (path === '/services/17/renewal-quote') {
        const { months } = route.request().postDataJSON()
        assert.ok([0, 1, 12].includes(months), '不能发送未经选择的月份')
        await new Promise(resolve => setTimeout(resolve, 120))
        return route.fulfill(ok({ service_id: 17, expires_at: row.expires_at, durations, ...(months ? { months, currency: 'CNY', amount_minor: months === 12 ? 8800 : 1000 } : {}) }))
      }
      if (path === '/services/17/renewals') {
        creates.push(route.request().postDataJSON())
        if (failOnce) { failOnce = false; return route.fulfill({ status: 503, json: { code: 50300, message: '临时不可用，请重试', data: null, request_id: 'fixture' } }) }
        return route.fulfill(ok({ id: 71 }))
      }
      if (path === '/orders/71/cancel') { cancelled = true; return route.fulfill(ok(null)) }
      if (path === '/orders/71/pay-wallet') { pays++; return route.fulfill(ok(null)) }
      if (path === '/orders/71') return route.fulfill(ok({ id: 71, order_no: 'RENEWAL-71', order_type: 'renewal', status: cancelled ? 'cancelled' : 'pending_payment', payment_status: 'unpaid', currency: 'CNY', payable_minor: 8800, paid_minor: 0, created_at: '2026-10-18 12:00:00', items: [{ product_name: '云服务器（续费）', quantity: 1, total_minor: 8800 }], units: [{ id: 11, unit_index: 1, service_id: 17, instance_no: row.instance_no, status: 'active', provisioning_status: null }], renewal: { service_id: 17, months: 12, expected_expires_at: row.expires_at, target_expires_at: row.expires_at, status: 'unpaid' } }))
      return route.fulfill(ok({ unread_count: 0, items: [] }))
    })
    await page.goto(`http://127.0.0.1:${mobile ? 5177 : 5176}/console/services/cloud-servers`)
    await page.getByText('续费', { exact: true }).click()
    const submit = mobile ? page.getByText('创建续费订单', { exact: true }) : page.getByRole('button', { name: '创建订单并付款', exact: true })
    if (!mobile) assert.equal(await submit.isDisabled(), true)
    await page.getByText('¥10.00', { exact: !mobile }).waitFor()
    if (mobile) await page.getByText('年付', { exact: true }).click()
    else { await page.locator('.arco-modal .arco-select').click(); await page.getByText('年付', { exact: true }).last().click() }
    await page.getByText('¥88.00', { exact: !mobile }).waitFor()
    await page.screenshot({ path: `/tmp/howstack-renewal-browser/${mobile ? 'mobile' : 'pc'}-${width}.png`, fullPage: true })
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false)
    await submit.click()
    await page.getByText('临时不可用，请重试', { exact: true }).last().waitFor()
    await submit.click()
    await page.waitForURL('**/console/finance/orders/71')
    await page.getByText('RENEWAL-71', { exact: true }).waitFor()
    assert.equal(creates.length, 2)
    assert.deepEqual(creates[0], creates[1], '失败重试须保留同一幂等请求')
    assert.equal(creates[0].months, 12)
    assert.equal(creates[0].expected_amount_minor, 8800)
    assert.equal(pays, 0, '创建订单不能自动扣款')
    await page.getByText('取消订单', { exact: true }).click()
    await page.getByText(mobile ? '确认取消' : '确定', { exact: true }).last().click()
    await page.getByText('已取消', { exact: true }).first().waitFor()
    assert.equal(cancelled, true)
    assert.deepEqual(errors, [])
    await context.close()
    console.log(`${mobile ? '移动' : 'PC'} ${width}px：续费周期、重新报价、幂等重试、原订单支付入口、取消及无溢出通过（模拟接口）`)
  }
} finally { await browser.close() }
