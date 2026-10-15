import assert from 'node:assert/strict'
import { consoleBootstrap } from './console-bootstrap-fixture.mjs'
const { chromium, expect } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright/test')
const browser = await chromium.launch({ headless: true })
let checks = 0
try {
  for (const mobile of [false, true]) {
    const base = mobile ? 'http://127.0.0.1:5177/console' : 'http://127.0.0.1:5176/console'
    const context = await browser.newContext({ viewport: { width: mobile ? 390 : 1440, height: 950 } })
    const a = { id: '728461931', mobile: '13800138001', email: null, nickname: '隔离会员甲' }
    const b = { id: '728461932', mobile: '13800138002', email: null, nickname: '隔离会员乙' }
    let current = a, late = false, release, captured
    const capturedPromise = new Promise(resolve => { captured = resolve })
    await context.route('**/api/console/v1/**', async route => {
      const path = new URL(route.request().url()).pathname.replace('/api/console/v1', '')
      let data = null, status = 200
      if (path === '/bootstrap') data = consoleBootstrap
      else if (path === '/auth/options') data = { sms_login: false, password_reset: { mobile: false, email: false }, oauth_providers: [] }
      else if (path === '/auth/me') { data = current ? { user: current } : null; if (!current) status = 401 }
      else if (path === '/wallet') {
        const owner = current
        if (late && owner?.id === a.id) { late = false; captured(); await new Promise(resolve => { release = resolve }) }
        data = { currency: 'CNY', available_minor: owner?.id === a.id ? 11103 : 22204, frozen_minor: 0 }
      } else if (path.includes('/messages')) data = { data: [], total: 0 }
      try { await route.fulfill({ status, json: { code: status === 200 ? 0 : 40100, message: 'ok', data } }) } catch { /* 账号边界中止的旧请求不能再投递。 */ }
    })
    const first = await context.newPage(), second = await context.newPage()
    await first.goto(`${base}/`); await expect(first.getByText('隔离会员甲', { exact: false }).first()).toBeVisible()
    await second.goto(`${base}/`); await expect(second.getByText('隔离会员甲', { exact: false }).first()).toBeVisible()
    late = true
    await first.reload()
    await capturedPromise
    current = b
    await second.evaluate(() => { localStorage.setItem('howstack.member-session', `test:${Math.random()}`); window.dispatchEvent(new Event('member:session-changed')) })
    await expect(first.getByText('隔离会员乙', { exact: false }).first()).toBeVisible()
    await expect(second.getByText('隔离会员乙', { exact: false }).first()).toBeVisible()
    release()
    await expect(first.locator('body')).not.toContainText('111.03')
    await expect(first.locator('body')).not.toContainText('隔离会员甲')
    await expect(first.locator('body')).toContainText('222.04')
    current = null
    await second.evaluate(() => { localStorage.setItem('howstack.member-session', `logout:${Math.random()}`); window.dispatchEvent(new Event('member:session-changed')) })
    await expect(first.getByRole('button', { name: '登录', exact: true })).toBeVisible()
    await expect(first.locator('body')).not.toContainText('隔离会员乙')
    await expect(first.locator('body')).not.toContainText('222.04')
    assert.equal(await first.evaluate(() => document.documentElement.scrollWidth > innerWidth), false)
    checks++
    await context.close()
  }
  console.log(JSON.stringify({ variants: checks, crossTabIsolation: 'passed', staleResponses: 'discarded', realWrites: 0 }))
} finally { await browser.close() }
