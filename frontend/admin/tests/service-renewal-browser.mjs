import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
await mkdir('/tmp/howstack-renewal-browser', { recursive: true })
try {
  for (const [readonly, width] of [[true, 1440], [false, 1440], [false, 390]]) {
    const context = await browser.newContext({ viewport: { width, height: 950 } }), page = await context.newPage()
    const writes = [], errors = []
    page.on('pageerror', error => errors.push(error.message))
    const permissions = ['admin.services.cloud-servers.view', ...(!readonly ? ['admin.services.cloud-servers.renew', 'admin.services.cloud-servers.lifecycle'] : [])]
    const rows = [
      { id: 17, instance_no: 'bos-existing-resource', product_type: 'cloud_server', member_id: 1, member_nickname: '测试会员', member_mobile: '13800138000', order_no: 'ORIGINAL-9', display_name: '原云服务器', status: 'active', activated_at: '2026-10-18 10:00:00', expires_at: '2026-11-18 10:00:00', created_at: '2026-10-18 10:00:00', provisioning_status: 'succeeded', provisioning_attempts: 2, provisioning_error: null, addresses: ['192.0.2.10'], can_renew: true, lifecycle_action: null, lifecycle_status: null, lifecycle_error: null, renewal_order_id: null, renewal_payment_pending: false },
      { id: 18, instance_no: 'bos-expired-resource', product_type: 'cloud_server', member_id: 1, member_nickname: '测试会员', member_mobile: '13800138000', order_no: 'ORIGINAL-8', display_name: '待处理云服务器', status: 'expired', activated_at: '2026-01-18 10:00:00', expires_at: '2026-02-18 10:00:00', created_at: '2026-01-18 10:00:00', provisioning_status: 'succeeded', provisioning_attempts: 2, provisioning_error: null, addresses: ['192.0.2.11'], can_renew: false, lifecycle_action: 'renew', lifecycle_status: 'attention', lifecycle_error: 'provider_result_unknown', renewal_order_id: 70, renewal_payment_pending: false },
    ]
    await context.route('**/api/admin/v1/**', async route => {
      const path = new URL(route.request().url()).pathname.replace('/api/admin/v1', '')
      let data = null
      if (path === '/auth/me') data = { user: { id: 1, username: 'fixture', nickname: '测试管理员', is_founder: false }, permissions }
      else if (path === '/service-instances') data = { data: rows, total: 2 }
      else if (path.endsWith('/renewal-quote')) {
        const months = route.request().postDataJSON().months
        await new Promise(resolve => setTimeout(resolve, 100))
        data = { service_id: 17, expires_at: rows[0].expires_at, durations: [{ months: 1, label: '月付' }, { months: 12, label: '年付' }], ...(months ? { months, amount_minor: months === 12 ? 8800 : 1000, currency: 'CNY' } : {}) }
      } else if (path.endsWith('/renewals')) { writes.push(route.request().postDataJSON()); data = { id: 71 } }
      else if (path.endsWith('/retry-lifecycle')) { writes.push({ retry: true }) }
      await route.fulfill({ json: { code: 0, message: 'ok', data, request_id: 'admin-renewal-fixture' } })
    })
    await page.goto('http://127.0.0.1:5175/admin/services/cloud-servers')
    await page.getByText('原云服务器', { exact: true }).waitFor()
    if (readonly) {
      assert.equal(await page.getByText('续费', { exact: true }).count(), 0)
      assert.equal(await page.getByText('重试处理', { exact: true }).count(), 0)
    } else {
      // TableActions collapses to the existing menu at narrow widths.
      const clickAction = async name => {
        const action = page.getByText(name, { exact: true })
        if (!await action.isVisible()) {
          const more = page.getByText('更多', { exact: true });
          for (let i = 0; i < await more.count(); i++) { await more.nth(i).click(); if (await action.isVisible()) break }
        }
        await action.click()
      }
      await clickAction('续费')
      const submit = page.getByRole('button', { name: '创建续费订单', exact: true })
      await page.getByText('¥10.00 元', { exact: true }).waitFor()
      await page.locator('.arco-modal .arco-select').click()
      await page.getByText('年付', { exact: true }).last().click()
      await page.getByText('¥88.00 元', { exact: true }).waitFor()
      await page.screenshot({ path: `/tmp/howstack-renewal-browser/admin-${width}.png`, fullPage: true })
      await submit.click()
      await page.getByText('已创建待付款的续费订单', { exact: true }).waitFor()
      assert.equal(writes.length, 1); assert.equal(writes[0].months, 12); assert.equal(writes[0].expected_amount_minor, 8800)
      await clickAction('重试处理')
      await page.getByRole('button', { name: '确定', exact: true }).click()
      await page.getByText('已重新排队', { exact: true }).waitFor()
      assert.equal(writes.length, 2)
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false)
    }
    assert.deepEqual(errors, [])
    await context.close()
    console.log(`后台 ${width}px ${readonly ? '只读' : '管理'}：权限隔离、续费计价、创建及生命周期重试通过（模拟接口）`)
  }
} finally { await browser.close() }
