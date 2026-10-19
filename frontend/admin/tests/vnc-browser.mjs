import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
import { installRfbFixture, prepareRfbTransport, sawKey } from '../../packages/vnc-runtime/testing/rfb-fixture.mjs'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
await mkdir('/tmp/howstack-vnc-browser', { recursive: true })
try {
  for (const [readonly, width] of [[true, 1440], [false, 1440], [false, 390]]) {
    const context = await browser.newContext({ viewport: { width, height: 950 } }), page = await context.newPage()
    const rfb = await installRfbFixture(context), writes = [], errors = []
    page.on('pageerror', error => errors.push(error.message))
    const row = { id: 17, instance_no: 'bos-existing-vnc', product_type: 'cloud_server', member_id: 1, member_nickname: '测试会员', member_mobile: '13800138000', order_no: 'ORIGINAL-9', display_name: '原云服务器', status: 'active', activated_at: '2026-10-18 10:00:00', expires_at: '2027-11-18 10:00:00', created_at: '2026-10-18 10:00:00', provisioning_status: 'succeeded', addresses: ['192.0.2.10'] }
    await context.route('**/api/admin/v1/**', async route => {
      const path = new URL(route.request().url()).pathname.replace('/api/admin/v1', '')
      let data = null
      if (path === '/auth/me') data = { user: { id: 1, username: 'fixture', nickname: '测试管理员', is_founder: false }, permissions: ['admin.services.cloud-servers.view', ...(!readonly ? ['admin.services.cloud-servers.vnc'] : [])] }
      else if (path === '/service-instances') data = { data: [row], total: 1 }
      else if (path.endsWith('/operations')) data = { vnc_available: !readonly, actions: [], images: [], history: [], busy: false, power_status: 'on', power_checked_at: null }
      else if (path.endsWith('/vnc')) {
        if (route.request().method() === 'GET') data = { allowed: true }
        else { writes.push(route.request().postDataJSON()); data = { websocket_url: 'wss://vnc.example.test/socket?token=admin-fixture', password: 'vncSecret', connect_timeout_seconds: 30, session_timeout_seconds: 900 } }
      }
      await route.fulfill({ json: { code: 0, message: 'ok', data, request_id: 'admin-vnc-fixture' } })
    })
    await page.goto('http://127.0.0.1:5175/admin/services/cloud-servers')
    await prepareRfbTransport(page)
    await page.getByText('管理', { exact: true }).click()
    if (readonly) {
      await page.getByText('当前实例状态或供应插件不支持运维操作，或没有操作权限。', { exact: true }).waitFor()
      assert.equal(await page.getByText('VNC 远程控制台', { exact: true }).count(), 0)
    } else {
      await page.getByText('VNC 远程控制台', { exact: true }).click()
      await page.getByRole('status').filter({ hasText: '尚未连接' }).waitFor()
      assert.equal(writes.length, 0)
      await page.getByRole('button', { name: '连接控制台', exact: true }).click()
      await page.getByRole('status').filter({ hasText: '已连接' }).waitFor()
      assert.equal(rfb.authenticated, 1)
      await page.getByRole('button', { name: 'Ctrl+Alt+Del', exact: true }).click()
      await page.waitForTimeout(100)
      assert.equal(sawKey(rfb, 0xffff), true)
      await page.screenshot({ path: `/tmp/howstack-vnc-browser/admin-${width}.png`, fullPage: true })
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false)
      await page.mouse.click(4, 12)
      await page.getByText('VNC 远程控制台', { exact: true }).waitFor({ state: 'hidden' })
      await page.waitForTimeout(100)
      assert.equal(rfb.closed, 1)
      assert.equal(writes.length, 1)
    }
    assert.deepEqual(errors, [])
    await context.close()
    console.log(`后台 ${readonly ? '只读' : 'VNC权限'} ${width}px：能力隔离、真实noVNC/RFB认证、按键、遮罩关闭断连和无溢出通过（模拟供应商）`)
  }
} finally { await browser.close() }
