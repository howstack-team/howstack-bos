import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
import { consoleBootstrap } from './console-bootstrap-fixture.mjs'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = (process.env.CONSOLE_BASE_URL ?? 'http://127.0.0.1:5176/console').replace(/\/$/, '')
const output = process.env.CONSOLE_SCREENSHOT_DIR ?? '/tmp/howstack-login-check'
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
let checks = 0
const passed = name => { checks++; console.log(`通过：${name}`) }
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } })
  let missing = true, sends = 0, resets = 0, codeDelay = 0, resetFailure = false, resetBody, loginBody
  await context.route('**/api/console/v1/**', async route => {
      if (new URL(route.request().url()).pathname.endsWith('/auth/options')) return route.fulfill({ json: { code: 0, message: 'ok', data: { sms_login: true, password_reset: { mobile: true, email: true }, oauth_providers: ['qq', 'wechat'] } } })
    const path = new URL(route.request().url()).pathname.replace('/api/console/v1', '')
    let status = 200, data = null, message = 'ok'
    if (path === '/bootstrap') data = consoleBootstrap
    else if (path === '/auth/me') status = 401
    else if (path === '/auth/login') { loginBody = route.request().postDataJSON(); status = 422; message = '模拟账号不存在' }
    else if (missing) status = 404
    else if (path === '/auth/password-reset-code') {
      sends++; if (codeDelay) await new Promise(resolve => setTimeout(resolve, codeDelay))
      data = { challenge_id: `reset-${sends}`, expires_in: 300, retry_after: 60 }
    } else if (path === '/auth/password-reset') { resets++; resetBody = route.request().postDataJSON(); if (resetFailure) { status = 422; message = '重置验证码错误' } }
    else status = 404
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ code: status === 200 ? 0 : status * 100, message, data }) })
  })
  const page = await context.newPage(), runtimeErrors = []
  page.setDefaultTimeout(10000)
  page.on('pageerror', error => runtimeErrors.push(error.message))
  async function feedback(text) {
    await page.locator('.arco-message').getByText(text, { exact: true }).last().waitFor()
    assert.equal(await page.locator('.console-login-card').getByText(text, { exact: true }).count(), 0)
    assert.equal(await page.getByRole('dialog', { name: '找回密码' }).getByText(text, { exact: true }).count(), 0)
  }
  await page.goto(`${base}/login`)
  const remember = page.getByRole('checkbox', { name: '记住我' })
  await page.getByText('记住我', { exact: true }).click()
  assert.equal(await remember.isChecked(), true)
  await page.getByPlaceholder('请输入手机号或邮箱').fill('fixture@example.com')
  await page.getByPlaceholder('请输入密码', { exact: true }).fill('fixture-password')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await feedback('模拟账号不存在')
  assert.deepEqual(loginBody, { account: 'fixture@example.com', password: 'fixture-password', remember_me: true })
  assert.equal(await page.evaluate(() => JSON.stringify({ local: { ...localStorage }, session: { ...sessionStorage } }).includes('fixture-password')), false)
  await page.getByText('记住我', { exact: true }).click()
  assert.equal(await remember.isChecked(), false)
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.getByRole('button', { name: '登录', exact: true }).waitFor({ state: 'visible' })
  await page.waitForFunction(() => !document.querySelector('.console-login-card button[type="submit"]').disabled)
  assert.equal(Object.hasOwn(loginBody, 'remember_me'), false)
  passed('记住我仅提交会话选项，未勾选兼容原密码请求且不保存密码')
  const dialog = page.getByRole('dialog', { name: '找回密码' })
  async function open() {
    await page.getByRole('button', { name: '忘记密码？', exact: true }).click()
    await dialog.waitFor(); await page.waitForTimeout(350)
  }
  async function fillPassword() {
    await dialog.getByPlaceholder('请输入验证码').fill('123456')
    await dialog.getByPlaceholder('请输入至少 8 个字符的新密码').fill('new-fixture-password')
    await dialog.getByPlaceholder('请再次输入新密码').fill('new-fixture-password')
  }
  await open()
  await dialog.getByRole('button', { name: '重置密码', exact: true }).click()
  await dialog.getByText('请输入绑定的手机号或邮箱', { exact: true }).waitFor()
  assert.equal(sends, 0); assert.equal(resets, 0)
  await dialog.getByPlaceholder('请输入绑定的手机号或邮箱').fill('fixture@example.com')
  await dialog.getByRole('button', { name: '获取验证码', exact: true }).click()
  await feedback('密码找回暂未接入，请联系服务方协助处理')
  passed('忘记密码入口可操作，原生字段错误与待接入提示准确')
  missing = false
  await dialog.getByRole('button', { name: '获取验证码', exact: true }).click()
  await feedback('验证码已发送，请查收短信或邮件')
  assert.equal(await dialog.getByRole('button', { name: /秒后重发/ }).isDisabled(), true)
  await fillPassword()
  await dialog.getByPlaceholder('请再次输入新密码').fill('different-password')
  await dialog.getByRole('button', { name: '重置密码', exact: true }).click()
  await dialog.getByText('两次输入的密码不一致', { exact: true }).waitFor()
  assert.equal(resets, 0)
  await dialog.getByPlaceholder('请输入绑定的手机号或邮箱').fill('another@example.com')
  await fillPassword()
  await dialog.getByRole('button', { name: '重置密码', exact: true }).click()
  await dialog.getByText('请先获取当前账号的有效验证码', { exact: true }).waitFor()
  assert.equal(resets, 0)
  passed('重置挑战独立，倒计时、确认密码与账号变更校验正确')
  await page.keyboard.press('Escape')
  await dialog.waitFor({ state: 'hidden' })
  await open()
  assert.equal(await dialog.getByPlaceholder('请输入绑定的手机号或邮箱').inputValue(), '')
  await dialog.getByPlaceholder('请输入绑定的手机号或邮箱').fill('fixture@example.com')
  await dialog.getByRole('button', { name: '获取验证码', exact: true }).click()
  await feedback('验证码已发送，请查收短信或邮件')
  await fillPassword()
  resetFailure = true
  await dialog.getByRole('button', { name: '重置密码', exact: true }).click()
  await feedback('重置验证码错误')
  assert.equal(await dialog.getByPlaceholder('请输入验证码').inputValue(), '123456')
  resetFailure = false
  await dialog.getByRole('button', { name: '重置密码', exact: true }).click()
  await dialog.waitFor({ state: 'hidden' })
  await feedback('密码重置成功，请使用新密码登录')
  assert.equal(resets, 2)
  assert.deepEqual(resetBody, { account: 'fixture@example.com', code: '123456', challenge_id: 'reset-2', password: 'new-fixture-password' })
  passed('模拟重置成功后回到登录，不自动登录且不提交确认密码')
  await page.setViewportSize({ width: 390, height: 844 })
  await page.reload()
  await open()
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
  const rect = await dialog.boundingBox()
  assert.ok(rect.x >= 0 && rect.x + rect.width <= 390)
  await page.screenshot({ path: `${output}/forgot-password-390.png`, fullPage: true })
  await page.mouse.click(5, 200)
  await dialog.waitFor({ state: 'hidden' })
  passed('390px 找回密码布局无横向溢出，遮罩和 Escape 可关闭并清空表单')
  await open()
  codeDelay = 200
  await dialog.getByPlaceholder('请输入绑定的手机号或邮箱').fill('fixture@example.com')
  await dialog.getByRole('button', { name: '获取验证码', exact: true }).click()
  await page.keyboard.press('Escape')
  await dialog.waitFor({ state: 'hidden' })
  await open()
  await page.waitForTimeout(300)
  assert.equal(await dialog.getByText('验证码已发送，请查收短信或邮件', { exact: true }).count(), 0)
  assert.equal(await page.locator('.arco-message').getByText('验证码已发送，请查收短信或邮件', { exact: true }).count(), 0)
  assert.equal(await dialog.getByRole('button', { name: '获取验证码', exact: true }).isEnabled(), true)
  passed('关闭后的旧响应不会污染重新打开的找回密码表单')
  assert.deepEqual(runtimeErrors, [])
  console.log(JSON.stringify({ passed: checks, runtimeErrors, evidence: '模拟接口，不代表真实密码修改或会话延期' }, null, 2))
  await context.close()
} finally { await browser.close() }
