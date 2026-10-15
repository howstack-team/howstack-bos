import assert from 'node:assert/strict'
import { consoleBootstrap } from './console-bootstrap-fixture.mjs'
const { chromium, expect } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
let scenarios = 0
const errors = []
try {
  for (const mobile of [false, true]) {
    console.log(mobile ? '验证移动端充值' : '验证PC充值')
    const base = `http://127.0.0.1:${mobile ? 5177 : 5176}/console`
    const context = await browser.newContext({ viewport: { width: mobile ? 390 : 1440, height: 1000 } })
    await context.addInitScript(() => { Object.defineProperty(crypto, 'randomUUID', { value: undefined, configurable: true }) })
    const page = await context.newPage(); page.setDefaultTimeout(12000); page.on('pageerror', e => errors.push(e.message))
    let rows = [], writes = [], mode = 'pending', available = 0, enabled = true
    await context.route('**/api/console/v1/**', async route => {
      const path = new URL(route.request().url()).pathname.replace('/api/console/v1', ''), post = route.request().method() === 'POST'
      let data = null, status = 200, message = 'ok'
      if (path === '/bootstrap') data = consoleBootstrap
      else if (path === '/auth/me') data = { user: { id: '728461931', mobile: '13800138000', email: null, nickname: '充值测试会员' } }
      else if (path === '/wallet') data = { currency: 'CNY', available_minor: available, frozen_minor: 0 }
      else if (path === '/recharges/options') data = { enabled, currency: 'CNY', min_minor: 1, max_minor: 100000000, channels: enabled ? [{ id: 7, name: '支付宝', plugin_id: 'alipay_order_code' }] : [] }
      else if (path === '/recharges' && post) {
        const input = route.request().postDataJSON(); writes.push(input)
        assert.equal(input.amount_minor, 1001); assert.equal(input.channel_id, 7); assert.match(input.request_key, /^[A-Za-z0-9_-]{16,64}$/)
        if (mode === 'fields') { status = 422; message = '请检查金额'; data = { errors: { amount_minor: ['后端金额验证失败'] } } }
        else {
          if (!rows.length) rows = [{ id: 1, recharge_no: 'R202610150000001', payment_no: 'P202610150000001', amount_minor: 1001, currency: 'CNY', plugin_id: 'alipay_order_code', status: 'pending', qr_code: 'https://qr.alipay.com/test', expires_at: '2099-01-01 00:00:00', paid_at: null, created_at: '2026-10-15 10:00:00', message: '' }]
          if (mode === 'network') { await route.abort(); return }
          data = rows[0]
        }
      } else if (path === '/recharges') data = { data: rows, total: rows.length }
      else if (path === '/recharges/1') data = rows[0]
      else if (path === '/recharges/1/query' || path === '/recharges/1/close') {
        rows[0] = { ...rows[0], status: mode === 'paid' ? 'paid' : path.endsWith('close') ? 'closed' : 'unknown', qr_code: null, message: mode === 'paid' ? '' : '支付结果待确认' }
        if (mode === 'paid') { available = 1001; rows[0].paid_at = '2026-10-15 10:01:00' }
        data = rows[0]
      } else { status = 404; message = '接口未配置' }
      await route.fulfill({ status, json: { code: status === 200 ? 0 : status * 100, message, data, request_id: 'recharge-browser-fixture' } })
    })
    const fill = async () => { await page.getByPlaceholder('请输入充值金额').fill('10.01'); await page.getByText('支付宝', { exact: true }).first().click() }
    await page.goto(`${base}/finance/recharge`)
    await page.getByRole('button', { name: '前往付款', exact: true }).click()
    await page.getByText('请选择支付方式', { exact: true }).waitFor(); assert.equal(writes.length, 0)
    await page.getByPlaceholder('请输入充值金额').fill('10.001')
    await page.getByText('支付宝', { exact: true }).first().click()
    await page.getByRole('button', { name: '前往付款', exact: true }).click(); assert.equal(writes.length, 0)
    scenarios++
    await fill(); mode = 'fields'; await page.getByRole('button', { name: '前往付款', exact: true }).click()
    await page.getByText('后端金额验证失败', { exact: true }).waitFor()
    if (mobile) assert.equal(await page.locator('.m-sr-only').getByText('后端金额验证失败', { exact: true }).count(), 0)
    else assert.equal(await page.locator('.arco-message').getByText('后端金额验证失败', { exact: true }).count(), 0)
    scenarios++
    mode = 'network'; await page.getByRole('button', { name: '前往付款', exact: true }).click()
    await expect.poll(() => writes.length).toBe(2)
    await page.reload(); await fill(); mode = 'pending'; await page.getByRole('button', { name: '前往付款', exact: true }).click()
    await page.waitForURL(`${base}/finance/recharge?id=1`)
    assert.equal(writes[2].request_key, writes[1].request_key)
    await page.getByAltText('支付二维码', { exact: true }).waitFor()
    assert.match(await page.getByAltText('支付二维码').getAttribute('src'), /^data:image\/png;/)
    scenarios++
    await page.reload(); await page.getByAltText('支付二维码').waitFor(); assert.equal(writes.length, 3)
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true)
    if (mobile) { await page.setViewportSize({ width: 320, height: 740 }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true) }
    scenarios++
    await page.getByRole('button', { name: '查询支付结果', exact: true }).click()
    await page.getByText('支付结果待确认', { exact: true }).waitFor()
    assert.equal(await page.getByText('已到账', { exact: true }).count(), 0)
    scenarios++
    mode = 'paid'; await expect(page.getByRole('button', { name: '查询支付结果', exact: true })).toBeEnabled(); await page.getByRole('button', { name: '查询支付结果', exact: true }).click()
    await page.getByText('返回继续', { exact: true }).waitFor()
    assert.equal(await page.getByAltText('支付二维码').count(), 0)
    await page.getByText('返回继续', { exact: true }).click(); await page.waitForURL(`${base}/finance/wallet`)
    await page.getByText(/10.01/).first().waitFor()
    scenarios++
    enabled = false; await page.goto(`${base}/finance/recharge`)
    await page.getByText('在线充值暂不可用，请联系平台。', { exact: true }).waitFor()
    assert.equal(await page.getByRole('button', { name: '前往付款', exact: true }).count(), 0)
    scenarios++
    await context.close()
  }
  assert.deepEqual(errors, [])
  console.log(JSON.stringify({ scenarios, invalidCreateRequests: 0, duplicateFieldFeedback: 0, realGatewayRequests: 0 }))
} finally { await browser.close() }
