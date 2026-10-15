import assert from 'node:assert/strict'
import { consoleBootstrap } from './console-bootstrap-fixture.mjs'
const { chromium, expect } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright/test')
const browser = await chromium.launch({ headless: true })
const errors = []
let scenarios = 0
try {
  for (const mobile of [false, true]) for (const kind of ['password', 'sms', 'register-mobile', 'register-email', 'reset-mobile', 'reset-email']) {
    const base = mobile ? process.env.CONSOLE_MOBILE_URL ?? 'http://127.0.0.1:5177/console' : process.env.CONSOLE_BASE_URL ?? 'http://127.0.0.1:5176/console'
    console.log(`验证 ${mobile ? 'Mobile' : 'PC'} / ${kind}`)
    const context = await browser.newContext({ viewport: { width: mobile ? 390 : 1440, height: 1000 } })
    const page = await context.newPage(); page.setDefaultTimeout(8000); page.on('pageerror', error => errors.push(error.message))
    const writes = []; let responseMode = 'fields'
    const registration = kind.startsWith('register'), reset = kind.startsWith('reset'), passwordLogin = kind === 'password'
    const serverFields = passwordLogin ? { password: ['后端字段：密码错误'] } : { challenge_id: ['后端字段：验证码错误'] }
    await context.route('**/api/console/v1/**', async route => {
      if (new URL(route.request().url()).pathname.endsWith('/auth/options')) return route.fulfill({ json: { code: 0, message: 'ok', data: { sms_login: true, password_reset: { mobile: true, email: true }, oauth_providers: ['qq', 'wechat'] } } })
      const path = new URL(route.request().url()).pathname.replace('/api/console/v1', '')
      let status = 200, message = 'ok', data = null
      if (route.request().method() === 'POST') writes.push({ path, input: route.request().postDataJSON() })
      if (path === '/bootstrap') data = { ...consoleBootstrap, theme: { ...consoleBootstrap.theme, type: mobile ? 'mobile' : 'pc' } }
      else if (path === '/auth/me') { status = 401; message = '请登录' }
      else if (path === '/auth/registration') data = { mobile_enabled: 1, email_enabled: 1, mobile_verification: 1, email_verification: 1 }
      else if (['/auth/login-code', '/auth/registration-code', '/auth/password-reset-code'].includes(path)) data = { challenge_id: 'a'.repeat(32), expires_in: 300, retry_after: 60 }
      else if (route.request().method() === 'POST') {
        if (responseMode === 'network') { await route.abort('failed'); return }
        status = responseMode === 'fields' ? 422 : 409; message = responseMode === 'fields' ? '参数校验失败' : '模拟业务失败'
        data = responseMode === 'fields' ? { errors: serverFields } : null
      } else { await route.abort(); return }
      await route.fulfill({ status, json: { code: status === 200 ? 0 : status * 100, message, data, request_id: 'auth-feedback-fixture' } })
    })
    await page.goto(`${base}/${registration ? 'register' : reset && mobile ? 'forgot-password' : 'login'}`)
    if (reset && !mobile) await page.getByRole('button', { name: '忘记密码？' }).click()
    if (kind === 'sms') await page.getByText('验证码登录', { exact: true }).click()
    if (kind === 'register-email') await page.getByText('邮箱注册', { exact: true }).click()
    const account = page.getByPlaceholder(passwordLogin ? '请输入手机号或邮箱' : reset ? '请输入绑定的手机号或邮箱' : kind.endsWith('email') ? '请输入邮箱' : '请输入手机号', { exact: true })
    await account.waitFor()
    const submit = page.getByRole('button', { name: registration ? '注册' : reset ? '重置密码' : '登录', exact: true })
    const submitted = () => writes.filter(row => !row.path.endsWith('-code')).length
    const noGlobalFieldError = async () => {
      if (mobile) assert.ok(!/后端字段|参数校验失败|请输入|两次输入|请阅读/.test(await page.locator('.m-sr-only').innerText()))
      else await expect(page.locator('.arco-message').filter({ hasText: /后端字段|参数校验失败|请输入|两次输入|请阅读/ })).toHaveCount(0)
    }
    await submit.click(); await noGlobalFieldError(); assert.equal(writes.length, 0)
    await account.press('Enter'); await noGlobalFieldError(); assert.equal(writes.length, 0)
    if (!passwordLogin) {
      const send = page.getByRole('button', { name: '获取验证码', exact: true })
      await send.click(); assert.equal(writes.length, 0)
      await account.fill('invalid'); await send.click(); assert.equal(writes.length, 0)
    }
    await account.fill(kind.endsWith('email') || passwordLogin ? 'fixture@example.com' : '13800138000')
    if (!passwordLogin) {
      await page.getByRole('button', { name: '获取验证码', exact: true }).click(); await expect.poll(() => writes.length).toBe(1)
      const code = page.getByPlaceholder('请输入验证码', { exact: true })
      await code.fill('12'); await submit.click(); assert.equal(submitted(), 0); await code.fill('123456')
    }
    if (passwordLogin) await page.getByPlaceholder('请输入密码', { exact: true }).fill('Test@123456')
    if (registration || reset) {
      const password = page.getByPlaceholder(reset ? mobile ? '请输入新密码' : '请输入至少 8 个字符的新密码' : mobile ? '请设置登录密码' : '请输入至少 8 个字符的密码', { exact: true })
      const confirm = page.getByPlaceholder(reset ? '请再次输入新密码' : '请再次输入密码', { exact: true })
      await password.fill('Test@123456'); await confirm.fill('Different@123'); await submit.click()
      assert.equal(submitted(), 0); await noGlobalFieldError(); await confirm.fill('Test@123456')
      if (registration) { await submit.click(); assert.equal(submitted(), 0); await noGlobalFieldError(); if (mobile) await page.getByRole('checkbox', { name: /我已阅读并同意/ }).click(); else { await page.getByText('我已阅读并同意', { exact: false }).click({ position: { x: 8, y: 8 } }); assert.equal(await page.getByRole('checkbox', { name: /我已阅读并同意/ }).isChecked(), true) } }
    }
    if (passwordLogin) {
      const passwordInput = page.getByPlaceholder('请输入密码', { exact: true })
      await passwordInput.fill('密'.repeat(25))
      await submit.click()
      assert.equal(submitted(), 0, '超过72个UTF-8字节的密码不能请求后端')
      await passwordInput.fill('Test@123456')
    }
    await submit.click(); await expect(page.getByText(Object.values(serverFields)[0][0], { exact: true })).toHaveCount(1)
    assert.equal(submitted(), 1); await noGlobalFieldError()
    responseMode = 'business'; await submit.click()
    if (mobile) await expect(page.locator('.m-sr-only')).toHaveText('模拟业务失败')
    else await expect(page.locator('.arco-message').getByText('模拟业务失败', { exact: true })).toHaveCount(1)
    assert.equal(submitted(), 2)
    responseMode = 'network'; await submit.click()
    if (mobile) await expect(page.locator('.m-sr-only')).toHaveText('请求失败，请检查网络后重试')
    else await expect(page.locator('.arco-message').getByText('请求失败，请检查网络后重试', { exact: true })).toHaveCount(1)
    assert.equal(submitted(), 3); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false)
    await context.close(); scenarios++
  }
  for (const mobile of [false, true]) {
    const base = mobile ? process.env.CONSOLE_MOBILE_URL ?? 'http://127.0.0.1:5177/console' : process.env.CONSOLE_BASE_URL ?? 'http://127.0.0.1:5176/console'
    const context = await browser.newContext({ viewport: { width: mobile ? 390 : 1440, height: 1000 } })
    const page = await context.newPage(); page.on('pageerror', error => errors.push(error.message))
    await context.route('**/api/console/v1/**', async route => {
      if (new URL(route.request().url()).pathname.endsWith('/auth/options')) return route.fulfill({ json: { code: 0, message: 'ok', data: { sms_login: true, password_reset: { mobile: true, email: true }, oauth_providers: ['qq', 'wechat'] } } })
      const bootstrap = new URL(route.request().url()).pathname.endsWith('/bootstrap')
      await route.fulfill({ status: bootstrap ? 200 : 503, json: { code: bootstrap ? 0 : 50300, message: bootstrap ? 'ok' : '会话加载失败', data: bootstrap ? consoleBootstrap : null } })
    })
    await page.goto(`${base}/login`)
    await page.getByRole('button', { name: '重新加载', exact: true }).waitFor()
    if (mobile) await expect(page.locator('.m-sr-only')).toHaveText('会话加载失败')
    else await expect(page.locator('.arco-message').getByText('会话加载失败', { exact: true })).toHaveCount(1)
    await expect(page.locator('main').getByText('会话加载失败', { exact: true })).toHaveCount(0)
    await context.close(); scenarios++
  }
  assert.deepEqual(errors, [])
  console.log(JSON.stringify({ project: 'howstack-bos', scenarios, invalidWrites: 0, duplicateFieldFeedback: 0, realWrites: 0 }))
} finally { await browser.close() }
