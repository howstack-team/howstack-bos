import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
import { consoleBootstrap } from './console-bootstrap-fixture.mjs'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
await mkdir('/tmp/howstack-service-operations-browser', { recursive: true })
try {
  for (const [mobile, width] of [[false, 1440], [true, 390], [true, 320]]) {
    const context = await browser.newContext({ viewport: { width, height: 960 }, isMobile: mobile, hasTouch: mobile,
      ...(mobile ? { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1' } : {}) })
    const page = await context.newPage(), errors = [], writes = []
    page.on('pageerror', error => errors.push(error.message))
    const ok = data => ({ json: { code: 0, message: 'ok', data, request_id: 'operations-fixture' } })
    const row = { id: 17, instance_no: 'bos-existing', display_name: '原云服务器', status: 'active', provisioning_status: 'succeeded', addresses: ['192.0.2.10'], activated_at: '2026-10-18 10:00:00', expires_at: '2027-11-18 10:00:00', created_at: '2026-10-18 10:00:00', order_id: 9, order_no: 'ORIGINAL-9', can_renew: true, initial_password_available: false }
    let failOnce = true, accepted = false
    await context.route('**/api/console/v1/**', async route => {
      const path = new URL(route.request().url()).pathname.replace('/api/console/v1', '')
      if (path === '/bootstrap') return route.fulfill(ok(consoleBootstrap))
      if (path === '/auth/me') return route.fulfill(ok({ user: { id: '728461931', mobile: '13800138000', email: '', nickname: '测试会员', balance_minor: 0 } }))
      if (path === '/services') return route.fulfill(ok({ data: [row], total: 1 }))
      if (path === '/services/17/operations') {
        if (route.request().method() === 'GET') return route.fulfill(ok({ vnc_available: false, actions: ['power_on', 'power_off', 'reboot', 'reinstall', 'reset_password'], busy: accepted, power_status: 'on', power_checked_at: '2026-10-18 10:00:00', images: [{ id: 7, name: 'Ubuntu 24.04', default_username: 'root', default_port: 22 }], history: accepted ? [{ id: 71, action: 'reinstall', status: 'waiting', error_code: null, created_at: '2026-10-18 12:00:00', updated_at: '2026-10-18 12:00:00' }] : [] }))
        writes.push(route.request().postDataJSON())
        if (failOnce) { failOnce = false; return route.fulfill({ status: 503, json: { code: 50300, message: '临时不可用，请重试', data: null, request_id: 'failure' } }) }
        accepted = true; return route.fulfill(ok({ id: 71, status: 'queued' }))
      }
      return route.fulfill(ok({ unread_count: 0, items: [] }))
    })
    await page.goto(`http://127.0.0.1:${mobile ? 5177 : 5176}/console/services/cloud-servers`)
    await page.getByText('管理', { exact: true }).click()
    await page.getByText('重装系统', { exact: true }).click()
    const submit = page.getByText('确认提交', { exact: true }).last()
    await submit.click()
    await page.waitForTimeout(250)
    assert.equal(writes.length, 0, '字段未通过不能请求后端')
    assert.equal(await page.getByText('form validate error', { exact: false }).count(), 0)
    if (mobile) {
      await page.getByText('Ubuntu 24.04', { exact: true }).click()
      await page.getByLabel('新密码', { exact: true }).fill('Test@123456')
      await page.getByLabel('确认密码', { exact: true }).fill('Test@123456')
      await page.getByText('我已了解操作影响并做好数据备份', { exact: true }).click()
    } else {
      await page.locator('.arco-modal .arco-select').click()
      await page.getByText('Ubuntu 24.04（root / 22）', { exact: true }).last().click()
      const inputs = page.locator('.arco-modal input[type="password"]')
      await inputs.nth(0).fill('Test@123456'); await inputs.nth(1).fill('Test@123456')
      await page.getByText('我已了解操作影响，并已备份系统盘数据', { exact: true }).click()
    }
    await page.screenshot({ path: `/tmp/howstack-service-operations-browser/${mobile ? 'mobile' : 'pc'}-${width}.png`, fullPage: true })
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false)
    await submit.click()
    await page.getByText('临时不可用，请重试', { exact: true }).last().waitFor()
    await submit.click()
    await page.getByText('操作已受理，请等待供应商确认结果', { exact: true }).last().waitFor()
    await page.getByText('重装系统 · 等待供应商确认', { exact: true }).waitFor()
    assert.equal(writes.length, 2); assert.deepEqual(writes[0], writes[1], '失败重试必须使用相同请求键')
    assert.equal(writes[0].action, 'reinstall'); assert.equal(writes[0].image_id, 7); assert.equal(writes[0].password, 'Test@123456')
    assert.equal(await page.locator('input[type="password"]').count(), 0, '受理后销毁密码输入')
    await page.getByText('关闭', { exact: true }).last().click()
    await page.getByText('管理', { exact: true }).click()
    await page.getByText('重装系统 · 等待供应商确认', { exact: true }).waitFor()
    await page.mouse.click(5, 80)
    await page.getByText('云服务器管理', { exact: true }).waitFor({ state: 'hidden' })
    assert.deepEqual(errors, [])
    await context.close()
    console.log(`${mobile ? '移动' : 'PC'} ${width}px：校验拦截、重装确认、失败原键重试、密码销毁、任务显示和无溢出通过（模拟接口）`)
  }
} finally { await browser.close() }
