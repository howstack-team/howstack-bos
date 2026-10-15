import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
import { consoleBootstrap } from './console-bootstrap-fixture.mjs'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.CONSOLE_BASE_URL ?? 'http://127.0.0.1:5176/console'
const output = '/tmp/howstack-registration-browser'; await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
const user = { id: '72846193', nickname: '会员', mobile: null, email: 'user@example.com' }
const writes = [], errors = []
async function context(options, width, responses = {}) {
  const context = await browser.newContext({ viewport: { width, height: 900 } })
  await context.route('**/api/console/v1/**', async route => {
      if (new URL(route.request().url()).pathname.endsWith('/auth/options')) return route.fulfill({ json: { code: 0, message: 'ok', data: { sms_login: true, password_reset: { mobile: true, email: true }, oauth_providers: ['qq', 'wechat'] } } })
    const path = new URL(route.request().url()).pathname.replace('/api/console/v1', '')
    let status = 200, data = null, message = 'ok'
    if (path === '/bootstrap') data = consoleBootstrap
    if (path === '/auth/me') status = 401
    if (path === '/auth/registration') { data = options; status = responses.loadStatus ?? 200; if (status !== 200) message = '注册设置暂不可用' }
    if (path === '/auth/registration-code') { data = { challenge_id: 'a'.repeat(32), expires_in: 300, retry_after: 60 }; status = responses.codeStatus ?? 200; if (status !== 200) message = '验证码发送过于频繁' }
    if (path === '/auth/register') { writes.push(route.request().postDataJSON()); data = { user }; status = responses.registerStatus ?? 200; if (status !== 200) message = '该账号已注册' }
    await route.fulfill({ status, json: { code: status === 200 ? 0 : status * 100, message, data, request_id: 'registration-mock' } })
  })
  const page = await context.newPage(); page.setDefaultTimeout(8000); page.on('pageerror', error => errors.push(error.message))
  return [context, page]
}
async function noOverflow(page) { assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)) }
async function feedback(page, text) {
  await page.locator('.arco-message').getByText(text, { exact: true }).last().waitFor()
  assert.equal(await page.locator('.console-login-card').getByText(text, { exact: true }).count(), 0)
}
try {
  for (const width of [1440, 390]) {
    const [ctx, page] = await context({ mobile_enabled: 1, email_enabled: 1, mobile_verification: 1, email_verification: 1 }, width)
    await page.goto(`${base}/register`)
    await page.getByRole('tab', { name: '邮箱注册' }).click()
    await page.getByPlaceholder('请输入邮箱', { exact: true }).fill('user@example.com')
    await page.getByRole('button', { name: '获取验证码' }).click()
    await feedback(page, '验证码已发送，请查收邮件')
    await page.getByPlaceholder('请输入验证码', { exact: true }).fill('123456')
    await page.getByPlaceholder('请输入至少 8 个字符的密码').fill('test-password')
    await page.getByPlaceholder('请再次输入密码').fill('test-password')
    const agreement = page.getByRole('checkbox', { name: /我已阅读并同意/ })
    assert.equal(await agreement.isChecked(), false)
    const beforeConsent = writes.length
    await page.getByRole('button', { name: '注册', exact: true }).click()
    await page.getByText('请阅读并同意服务协议和隐私政策', { exact: true }).waitFor()
    assert.equal(writes.length, beforeConsent)
    await page.getByRole('link', { name: '《服务协议》', exact: true }).click()
    await page.getByRole('link', { name: '《隐私政策》', exact: true }).click()
    assert.equal(await agreement.isChecked(), false)
    assert.equal(await page.getByPlaceholder('请输入邮箱', { exact: true }).inputValue(), 'user@example.com')
    await page.getByText('我已阅读并同意', { exact: false }).click({ position: { x: 8, y: 8 } })
    assert.equal(await agreement.isChecked(), true)
    await noOverflow(page); await page.screenshot({ path: `${output}/email-${width}.png`, fullPage: true })
    await page.getByRole('button', { name: '注册', exact: true }).click()
    await page.waitForURL(`${base}/login`)
    await feedback(page, '注册成功，请登录')
    assert.equal(writes.at(-1).method, 'email'); assert.equal(writes.at(-1).challenge_id, 'a'.repeat(32))
    await ctx.close()
  }
  const [ctx, page] = await context({ mobile_enabled: 1, email_enabled: 0, mobile_verification: 0, email_verification: 1 }, 390)
  await page.goto(`${base}/register`); await page.getByPlaceholder('请输入手机号', { exact: true }).fill('13800138000')
  assert.equal(await page.getByPlaceholder('请输入验证码', { exact: true }).count(), 0)
  await page.getByPlaceholder('请输入至少 8 个字符的密码').fill('test-password')
  await page.getByText('我已阅读并同意', { exact: false }).click({ position: { x: 8, y: 8 } })
  await page.getByPlaceholder('请再次输入密码').fill('different-password')
  await page.getByRole('button', { name: '注册', exact: true }).click()
  await page.getByText('两次输入的密码不一致', { exact: true }).waitFor()
  const before = writes.length
  await page.getByPlaceholder('请再次输入密码').fill('test-password')
  await page.getByRole('button', { name: '注册', exact: true }).click(); await page.waitForURL(`${base}/login`)
  assert.equal(writes.length, before + 1); assert.equal(writes.at(-1).code, ''); assert.equal(writes.at(-1).challenge_id, '')
  await ctx.close()
  const [closed, closedPage] = await context({ mobile_enabled: 0, email_enabled: 0, mobile_verification: 1, email_verification: 1 }, 1440)
  await closedPage.goto(`${base}/register`); await feedback(closedPage, '暂未开放会员注册，请联系服务方。')
  assert.equal(await closedPage.getByRole('button', { name: '注册', exact: true }).count(), 0); await closed.close()
  const responses = { loadStatus: 503, registerStatus: 422 }
  const [recovery, recoveryPage] = await context({ mobile_enabled: 1, email_enabled: 0, mobile_verification: 0, email_verification: 0 }, 390, responses)
  await recoveryPage.goto(`${base}/register`); await feedback(recoveryPage, '注册设置暂不可用')
  assert.equal(await recoveryPage.getByRole('button', { name: '注册', exact: true }).count(), 0)
  responses.loadStatus = 200
  await recoveryPage.getByRole('button', { name: '重试', exact: true }).click()
  await recoveryPage.getByPlaceholder('请输入手机号', { exact: true }).fill('13800138000')
  await recoveryPage.getByPlaceholder('请输入至少 8 个字符的密码').fill('test-password')
  await recoveryPage.getByPlaceholder('请再次输入密码').fill('test-password')
  await recoveryPage.getByText('我已阅读并同意', { exact: false }).click({ position: { x: 8, y: 8 } })
  await recoveryPage.getByRole('button', { name: '注册', exact: true }).click()
  await feedback(recoveryPage, '该账号已注册')
  assert.equal(await recoveryPage.getByPlaceholder('请输入手机号', { exact: true }).inputValue(), '13800138000')
  assert.equal(await recoveryPage.getByPlaceholder('请输入至少 8 个字符的密码').inputValue(), 'test-password')
  await noOverflow(recoveryPage)
  await recoveryPage.screenshot({ path: `${output}/feedback-390.png`, fullPage: true })
  responses.registerStatus = 200
  await recoveryPage.getByRole('button', { name: '注册', exact: true }).click(); await recoveryPage.waitForURL(`${base}/login`)
  await recovery.close()
  const [codeFailure, codeFailurePage] = await context({ mobile_enabled: 0, email_enabled: 1, mobile_verification: 0, email_verification: 1 }, 1440, { codeStatus: 429 })
  await codeFailurePage.goto(`${base}/register`)
  await codeFailurePage.getByPlaceholder('请输入邮箱', { exact: true }).fill('user@example.com')
  await codeFailurePage.getByRole('button', { name: '获取验证码', exact: true }).click()
  await feedback(codeFailurePage, '验证码发送过于频繁'); await codeFailure.close()
  assert.deepEqual(errors, [])
  console.log(JSON.stringify({ passed: true, widths: [1440, 390], consentValidation: true, mockRegistrations: writes.length, realWrites: 0, screenshots: output }))
} finally { await browser.close() }
