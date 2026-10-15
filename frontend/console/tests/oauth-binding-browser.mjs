import assert from 'node:assert/strict'
import { consoleBootstrap } from './console-bootstrap-fixture.mjs'
const { chromium, expect } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
let scenarios = 0
const runtimeErrors = []
try {
  for (const mobile of [false, true]) {
    const base = `http://127.0.0.1:${mobile ? 5177 : 5176}/console`
    const context = await browser.newContext({ viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 1000 } })
    const page = await context.newPage()
    page.on('pageerror', error => runtimeErrors.push(error.message))
    let authenticated = false, bindWrites = 0, completions = 0, registered = 0, expired = false, failPassword = true, isBound = false
    const user = { id: '728461931', mobile: '13800138000', email: null, nickname: '关联测试会员' }
    await context.route('**/api/console/v1/**', async route => {
      const path = new URL(route.request().url()).pathname.replace('/api/console/v1', '')
      let data = null, status = 200, message = 'ok'
      if (path === '/bootstrap') data = consoleBootstrap
      else if (path === '/auth/options') data = { sms_login: false, password_reset: { mobile: false, email: false }, oauth_providers: ['qq', 'wechat'] }
      else if (path === '/auth/me') { status = authenticated ? 200 : 401; data = authenticated ? { user } : null }
      else if (path === '/auth/oauth/complete') {
        completions++
        assert.deepEqual(route.request().postDataJSON(), { state: 'a'.repeat(64), code: 'CODE' })
        if (isBound) { authenticated = true; data = { status: 'authenticated', user } }
        else data = { status: 'binding_required', provider: 'qq', expires_in: 600 }
      } else if (path === '/auth/oauth/pending') {
        if (expired) { status = 409; message = '关联请求已过期' }
        else data = { provider: 'qq', expires_in: 550 }
      } else if (path === '/auth/oauth/bind') {
        bindWrites++
        assert.equal(route.request().postDataJSON().account, '13800138000')
        if (failPassword) { status = 422; message = '请检查账号'; data = { errors: { password: ['测试密码校验失败'] } } }
        else { authenticated = true; data = { status: 'authenticated', user } }
      } else if (path === '/auth/registration') data = { mobile_enabled: 1, email_enabled: 0, mobile_verification: 0, email_verification: 0 }
      else if (path === '/auth/register') { registered++; data = { user } }
      else if (path === '/wallet') data = { currency: 'CNY', available_minor: 0, frozen_minor: 0 }
      else { status = 404; message = '接口不存在' }
      await route.fulfill({ status, json: { code: status === 200 ? 0 : status * 100, message, data } })
    })
    await context.addInitScript(() => sessionStorage.setItem('console.oauth.return-path', '/finance/wallet?purchase=kept#balance'))
    await page.goto(`${base}/login?code=CODE&state=${'a'.repeat(64)}`)
    await page.waitForURL(`${base}/oauth-bind`)
    await page.getByRole('heading', { name: '关联平台账号', exact: true }).waitFor()
    await page.getByRole('button', { name: '关联并登录', exact: true }).waitFor()
    assert.equal(completions, 1)
    assert.equal(new URL(page.url()).search, '')
    assert.equal(await page.evaluate(() => sessionStorage.getItem('console.oauth.return-path')), null)
    scenarios++
    const account = () => mobile ? page.getByRole('textbox', { name: '账号', exact: true }) : page.getByPlaceholder('请输入平台账号', { exact: true })
    const password = () => mobile ? page.getByLabel('密码', { exact: true }) : page.getByPlaceholder('请输入平台账号的密码', { exact: true })
    await page.getByRole('button', { name: '关联并登录', exact: true }).click()
    await expect(account()).toHaveValue('')
    await page.waitForTimeout(150)
    assert.equal(bindWrites, 0)
    await account().fill('13800138000'); await password().fill('wrong-password')
    await page.getByRole('button', { name: '关联并登录', exact: true }).click()
    await page.getByText('测试密码校验失败', { exact: true }).waitFor()
    if (mobile) assert.equal(await page.locator('.m-sr-only').getByText('测试密码校验失败', { exact: true }).count(), 0)
    else assert.equal(await page.locator('.arco-message').getByText('测试密码校验失败', { exact: true }).count(), 0)
    scenarios++
    await page.reload()
    await page.getByRole('button', { name: '关联并登录', exact: true }).waitFor()
    assert.equal(completions, 1)
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true)
    if (mobile) { await page.setViewportSize({ width: 320, height: 740 }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true) }
    scenarios++
    await page.getByRole('link', { name: '立即注册', exact: true }).click()
    await page.getByRole('button', { name: '注册', exact: true }).waitFor()
    await page.getByPlaceholder('请输入手机号', { exact: true }).fill('13800138000')
    await page.getByPlaceholder(mobile ? '请设置登录密码' : '请输入至少 8 个字符的密码', { exact: true }).fill('test-password')
    await page.getByPlaceholder('请再次输入密码', { exact: true }).fill('test-password')
    if (mobile) await page.getByRole('checkbox').click()
    else await page.getByText('我已阅读并同意', { exact: false }).click({ position: { x: 8, y: 8 } })
    await page.getByRole('button', { name: '注册', exact: true }).click()
    await page.waitForURL(`${base}/oauth-bind`)
    assert.equal(registered, 1); assert.equal(authenticated, false)
    scenarios++
    await account().fill('13800138000'); await password().fill('test-password')
    failPassword = false
    await page.getByRole('button', { name: '关联并登录', exact: true }).click()
    await page.waitForURL(`${base}/finance/wallet?purchase=kept#balance`)
    assert.equal(bindWrites, 2)
    scenarios++
    authenticated = false; expired = true
    await page.goto(`${base}/oauth-bind`)
    await page.getByRole('link', { name: '返回登录并重新授权', exact: true }).waitFor()
    assert.equal(bindWrites, 2)
    scenarios++
    expired = false; isBound = true
    await page.goto(`${base}/login?code=CODE&state=${'a'.repeat(64)}`)
    await page.waitForURL(`${base}/finance/wallet?purchase=kept#balance`)
    assert.equal(completions, 2)
    scenarios++
    await context.close()
  }
  assert.deepEqual(runtimeErrors, [])
  console.log(JSON.stringify({ scenarios, invalidBindRequests: 0, duplicateFieldFeedback: 0, realProviderCalls: 0 }))
} finally { await browser.close() }
