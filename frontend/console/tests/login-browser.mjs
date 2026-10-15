import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
import { consoleBootstrap } from './console-bootstrap-fixture.mjs'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = (process.env.CONSOLE_BASE_URL ?? 'http://127.0.0.1:5176/console').replace(/\/$/, '')
const output = process.env.CONSOLE_SCREENSHOT_DIR ?? '/tmp/howstack-login-check'
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
const checks = []
const passed = name => { checks.push(name); console.log(`通过：${name}`) }
try {
  const context = await browser.newContext({ viewport: { width: 1536, height: 1024 } })
  let authenticated = false, sends = 0, smsLogins = 0, oauthCallbacks = 0
  let missing = false, smsFailure = false, expiresIn = 300, invalidOAuth = true
  let startProvider, smsBody
  const user = { id: '72846193', email: 'fixture@example.com', mobile: '13800138000', nickname: '测试会员' }
  await context.route('**/api/console/v1/**', async route => {
      if (new URL(route.request().url()).pathname.endsWith('/auth/options')) return route.fulfill({ json: { code: 0, message: 'ok', data: { sms_login: true, password_reset: { mobile: true, email: true }, oauth_providers: ['qq', 'wechat'] } } })
    const path = new URL(route.request().url()).pathname.replace('/api/console/v1', '')
    let status = 200, data = null, message = 'ok'
    if (path === '/bootstrap') data = consoleBootstrap
    else if (path === '/auth/me') { status = authenticated ? 200 : 401; data = authenticated ? { user } : null }
    else if (path === '/wallet') data = { currency: 'CNY', available_minor: 0, frozen_minor: 0 }
    else if (missing) status = 404
    else if (path === '/auth/login-code') { sends++; data = { challenge_id: `challenge-${sends}`, expires_in: expiresIn, retry_after: 60 } }
    else if (path === '/auth/login-sms') {
      smsLogins++; smsBody = route.request().postDataJSON()
      if (smsFailure) { status = 422; message = '验证码错误，请重新输入' }
      else { authenticated = true; data = { user } }
    } else if (path === '/auth/oauth/start') {
      startProvider = route.request().postDataJSON().provider
      data = { url: invalidOAuth ? 'https://invalid.example/authorize' : startProvider === 'qq'
        ? 'https://graph.qq.com/oauth2.0/authorize?state=fixture-state' : 'https://open.weixin.qq.com/connect/qrconnect?state=fixture-state' }
    } else if (path === '/auth/oauth/complete') {
      oauthCallbacks++; assert.deepEqual(route.request().postDataJSON(), { state: 'fixture-state', code: 'fixture-code' })
      authenticated = true; data = { status: 'authenticated', user }
    } else { status = 404 }
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ code: status === 200 ? 0 : status * 100, message, data }) })
  })
  // 授权页拦截为测试文档，不访问真实 QQ / 微信服务。
  await context.route(/https:\/\/(graph\.qq\.com|open\.weixin\.qq\.com)\//, route => route.fulfill({ contentType: 'text/html', body: '<p>模拟第三方授权页</p>' }))
  const page = await context.newPage()
  page.setDefaultTimeout(10000)
  const runtimeErrors = []
  page.on('pageerror', error => runtimeErrors.push(error.message))
  async function feedback(text) {
    await page.locator('.arco-message').getByText(text, { exact: true }).last().waitFor()
    assert.equal(await page.locator('.console-login-card').getByText(text, { exact: true }).count(), 0)
  }
  async function noOverflow() {
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
  }
  await page.goto(`${base}/finance/wallet?source=login#balance`)
  await page.waitForURL(`${base}/login`)
  await page.getByRole('heading', { name: '欢迎登录', exact: true }).waitFor()
  await noOverflow()
  await page.screenshot({ path: `${output}/login-desktop.png`, fullPage: true })
  const input = page.getByPlaceholder('请输入手机号或邮箱')
  assert.deepEqual(await input.evaluate(node => {
    const style = getComputedStyle(node.parentElement)
    return [style.backgroundColor, style.borderColor]
  }), ['rgb(255, 255, 255)', 'rgb(229, 230, 235)'])
  await input.focus()
  await page.waitForTimeout(150)
  assert.equal(await input.evaluate(node => getComputedStyle(node.parentElement).borderColor), 'rgb(22, 93, 255)')
  passed('桌面布局、白底浅灰边框与主题蓝聚焦')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.getByText('请输入手机号或邮箱', { exact: true }).waitFor()
  await page.getByText('请输入密码', { exact: true }).waitFor()
  await page.getByRole('tab', { name: '验证码登录', exact: true }).click()
  await page.getByRole('button', { name: '获取验证码', exact: true }).click()
  await page.getByText('请输入手机号', { exact: true }).waitFor()
  assert.equal(sends, 0)
  passed('密码与短信使用原生字段校验，非法手机号不请求发送')
  missing = true
  await page.getByPlaceholder('请输入手机号', { exact: true }).fill('13800138000')
  await page.getByRole('button', { name: '获取验证码', exact: true }).click()
  await feedback('该登录方式暂未接入，请使用密码登录')
  assert.equal(await page.getByRole('button', { name: '登录', exact: true }).isEnabled(), true)
  passed('缺少扩展接口时明确反馈，保留密码登录入口')
  missing = false
  await page.getByRole('button', { name: '获取验证码', exact: true }).click()
  await feedback('请求已受理，账号可验证时将收到短信')
  assert.equal(sends, 1)
  assert.equal(await page.getByRole('button', { name: /秒后重发/ }).isDisabled(), true)
  await page.getByPlaceholder('请输入验证码').fill('123456')
  await page.getByPlaceholder('请输入手机号', { exact: true }).fill('13900139000')
  await page.getByPlaceholder('请输入验证码').fill('123456')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.getByText('请先获取当前手机号的有效验证码', { exact: true }).waitFor()
  assert.equal(smsLogins, 0)
  passed('发送成功后倒计时防重复发送，改手机号使挑战失效')
  await page.setViewportSize({ width: 390, height: 844 })
  await noOverflow()
  await page.screenshot({ path: `${output}/login-sms-390.png`, fullPage: true })
  await page.getByRole('tab', { name: '密码登录', exact: true }).click()
  await page.getByPlaceholder('请输入手机号或邮箱').waitFor()
  await page.waitForTimeout(200)
  await page.screenshot({ path: `${output}/login-390.png`, fullPage: true })
  await page.setViewportSize({ width: 320, height: 740 })
  await noOverflow()
  passed('390px 与 320px 登录表单无横向溢出')
  // 刷新清除页面挑战与倒计时，模拟短有效期来验证过期阻止提交。
  await page.reload()
  await page.getByRole('tab', { name: '验证码登录', exact: true }).click()
  await page.getByPlaceholder('请输入手机号', { exact: true }).fill('13800138000')
  expiresIn = 1
  await page.getByRole('button', { name: '获取验证码', exact: true }).click()
  await feedback('请求已受理，账号可验证时将收到短信')
  await page.getByPlaceholder('请输入验证码').fill('123456')
  await page.waitForTimeout(1100)
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.getByText('请先获取当前手机号的有效验证码', { exact: true }).waitFor()
  assert.equal(smsLogins, 0)
  passed('验证码挑战过期后不提交登录')
  await page.reload()
  expiresIn = 300; smsFailure = true
  await page.getByRole('tab', { name: '验证码登录', exact: true }).click()
  await page.getByPlaceholder('请输入手机号', { exact: true }).fill('13800138000')
  await page.getByRole('button', { name: '获取验证码', exact: true }).click()
  await feedback('请求已受理，账号可验证时将收到短信')
  await page.getByPlaceholder('请输入验证码').fill('123456')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await feedback('验证码错误，请重新输入')
  assert.equal(smsLogins, 1)
  assert.deepEqual(smsBody, { mobile: '13800138000', code: '123456', challenge_id: 'challenge-3' })
  smsFailure = false
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.waitForURL(`${base}/finance/wallet?source=login#balance`)
  passed('模拟短信错误可重试，成功更新会话并恢复原路径')
  authenticated = false
  await page.goto(`${base}/finance/wallet?source=oauth#balance`)
  await page.waitForURL(`${base}/login`)
  for (const [name, provider] of [['QQ 登录', 'qq'], ['微信登录', 'wechat']]) {
    await page.getByRole('button', { name, exact: true }).click()
    await feedback('第三方授权地址无效')
    assert.equal(startProvider, provider)
    assert.equal(new URL(page.url()).pathname, '/console/login')
  }
  invalidOAuth = false
  await page.getByRole('button', { name: 'QQ 登录', exact: true }).click()
  await page.waitForURL('https://graph.qq.com/**')
  assert.equal(await page.evaluate(() => localStorage.getItem('console.oauth.return-path')), null)
  await page.goto(`${base}/login?state=fixture-state&code=fixture-code`)
  await page.waitForURL(`${base}/finance/wallet?source=oauth#balance`)
  assert.equal(oauthCallbacks, 1)
  assert.equal(await page.evaluate(() => sessionStorage.getItem('console.oauth.return-path')), null)
  passed('QQ / 微信使用独立提供者，拒绝异常地址，模拟授权恢复原路径且只消费一次回调')
  assert.deepEqual(runtimeErrors, [])
  passed('浏览器无运行时异常')
  console.log(JSON.stringify({ passed: checks.length, screenshots: output, evidence: '模拟接口，不涉及真实短信或第三方授权' }, null, 2))
  await context.close()
} finally { await browser.close() }
