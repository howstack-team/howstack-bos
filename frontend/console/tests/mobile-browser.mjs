import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
import { consoleBootstrap } from './console-bootstrap-fixture.mjs'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.CONSOLE_MOBILE_URL ?? 'http://127.0.0.1:5177/console'
const output = process.env.CONSOLE_SCREENSHOT_DIR ?? '/tmp/howstack-mobile-theme'
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
let currentPage
const errors = []
try {
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true,
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' })
  const page = await context.newPage()
  currentPage = page
  page.on('pageerror', error => errors.push(error.message))
  page.setDefaultTimeout(10000)
  let authenticated = false, loginFailure = false, logoutFailure = false, walletExpired = false
  let registrationVerification = 1, sentCode = 0, smsLogins = 0, passwordLogins = 0, registrations = 0, unread = true
  let brand = '移动端测试平台'
  const user = { id: '728461931', mobile: '13800138000', email: 'test@example.com', nickname: '测试会员' }
  const messages = [{ id: 1, title: '服务开通成功', body: '您的服务已开通。\n' + '详细说明。'.repeat(300), unread: true, created_at: '2026-10-11 10:00:00' }]
  await context.route('**/api/console/v1/**', async route => {
      if (new URL(route.request().url()).pathname.endsWith('/auth/options')) return route.fulfill({ json: { code: 0, message: 'ok', data: { sms_login: true, password_reset: { mobile: true, email: true }, oauth_providers: ['qq', 'wechat'] } } })
    const path = new URL(route.request().url()).pathname.replace('/api/console/v1', '')
    let data = null, status = 200, message = 'ok'
    if (path === '/bootstrap') data = { ...consoleBootstrap, theme: { id: 'default', version: '2.0.0', type: 'mobile' }, site: { ...consoleBootstrap.site, name: brand, logo: '' }, capabilities: ['theme.presentation.split'] }
    else if (path === '/auth/me') { if (authenticated) data = { user }; else { status = 401; message = '请登录' } }
    else if (path === '/auth/login') {
      passwordLogins++
      if (loginFailure) { status = 401; message = '账号或密码错误' } else { authenticated = true; data = { user } }
    } else if (path === '/auth/logout') {
      if (logoutFailure) { status = 500; message = '退出暂时失败' } else authenticated = false
    } else if (path === '/wallet') { if (walletExpired) { status = 401; authenticated = false; message = '会话已过期' } else data = { currency: 'CNY', available_minor: 325050, frozen_minor: 12000 } }
    else if (path === '/auth/registration') data = { mobile_enabled: 1, email_enabled: 1, mobile_verification: registrationVerification, email_verification: registrationVerification }
    else if (path === '/auth/login-code' || path === '/auth/registration-code') { sentCode++; data = { challenge_id: 'a'.repeat(32), expires_in: 300, retry_after: 60 } }
    else if (path === '/auth/login-sms') { smsLogins++; authenticated = true; data = { user } }
    else if (path === '/auth/register') {
      registrations++
      const input = route.request().postDataJSON()
      assert.equal(input.method, 'email'); assert.equal(input.account, 'new@example.com')
      assert.equal(input.challenge_id, 'a'.repeat(32)); assert.equal(input.code, '123456'); data = { user }
    } else if (path === '/messages') data = { data: messages.map(row => ({ ...row, unread })), total: 1 }
    else if (path === '/messages/1/read' || path === '/messages/read-all') { unread = false; data = {} }
    else { status = 404; message = '未接入' }
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ code: status === 200 ? 0 : 1001, message, data }) })
  })
  const fit = async () => {
    await page.waitForFunction(() => Math.abs(parseFloat(getComputedStyle(document.documentElement).fontSize) - Math.min(58, innerWidth / 375 * 50)) < 0.1)
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, '不应横向溢出')
  }
  const bottomNavigation = async (checkOverlap = false) => {
    const nav = page.locator('.m-bottom-nav')
    const bounds = await nav.boundingBox()
    const viewport = page.viewportSize()
    assert.ok(bounds && viewport && Math.abs(bounds.y + bounds.height - viewport.height) < 1, '菜单应固定在当前可见区域底部')
    assert.equal(await nav.evaluate(node => getComputedStyle(node).backgroundColor), 'rgb(255, 255, 255)', '菜单背景应不透明，不能透出页面内容')
    if (checkOverlap) {
      // 缩短可见区域后将账户资料滚动到菜单后面，复现手机浏览器工具栏展开时的截图。
      const account = page.getByRole('button', { name: '账户资料', exact: true })
      await account.evaluate((node, center) => {
        const box = node.getBoundingClientRect()
        window.scrollBy(0, box.y + box.height / 2 - center)
      }, bounds.y + bounds.height / 2)
      const accountBounds = await account.boundingBox()
      assert.ok(accountBounds && accountBounds.y < bounds.y + bounds.height && accountBounds.y + accountBounds.height > bounds.y, '应覆盖账户内容经过菜单下方的场景')
      await page.screenshot({ path: `${output}/overview-navigation-overlap.png` })
    }
    assert.equal(await nav.evaluate(node => {
      const box = node.getBoundingClientRect()
      return node.contains(document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2))
    }), true, '菜单必须位于正文前方并能接收点击')
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight))
    const lastAction = await page.getByRole('button', { name: '安全设置', exact: true }).boundingBox()
    assert.ok(lastAction && lastAction.y + lastAction.height <= bounds.y, '滚动到底后最后一项操作必须完整显示在菜单上方')
    await page.evaluate(() => window.scrollTo(0, 0))
  }
  const codeLayout = async () => {
    const input = await page.getByRole('textbox', { name: '验证码', exact: true }).boundingBox()
    const button = await page.locator('.m-code-button').boundingBox()
    const row = await page.locator('.m-field-input').filter({ has: page.locator('.m-code-button') }).boundingBox()
    assert.ok(input && button && row)
    assert.ok(input.width >= 90, '验证码输入区应足够展示 6 位验证码')
    assert.ok(input.x + input.width <= button.x, '验证码按钮不能覆盖输入文字或清除按钮')
    assert.ok(button.x + button.width <= row.x + row.width + 1, '验证码按钮不能越过表单边缘')
    assert.ok(Math.abs(input.y + input.height / 2 - button.y - button.height / 2) < 2, '验证码按钮应在输入行内垂直居中')
    const field = await page.locator('.m-field').filter({ has: page.locator('.m-code-button') }).boundingBox()
    assert.ok(field && button.y >= field.y && button.y + button.height <= field.y + field.height + 1, '默认尺寸的验证码按钮应完整位于表单行内')
    const heading = await page.locator('.m-auth-intro h1').boundingBox()
    const submit = await page.locator('.m-auth-submit').boundingBox()
    assert.ok(heading && submit && Math.abs(heading.x - field.x) < 1 && Math.abs(submit.x - field.x) < 1, '页面标题、输入区和主按钮应沿同一条左边线对齐')
    assert.ok(Math.abs(submit.width - field.width) < 1, '主按钮与输入区应保持相同宽度')
  }
  const signIn = async () => {
    await page.getByRole('textbox', { name: '账号', exact: true }).fill('13800138000')
    await page.getByLabel('密码', { exact: true }).fill('Test@123456')
    await page.getByRole('button', { name: '登录', exact: true }).click()
  }
  const maskedPassword = async label => {
    const input = page.getByLabel(label, { exact: true })
    await input.fill('Test@123456')
    assert.equal(await input.getAttribute('type'), 'password', `${label}始终遮蔽`)
    assert.equal(await page.getByRole('button', { name: new RegExp(`^(显示|隐藏)${label}$`) }).count(), 0, '密码不提供显示隐藏后缀')
    assert.equal(await input.inputValue(), 'Test@123456', '密码输入值保持完整')
  }
  await page.goto(`${base}/finance/wallet?from=check#balance`)
  await page.getByRole('heading', { name: '欢迎登录' }).waitFor()
  assert.equal(await page.title(), '会员登录 - 移动端测试平台')
  for (const width of [320, 390, 430, 768]) { await page.setViewportSize({ width, height: 844 }); await fit() }
  await maskedPassword('密码')
  await page.getByRole('textbox', { name: '账号', exact: true }).fill('13800138000')
  await page.getByLabel('密码', { exact: true }).fill('a'.repeat(73))
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.getByText('请输入密码，最多 72 个字符', { exact: true }).first().waitFor()
  assert.equal(passwordLogins, 0, '超过密码长度上限不能发起登录请求')
  assert.equal((await page.getByLabel('密码', { exact: true }).inputValue()).length, 73, '超长密码应提示错误，不静默截断')
  await page.getByLabel('密码', { exact: true }).fill('a'.repeat(72))
  assert.equal((await page.getByLabel('密码', { exact: true }).inputValue()).length, 72, '长度上限内的密码保持完整')
  // 只为排版扩展注入临时入口，验证六种登录方式能换行；不增加实际授权提供方。
  await page.setViewportSize({ width: 320, height: 844 }); await fit()
  const multipleProviders = await page.locator('.m-social').evaluate(container => {
    const template = container.firstElementChild
    const additional = Array.from({ length: 4 }, () => template.cloneNode(true))
    additional.forEach(item => container.appendChild(item))
    try {
      const bounds = container.getBoundingClientRect()
      const items = Array.from(container.children, item => item.getBoundingClientRect())
      return { fits: items.every(item => item.left >= bounds.left && item.right <= bounds.right + 1), rows: new Set(items.map(item => item.top)).size }
    } finally { additional.forEach(item => item.remove()) }
  })
  assert.ok(multipleProviders.fits && multipleProviders.rows > 1, '多于两种登录方式应自然换行且不越过表单边界')
  assert.equal(await page.locator('.m-auth-brand img').evaluate(img => getComputedStyle(img).backgroundColor), 'rgba(0, 0, 0, 0)', 'Logo 不应添加不透明底板')
  const registrationLink = await page.getByRole('link', { name: '立即注册' }).boundingBox()
  const mainSubmit = await page.getByRole('button', { name: '登录', exact: true }).boundingBox()
  const socialHeading = await page.locator('.m-social-heading').boundingBox()
  assert.ok(registrationLink && mainSubmit && socialHeading && registrationLink.y >= mainSubmit.y + mainSubmit.height && registrationLink.y + registrationLink.height <= socialHeading.y, '注册提醒应位于主登录按钮下方、其他登录方式上方')
  // 三个用途都验证实际输入/按钮几何；仅检查页面不溢出会遗漏按钮错位。
  await page.getByText('验证码登录', { exact: true }).click()
  for (const width of [320, 390, 430]) {
    await page.setViewportSize({ width, height: 844 }); await fit(); await codeLayout()
    await page.screenshot({ path: `${output}/login-code-${width}.png`, fullPage: true })
  }
  await page.getByRole('link', { name: '立即注册' }).click()
  await page.getByRole('heading', { name: '创建账号' }).waitFor()
  await page.getByRole('textbox', { name: '手机号', exact: true }).waitFor()
  for (const width of [320, 390, 430]) {
    await page.setViewportSize({ width, height: 844 }); await fit(); await codeLayout()
    const agreement = page.locator('.m-agreement-control')
    for (const name of ['《服务协议》', '《隐私政策》']) {
      assert.equal(await agreement.locator('.m-agreement-choice span').evaluate(node => getComputedStyle(node).fontSize),
        await agreement.getByRole('button', { name }).evaluate(node => getComputedStyle(node).fontSize), '勾选文案与协议链接字号保持一致')
    }
    await page.screenshot({ path: `${output}/register-empty-${width}.png`, fullPage: true })
  }
  await page.setViewportSize({ width: 320, height: 844 })
  for (const name of ['服务协议', '隐私政策']) {
    await page.getByRole('button', { name: `《${name}》`, exact: true }).click()
    assert.equal(await page.getByRole('checkbox', { name: '我已阅读并同意', exact: true }).getAttribute('aria-checked'), 'false', '点击协议不应隐式勾选')
    const document = page.getByRole('document', { name, exact: true })
    await document.getByText(`${name}尚未发布，请联系平台获取后再确认。`, { exact: true }).waitFor()
    assert.equal(await page.locator('form').getByText(`${name}尚未发布，请联系平台获取后再确认。`, { exact: true }).count(), 0, '协议内容只在弹窗展示')
    await fit()
    await page.waitForFunction(() => document.getAnimations().every(animation => animation.playState !== 'running'))
    await page.screenshot({ path: `${output}/agreement-${name === '服务协议' ? 'service' : 'privacy'}-320.png`, fullPage: true })
    if (name === '服务协议') await page.getByText('关闭', { exact: true }).click()
    else await page.locator('.m-agreement-mask').click({ position: { x: 8, y: 8 } })
    await document.waitFor({ state: 'hidden' })
  }
  await maskedPassword('密码')
  await maskedPassword('确认密码')
  await page.getByText('邮箱注册', { exact: true }).click()
  for (const label of ['密码', '确认密码']) {
    assert.equal(await page.getByLabel(label, { exact: true }).getAttribute('type'), 'password', '切换注册方式后恢复密码遮蔽')
    assert.equal(await page.getByLabel(label, { exact: true }).inputValue(), '', '切换注册方式清空密码')
  }
  await page.getByRole('textbox', { name: '邮箱', exact: true }).fill('new@example.com')
  await page.getByRole('textbox', { name: '验证码', exact: true }).fill('123456')
  await page.setViewportSize({ width: 320, height: 568 }); await fit(); await codeLayout()
  await page.getByRole('link', { name: '返回登录', exact: true }).click()
  await page.getByRole('link', { name: '忘记密码', exact: true }).click()
  await page.getByRole('heading', { name: '找回密码' }).waitFor()
  for (const width of [320, 390, 430]) {
    await page.setViewportSize({ width, height: 844 }); await fit(); await codeLayout()
    await page.screenshot({ path: `${output}/reset-${width}.png`, fullPage: true })
  }
  await maskedPassword('新密码')
  await maskedPassword('确认密码')
  await page.goto(`${base}/finance/wallet?from=check#balance`)
  await page.getByRole('heading', { name: '欢迎登录' }).waitFor()
  await page.setViewportSize({ width: 390, height: 844 })
  await page.screenshot({ path: `${output}/login-390.png`, fullPage: true })
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.getByText('请输入有效的手机号或邮箱', { exact: true }).waitFor()
  loginFailure = true
  await signIn()
  await page.getByText('账号或密码错误', { exact: true }).last().waitFor()
  await page.waitForTimeout(3600)
  loginFailure = false
  await signIn(); await page.waitForURL(`${base}/finance/wallet?from=check#balance`)
  await page.getByText('¥3250.50', { exact: true }).waitFor()
  await page.getByText('我的', { exact: true }).click()
  await page.getByText('会员 ID 728461931').waitFor()
  await fit(); await page.screenshot({ path: `${output}/profile-390.png`, fullPage: true })
  logoutFailure = true
  await page.getByRole('button', { name: '退出登录', exact: true }).click()
  await page.getByText('取消', { exact: true }).waitFor()
  await page.getByText('退出', { exact: true }).click()
  await page.getByText('退出暂时失败', { exact: true }).last().waitFor()
  assert.ok(page.url().endsWith('/account/profile'))
  logoutFailure = false
  await page.getByText('退出', { exact: true }).click(); await page.getByRole('heading', { name: '欢迎登录' }).waitFor()
  await page.getByText('验证码登录', { exact: true }).click()
  await page.getByRole('textbox', { name: '手机号', exact: true }).fill('13800138000')
  await page.getByRole('button', { name: '获取验证码', exact: true }).click()
  await page.getByRole('button', { name: /后重发/ }).waitFor()
  await codeLayout()
  await page.locator('.m-code-button').click()
  assert.equal(sentCode, 1, '倒计时期间重复点击不能再次发送')
  assert.equal(sentCode, 1)
  await page.getByRole('textbox', { name: '手机号', exact: true }).fill('13900139000')
  await page.getByRole('textbox', { name: '验证码', exact: true }).fill('123456')
  await codeLayout()
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.getByText('请先获取当前手机号的有效验证码').waitFor()
  assert.equal(smsLogins, 0, '改账号不能沿用旧挑战')
  await page.getByRole('textbox', { name: '手机号', exact: true }).fill('13800138000')
  await page.getByRole('button', { name: '微信登录', exact: true }).click()
  await page.getByText('该登录方式暂未接入，请使用密码登录', { exact: true }).last().waitFor()
  await page.getByRole('link', { name: '立即注册' }).click()
  await page.getByText('邮箱注册', { exact: true }).click()
  await page.getByRole('textbox', { name: '邮箱', exact: true }).fill('new@example.com')
  await page.getByRole('button', { name: '获取验证码', exact: true }).click()
  await page.getByRole('button', { name: /后重发/ }).waitFor()
  await codeLayout()
  await page.getByRole('textbox', { name: '验证码', exact: true }).fill('123456')
  await page.getByLabel('密码', { exact: true }).fill('Test@123456')
  await page.getByLabel('确认密码', { exact: true }).fill('wrong')
  await page.getByRole('button', { name: '注册', exact: true }).click()
  await page.getByText('两次输入的密码不一致').waitFor(); assert.equal(registrations, 0)
  await page.getByLabel('确认密码', { exact: true }).fill('Test@123456')
  await page.getByRole('button', { name: '注册', exact: true }).click()
  const agreementError = page.getByText('请阅读并同意服务协议和隐私政策', { exact: true })
  await agreementError.waitFor()
  const controlBox = await page.locator('.m-agreement-control').boundingBox(), errorBox = await agreementError.boundingBox()
  assert.ok(controlBox && errorBox && errorBox.y >= controlBox.y + controlBox.height, '协议校验提示应位于整行下方')
  assert.equal(registrations, 0, '未同意协议不能注册')
  await page.getByRole('checkbox', { name: '我已阅读并同意', exact: true }).click()
  await page.waitForFunction(() => document.querySelector('[role=checkbox]')?.getAttribute('aria-checked') === 'true')
  await page.screenshot({ path: `${output}/register-390.png`, fullPage: true })
  await page.getByRole('button', { name: '注册', exact: true }).click()
  await page.getByRole('heading', { name: '欢迎登录' }).waitFor(); assert.equal(registrations, 1)
  await signIn(); await page.getByText('总览', { exact: true }).click()
  await page.getByRole('heading', { name: '账户总览' }).waitFor(); await fit()
  await page.locator('.m-sr-only').filter({ hasText: '注册成功，请登录' }).waitFor({ state: 'hidden' })
  for (const width of [320, 390, 430, 768]) {
    await page.setViewportSize({ width, height: 844 }); await fit()
    await bottomNavigation()
    const greeting = await page.locator('.m-greeting').boundingBox(), balance = await page.locator('.m-balance').boundingBox()
    assert.ok(greeting && balance && balance.y >= greeting.y + greeting.height, '账户信息和余额卡片不能重叠')
    await page.screenshot({ path: `${output}/overview-${width}.png`, fullPage: true })
  }
  await page.setViewportSize({ width: 390, height: 650 }); await fit(); await bottomNavigation(true)
  await page.setViewportSize({ width: 390, height: 844 })
  await page.getByRole('button', { name: '查看资金账户', exact: true }).click()
  await page.getByRole('heading', { name: '资金账户', exact: true }).waitFor()
  await page.getByText('¥3250.50', { exact: true }).waitFor()
  await page.getByText('总览', { exact: true }).click()
  await page.getByRole('heading', { name: '账户总览' }).waitFor()
  await page.getByText('消息', { exact: true }).click()
  await page.getByRole('button', { name: /服务开通成功/ }).click()
  await page.locator('#mobile-message-body').waitFor()
  assert.equal(unread, false)
  assert.equal(await page.locator('#mobile-message-body').evaluate(node => node.scrollHeight > node.clientHeight), true)
  await page.getByText('关闭', { exact: true }).click()
  await page.screenshot({ path: `${output}/messages-390.png`, fullPage: true })
  await page.getByText('我的', { exact: true }).click()
  page.once('dialog', dialog => dialog.accept())
  await page.getByRole('button', { name: '使用 PC 版' }).click()
  assert.equal((await context.cookies()).find(cookie => cookie.name === 'console_presentation')?.value, 'pc')
  await page.getByRole('heading', { name: '测试会员', exact: true }).waitFor()
  walletExpired = true
  await page.getByRole('button', { name: '资金账户', exact: true }).click()
  await page.getByRole('heading', { name: '欢迎登录' }).waitFor()
  assert.equal(await page.getByText('¥3250.50', { exact: true }).count(), 0)
  await page.getByRole('link', { name: '忘记密码', exact: true }).click()
  await page.getByRole('textbox', { name: '账号', exact: true }).fill('test@example.com')
  await page.getByRole('button', { name: '获取验证码', exact: true }).click()
  await page.getByText('密码找回暂未接入，请联系服务方协助处理', { exact: true }).last().waitFor()
  registrationVerification = 0
  await page.goto(`${base}/register`)
  await page.getByRole('textbox', { name: '手机号', exact: true }).waitFor()
  assert.equal(await page.getByRole('button', { name: '获取验证码', exact: true }).count(), 0)
  brand = '更新后的平台'
  await page.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' })
    window.dispatchEvent(new Event('visibilitychange'))
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' })
    window.dispatchEvent(new Event('visibilitychange'))
  })
  await page.waitForFunction(() => document.title === '会员注册 - 更新后的平台')
  assert.deepEqual(errors, [])
  console.log('通过：移动端 320/390/430/768 布局，登录/注册/找回验证码按钮位置与组件默认尺寸、输入与倒计时状态、返回路径、登录反馈、邮箱注册配置与校验、验证码账号绑定、消息阅读与滚动、退出失败/成功、显示偏好、401缓存清理、动态品牌；使用模拟 API。')
} catch (error) {
  console.error('浏览器异常', errors)
  if (currentPage) { console.error(await currentPage.locator('body').innerText()); await currentPage.screenshot({ path: `${output}/failure.png`, fullPage: true }) }
  throw error
} finally { await browser.close() }
