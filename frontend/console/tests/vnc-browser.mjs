import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
import { consoleBootstrap } from './console-bootstrap-fixture.mjs'
import { installRfbFixture, prepareRfbTransport, sawKey } from '../../packages/vnc-runtime/testing/rfb-fixture.mjs'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
await mkdir('/tmp/howstack-vnc-browser', { recursive: true })
try {
  for (const [mobile, width] of [[false, 1440], [true, 390], [true, 320]]) {
    const context = await browser.newContext({ viewport: { width, height: 960 }, isMobile: mobile, hasTouch: mobile,
      ...(mobile ? { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1' } : {}) })
    const rfb = await installRfbFixture(context), page = await context.newPage(), errors = [], writes = []
    if (process.env.HOWSTACK_VNC_DEBUG) page.on('console', message => { if (['error', 'warning'].includes(message.type())) console.log(message.text().replace(/wss?:[^\s]+/g, '[endpoint]')) })
    page.on('pageerror', error => errors.push(error.message))
    let failOnce = true
    const ok = data => ({ json: { code: 0, message: 'ok', data, request_id: 'vnc-fixture' } })
    await context.route('**/api/console/v1/**', async route => {
      const path = new URL(route.request().url()).pathname.replace('/api/console/v1', '')
      if (path === '/bootstrap') return route.fulfill(ok(consoleBootstrap))
      if (path === '/auth/me') return route.fulfill(ok({ user: { id: '728461931', mobile: '13800138000', email: '', nickname: '测试会员', balance_minor: 0 } }))
      if (path === '/services') return route.fulfill(ok({ data: [{ id: 17, instance_no: 'bos-vnc-existing', display_name: '原云服务器', status: 'active', provisioning_status: 'succeeded', addresses: ['192.0.2.10'], activated_at: '2026-10-18 10:00:00', expires_at: '2027-11-18 10:00:00', created_at: '2026-10-18 10:00:00', order_id: 9, order_no: 'ORIGINAL-9' }], total: 1 }))
      if (path === '/services/17/operations') return route.fulfill(ok({ vnc_available: true, actions: ['power_on'], images: [], history: [], busy: false, power_status: 'on', power_checked_at: null }))
      if (path === '/services/17/vnc') {
        if (route.request().method() === 'GET') return route.fulfill(ok({ allowed: true }))
        writes.push(route.request().postDataJSON())
        if (failOnce) { failOnce = false; return route.fulfill({ status: 503, json: { code: 50300, message: 'VNC暂不可用，请稍后重试', data: null, request_id: 'failure' } }) }
        return route.fulfill(ok({ websocket_url: `wss://vnc.example.test/socket?token=fixture-${writes.length}`, password: 'vncSecret', connect_timeout_seconds: 30, session_timeout_seconds: 900 }))
      }
      return route.fulfill(ok({ unread_count: 0, items: [] }))
    })
    await page.goto(`http://127.0.0.1:${mobile ? 5177 : 5176}/console/services/cloud-servers`)
    await prepareRfbTransport(page)
    await page.getByText('管理', { exact: true }).click()
    await page.getByText('VNC 远程控制台', { exact: true }).click()
    await page.getByRole('status').filter({ hasText: '尚未连接' }).waitFor()
    assert.equal(writes.length, 0, '打开弹窗不能隐式签发或StrictMode重复签发')
    const connect = page.getByRole('button', { name: mobile ? '连接' : '连接控制台', exact: true })
    await connect.click()
    await page.getByText('VNC暂不可用，请稍后重试', { exact: true }).last().waitFor()
    await connect.click()
    if (process.env.HOWSTACK_VNC_DEBUG) { await page.waitForTimeout(500); console.log({ phases: rfb.phases, authenticated: rfb.authenticated, sockets: rfb.sockets.length, writes: writes.length, status: await page.getByRole('status').allTextContents(), alerts: await page.locator('.arco-message-content, .arco-toast').allTextContents(), closed: rfb.closed, errors }) }
    await page.getByRole('status').filter({ hasText: '已连接' }).waitFor()
    await page.waitForFunction(() => { const canvas = document.querySelector('[aria-label="远程屏幕"] canvas'); if (!(canvas instanceof HTMLCanvasElement)) return false; const pixel = canvas.getContext('2d')?.getImageData(10, 10, 1, 1).data; return pixel?.[0] === 25 && pixel?.[1] === 43 && pixel?.[2] === 71 })
    assert.equal(rfb.authenticated, 1, '真实noVNC必须通过VNC challenge-response认证')
    await page.getByRole('button', { name: 'Ctrl+Alt+Del', exact: true }).click()
    await page.waitForTimeout(100)
    assert.equal(sawKey(rfb, 0xffe3), true); assert.equal(sawKey(rfb, 0xffe9), true); assert.equal(sawKey(rfb, 0xffff), true)
    if (mobile) {
      await page.getByRole('button', { name: '键盘', exact: true }).click()
      await page.getByLabel('远程键盘输入', { exact: true }).fill('abc')
      await page.getByRole('button', { name: '回车', exact: true }).click()
      await page.waitForTimeout(100)
      assert.equal(sawKey(rfb, 0x61), true); assert.equal(sawKey(rfb, 0xff0d), true)
    } else {
      await page.getByRole('button', { name: 'Esc', exact: true }).click(); await page.waitForTimeout(100)
      assert.equal(sawKey(rfb, 0xff1b), true)
    }
    await page.getByText('VNC暂不可用，请稍后重试', { exact: true }).last().waitFor({ state: 'hidden' })
    await page.screenshot({ path: `/tmp/howstack-vnc-browser/${mobile ? 'mobile' : 'pc'}-${width}.png`, fullPage: true })
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false)
    const stored = await page.evaluate(() => JSON.stringify([location.href, { ...localStorage }, { ...sessionStorage }]))
    assert.equal(stored.includes('vncSecret') || stored.includes('fixture-2'), false)
    await page.getByRole('button', { name: mobile ? '关闭' : 'Close', exact: true }).click()
    await page.waitForTimeout(300)
    assert.equal(rfb.closed, 1, '关闭必须断开真实RFB连接')
    assert.deepEqual(errors, [])
    await context.close()
    console.log(`${mobile ? '移动' : 'PC'} ${width}px：失败反馈、手动连接、真实noVNC/RFB认证、画面、按键、凭据隔离、关闭断连和无溢出通过（模拟供应商）`)
  }
} finally { await browser.close() }
