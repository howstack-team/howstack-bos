import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
import { consoleBootstrap } from './console-bootstrap-fixture.mjs'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
await mkdir('/tmp/howstack-provisioning-browser', { recursive: true })
const errors = []
try {
  for (const [mobile, width] of [[false, 1440], [true, 390], [true, 320]]) {
    const base = `http://127.0.0.1:${mobile ? 5177 : 5176}/console`
    const context = await browser.newContext({ viewport: { width, height: 900 }, isMobile: mobile, hasTouch: mobile, ...(mobile ? { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1' } : {}) })
    const page = await context.newPage(); page.on('pageerror', error => errors.push(error.message))
    let passwordReads = 0, complete = false
    const row = () => ({ id: 17, instance_no: 'bos-fixture-request-123456', display_name: '云服务器 1', status: complete ? 'active' : 'provisioning', provisioning_status: complete ? 'succeeded' : 'waiting', addresses: complete ? ['192.0.2.10'] : [], initial_password_available: complete, activated_at: complete ? '2026-10-18 10:00:00' : null, expires_at: complete ? '2026-11-18 10:00:00' : null, created_at: '2026-10-18 10:00:00', order_id: 9, order_no: 'ORDER-9' })
    const ok = data => ({ json: { code: 0, message: 'ok', data, request_id: 'service-fixture' } })
    await context.route('**/api/console/v1/**', async route => {
      const path = new URL(route.request().url()).pathname.replace('/api/console/v1', '')
      if (path === '/bootstrap') return route.fulfill(ok(consoleBootstrap))
      if (path === '/auth/me') return route.fulfill(ok({ user: { id: '728461931', mobile: '13800138000', email: null, nickname: '测试会员' } }))
      if (path === '/services') return route.fulfill(ok({ data: [row()], total: 1 }))
      if (path === '/services/17/initial-password') { passwordReads++; return route.fulfill(ok({ password: 'Initial@123456' })) }
      if (path === '/orders/9') return route.fulfill(ok({ id: 9, order_no: 'ORDER-9', status: 'fulfilling', payment_status: 'paid', currency: 'CNY', payable_minor: 2000, paid_minor: 2000, created_at: '2026-10-18 10:00:00', items: [{ product_name: '云服务器', quantity: 1, total_minor: 2000 }], units: [{ id: 1, unit_index: 1, service_id: 17, instance_no: row().instance_no, status: row().status, provisioning_status: row().provisioning_status }] }))
      return route.fulfill(ok({ unread_count: 0, items: [] }))
    })
    await page.goto(`${base}/services/cloud-servers`)
    await page.getByText('等待供应商确认', { exact: true }).waitFor()
    assert.equal(passwordReads, 0)
    complete = true
    await page.reload()
    await page.getByText('192.0.2.10', { exact: !mobile }).waitFor()
    await page.getByText('初始密码', { exact: true }).click()
    await page.getByText('Initial@123456', { exact: true }).waitFor()
    assert.equal(passwordReads, 1)
    await page.getByText('关闭', { exact: true }).last().click()
    await page.getByText('Initial@123456', { exact: true }).waitFor({ state: 'detached' })
    await page.getByText('实例初始密码', { exact: true }).waitFor({ state: 'detached' })
    const stored = await page.evaluate(() => JSON.stringify({ local: { ...localStorage }, session: { ...sessionStorage } }))
    assert.equal(stored.includes('Initial@123456'), false)
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false)
    await page.screenshot({ path: `/tmp/howstack-provisioning-browser/${mobile ? 'mobile' : 'pc'}-${width}.png`, fullPage: true })
    await page.goto(`${base}/finance/orders/9`)
    await page.getByText('ORDER-9', { exact: true }).waitFor()
    await page.getByText('已开通', { exact: true }).waitFor()
    await context.close()
    console.log(`${mobile ? '移动端' : 'PC'} ${width}px：实例状态、订单逐台进度、密码弹窗与无横向溢出通过`)
  }
  assert.deepEqual(errors, [])
} finally { await browser.close() }
