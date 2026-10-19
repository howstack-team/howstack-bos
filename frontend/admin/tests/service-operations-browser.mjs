import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
await mkdir('/tmp/howstack-service-operations-browser', { recursive: true })
try {
  for (const [readonly, width] of [[true, 1440], [false, 1440], [false, 390]]) {
    const context = await browser.newContext({ viewport: { width, height: 950 } }), page = await context.newPage()
    const errors = [], writes = []
    page.on('pageerror', error => errors.push(error.message))
    const row = { id: 17, instance_no: 'bos-existing-resource', product_type: 'cloud_server', member_id: 1, member_nickname: '测试会员', member_mobile: '13800138000', order_no: 'ORIGINAL-9', display_name: '原云服务器', status: 'active', activated_at: '2026-10-18 10:00:00', expires_at: '2027-11-18 10:00:00', created_at: '2026-10-18 10:00:00', provisioning_status: 'succeeded', addresses: ['192.0.2.10'] }
    let accepted = false
    await context.route('**/api/admin/v1/**', async route => {
      const path = new URL(route.request().url()).pathname.replace('/api/admin/v1', '')
      let data = null
      if (path === '/auth/me') data = { user: { id: 1, username: 'fixture', nickname: '测试管理员', is_founder: false }, permissions: ['admin.services.cloud-servers.view', ...(!readonly ? ['admin.services.cloud-servers.power'] : [])] }
      else if (path === '/service-instances') data = { data: [row], total: 1 }
      else if (path.endsWith('/operations')) {
        if (route.request().method() === 'POST') { writes.push(route.request().postDataJSON()); accepted = true; data = { id: 71, status: 'queued' } }
        else data = { vnc_available: false, actions: readonly ? [] : ['power_on', 'power_off', 'reboot'], images: [], busy: accepted, power_status: 'on', power_checked_at: '2026-10-18 12:00:00', history: accepted ? [{ id: 71, action: 'reboot', status: 'waiting', error_code: null, created_at: '2026-10-18 12:00:00', updated_at: '2026-10-18 12:00:00' }] : [] }
      }
      await route.fulfill({ json: { code: 0, message: 'ok', data, request_id: 'admin-operation-fixture' } })
    })
    await page.goto('http://127.0.0.1:5175/admin/services/cloud-servers')
    await page.getByText('原云服务器', { exact: true }).waitFor()
    const manage = page.getByText('管理', { exact: true })
    if (!await manage.isVisible()) await page.getByText('更多', { exact: true }).first().click()
    await manage.click()
    await page.getByText('云服务器管理', { exact: true }).waitFor()
    if (readonly) {
      await page.getByText('当前实例状态或供应插件不支持运维操作，或没有操作权限。', { exact: true }).waitFor()
      assert.equal(await page.getByText('重启', { exact: true }).count(), 0)
      assert.equal(writes.length, 0)
    } else {
      await page.getByText('重启', { exact: true }).click()
      assert.equal(await page.getByText('重装系统', { exact: true }).count(), 0, '电源权限不能展示重装')
      const submit = page.getByRole('button', { name: '确认提交', exact: true })
      await submit.click(); await page.waitForTimeout(100)
      assert.equal(writes.length, 0)
      await page.getByText('我已了解操作影响', { exact: true }).click()
      await page.screenshot({ path: `/tmp/howstack-service-operations-browser/admin-${width}.png`, fullPage: true })
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false)
      await submit.click()
      await page.getByText('操作已受理，请等待供应商确认结果', { exact: true }).waitFor()
      await page.getByText('重启 · 等待供应商确认', { exact: true }).waitFor()
      assert.equal(writes.length, 1); assert.equal(writes[0].action, 'reboot'); assert.equal(writes[0].acknowledged, true)
      assert.equal(Object.hasOwn(writes[0], 'password'), false)
    }
    assert.deepEqual(errors, [])
    await context.close()
    console.log(`后台 ${readonly ? '只读' : '电源权限'} ${width}px：能力显示、校验拦截、确认提交和无溢出通过（模拟接口）`)
  }
} finally { await browser.close() }
