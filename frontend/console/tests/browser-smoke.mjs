import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
// 已安装的浏览器工具可通过环境变量提供，避免进入生产依赖。
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = (process.env.CONSOLE_BASE_URL ?? 'http://127.0.0.1:5176/console').replace(/\/$/, '')
const output = process.env.CONSOLE_SCREENSHOT_DIR ?? '/tmp/howstack-console-browser'
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
const results = []
const check = (name) => { results.push(name); console.log(`通过：${name}`) }
async function noOverflow(page, name) {
  const result = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }))
  assert.ok(result.document <= result.viewport, `${name}: ${JSON.stringify(result)}`)
  check(name)
}
try {
  const realContext = await browser.newContext({ viewport: { width: 1440, height: 900 } })
  const real = await realContext.newPage()
  const realErrors = []
  real.on('pageerror', (error) => realErrors.push(error.message))
  await real.goto(`${base}/finance/wallet?source=check#balance`)
  await real.waitForURL(`${base}/login`)
  await real.getByRole('heading', { name: '欢迎登录', exact: true }).waitFor()
  check('真实 Webman 匿名访问深层路由跳转登录')
  await real.getByRole('button', { name: '登录', exact: true }).click()
  await real.getByText('请输入手机号或邮箱', { exact: true }).waitFor()
  await real.getByText('请输入密码', { exact: true }).waitFor()
  check('空表单显示原生字段错误')
  await real.setViewportSize({ width: 390, height: 844 })
  await noOverflow(real, '390px 登录页无横向溢出')
  await real.waitForTimeout(250)
  await real.screenshot({ path: `${output}/login-390.png`, fullPage: true })
  assert.deepEqual(realErrors, [])
  await realContext.close()

  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } })
  let authenticated = false
  let walletStatus = 200
  let malformed = false
  let failedLogin = false
  let failLogout = false
  let walletRequests = 0
  let loginRequests = 0
  let walletDelay = 0
  let available = 325050
  let frozen = 12000
  const user = { id: '72846193', email: null, mobile: '13800138000', nickname: '测试会员' }
  const errors = []
  const page = await context.newPage()
  page.setDefaultTimeout(10_000)
  page.on('pageerror', (error) => errors.push(error.message))
  await context.route('**/api/console/v1/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname.replace('/api/console/v1', '')
    let status = 200
    let data = null
    let message = 'ok'
    if (pathname === '/auth/me') {
      if (authenticated) data = { user }
      else { status = 401; message = '会员登录状态已失效' }
    } else if (pathname === '/auth/login') {
      loginRequests++
      if (failedLogin) { status = 401; message = '手机号或密码错误' }
      else { authenticated = true; data = { user } }
    } else if (pathname === '/auth/logout') {
      if (failLogout) { status = 500; message = '退出暂时失败' }
      else authenticated = false
    } else if (pathname === '/wallet') {
      walletRequests++
      if (walletDelay) await new Promise((resolve) => setTimeout(resolve, walletDelay))
      status = walletStatus
      message = status === 200 ? 'ok' : status === 401 ? '会员登录状态已失效' : status === 403 ? '无权访问资金账户' : '资金服务暂不可用'
      data = malformed ? { currency: 'USD', available_minor: 'invalid', frozen_minor: 0 }
        : { currency: 'CNY', available_minor: available, frozen_minor: frozen }
    } else { status = 404; message = '页面未实现' }
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ code: status === 200 ? 0 : status * 100, message, data }) })
  })
  async function login() {
    await page.getByPlaceholder('请输入手机号或邮箱').fill('13800138000')
    await page.getByPlaceholder('请输入密码').fill('fixture-password')
    await page.getByRole('button', { name: '登录', exact: true }).click()
  }
  async function logout() {
    await page.getByRole('button', { name: '会员菜单', exact: true }).click()
    await page.getByRole('menuitem', { name: '退出登录' }).click()
  }
  await page.goto(`${base}/finance/wallet?source=check#balance`)
  await page.waitForURL(`${base}/login`)
  failedLogin = true
  await login()
  await page.getByText('手机号或密码错误', { exact: true }).waitFor()
  assert.equal(loginRequests, 1)
  check('模拟错误密码显示业务反馈，不重复请求')
  failedLogin = false
  await login()
  await page.waitForURL(`${base}/finance/wallet?source=check#balance`)
  await page.getByRole('heading', { name: '资金账户', exact: true }).waitFor()
  await page.getByText('¥3,250.50', { exact: true }).waitFor()
  check('模拟登录恢复查询参数和 hash，余额按元显示')
  await noOverflow(page, '1440px 资金账户无横向溢出')
  await page.screenshot({ path: `${output}/wallet-1440.png`, fullPage: true })
  await page.reload()
  await page.getByRole('heading', { name: '资金账户', exact: true }).waitFor()
  check('已登录深层路由刷新保持页面')
  const collapseButton = page.getByRole('button', { name: '收起侧边栏' })
  const collapseSize = await collapseButton.boundingBox()
  assert.equal(collapseSize.width, 24)
  assert.equal(collapseSize.height, 24)
  assert.equal(await page.locator('.console-sider').evaluate((node) => node.getBoundingClientRect().width), 220)
  await page.getByRole('button', { name: '收起侧边栏' }).click()
  await page.getByRole('button', { name: '展开侧边栏' }).waitFor()
  await page.waitForFunction(() => document.querySelector('.console-sider').getBoundingClientRect().width === 48)
  await noOverflow(page, '桌面侧栏收起无溢出')
  await page.getByRole('button', { name: '展开侧边栏' }).click()
  await page.waitForTimeout(250)
  await page.getByRole('button', { name: '切换深色模式' }).click()
  assert.equal(await page.locator('body').getAttribute('arco-theme'), 'dark')
  const primary = await page.evaluate(() => getComputedStyle(document.body).getPropertyValue('--primary-6').trim())
  assert.equal(primary, '22, 93, 255')
  const background = await page.locator('body').evaluate((element) => getComputedStyle(element).backgroundColor)
  assert.equal(background, 'rgb(23, 23, 26)')
  await page.waitForTimeout(250)
  await page.screenshot({ path: `${output}/wallet-dark.png`, fullPage: true })
  await page.getByRole('button', { name: '切换浅色模式' }).click()
  check('主题切换与品牌色保持正确')
  await page.getByRole('button', { name: '会员菜单', exact: true }).click()
  await page.getByRole('menuitem', { name: '账户设置', exact: true }).last().click()
  await page.waitForURL(`${base}/account/profile`)
  await page.getByText('138****8000', { exact: true }).waitFor()
  await page.getByRole('switch', { name: '显示完整联系方式' }).click()
  await page.getByText('13800138000', { exact: true }).waitFor()
  check('头像账户入口和原生圆形开关可操作')
  await page.setViewportSize({ width: 390, height: 844 })
  await page.getByRole('button', { name: '打开导航菜单' }).waitFor()
  await noOverflow(page, '390px 账户设置无横向溢出')
  await page.screenshot({ path: `${output}/profile-390.png`, fullPage: true })
  await page.getByRole('button', { name: '打开导航菜单' }).click()
  const drawer = page.getByRole('navigation', { name: '移动端会员导航' })
  await drawer.waitFor()
  const closeMenu = page.getByRole('button', { name: '关闭导航菜单' })
  assert.equal(await closeMenu.getAttribute('aria-expanded'), 'true')
  const toggleSize = await closeMenu.boundingBox()
  assert.equal(toggleSize.width, 40)
  assert.equal(toggleSize.height, 40)
  await page.waitForTimeout(350)
  const mobilePanel = await drawer.evaluate((node) => {
    const panel = node.closest('.console-mobile-panel')
    const rect = panel.getBoundingClientRect()
    return { width: rect.width, top: rect.top }
  })
  assert.equal(mobilePanel.width, 260)
  assert.equal(mobilePanel.top, 104)
  await noOverflow(page, '390px 菜单展开无横向溢出')
  await page.screenshot({ path: `${output}/menu-open-390.png`, fullPage: true })
  await closeMenu.click()
  await drawer.waitFor({ state: 'hidden' })
  await page.getByRole('button', { name: '打开导航菜单' }).click()
  await page.waitForTimeout(350)
  await page.keyboard.press('Escape')
  await drawer.waitFor({ state: 'hidden' })
  await page.getByRole('button', { name: '打开导航菜单' }).click()
  await page.waitForTimeout(350)
  await page.mouse.click(350, 200)
  await drawer.waitFor({ state: 'hidden' })
  check('移动菜单支持双行顶栏，支持顶栏关闭、Escape 和遮罩关闭')
  await page.getByRole('button', { name: '打开导航菜单' }).click()
  await drawer.getByRole('menuitem', { name: '控制台' }).click()
  await page.waitForURL(`${base}/overview`)
  await drawer.waitFor({ state: 'hidden' })
  check('390px 导航抽屉跳转后关闭')
  await page.getByRole('region', { name: '欢迎卡片', exact: true }).waitFor()
  await noOverflow(page, '390px 概览无横向溢出')
  await page.screenshot({ path: `${output}/overview-390.png`, fullPage: true })
  await page.setViewportSize({ width: 1440, height: 900 })
  await page.getByRole('button', { name: '收起侧边栏' }).waitFor()
  await page.getByRole('button', { name: '收起侧边栏' }).click()
  await page.setViewportSize({ width: 768, height: 900 })
  await page.getByRole('button', { name: '打开导航菜单' }).click()
  await drawer.waitFor()
  await page.setViewportSize({ width: 769, height: 900 })
  await page.getByRole('button', { name: '收起侧边栏' }).waitFor()
  await drawer.waitFor({ state: 'hidden' })
  await page.setViewportSize({ width: 390, height: 844 })
  await page.getByRole('button', { name: '打开导航菜单' }).waitFor()
  assert.equal(await drawer.isVisible(), false)
  check('768px 导航断点和桌面恢复展开状态与后台一致')
  await page.setViewportSize({ width: 1440, height: 900 })
  await page.screenshot({ path: `${output}/overview-1440.png`, fullPage: true })
  await page.goto(`${base}/account/profile/`)
  await page.getByRole('heading', { name: '账户设置', exact: true }).waitFor()
  assert.equal(await page.title(), '账户设置 - 皓量云擎会员控制台')
  check('尾部斜杠路径保持标题和路由一致')
  await page.goto(`${base}/missing/page`)
  await page.getByText('页面不存在', { exact: true }).waitFor()
  await page.getByRole('button', { name: '返回控制台', exact: true }).click()
  await page.waitForURL(`${base}/overview`)
  check('未知路由展示 404 并可返回概览')
  await page.goto(`${base}/finance/wallet`)
  await page.getByText('¥3,250.50', { exact: true }).waitFor()
  walletStatus = 500
  await page.getByRole('button', { name: '刷新余额', exact: true }).click()
  await page.getByText('余额刷新失败，当前显示上次查询结果', { exact: true }).waitFor()
  await page.getByText('¥3,250.50', { exact: true }).waitFor()
  walletStatus = 200
  available = 0
  frozen = 0
  await page.getByRole('button', { name: '重新查询', exact: true }).click()
  await page.getByText('¥0.00', { exact: true }).first().waitFor()
  check('余额失败保留旧结果，重试与零余额展示正确')
  walletStatus = 403
  await page.getByRole('button', { name: '刷新余额', exact: true }).click()
  await page.getByText('暂时无法访问资金账户', { exact: true }).waitFor()
  assert.equal(await page.getByText('¥0.00', { exact: true }).count(), 0)
  assert.equal(new URL(page.url()).pathname, '/console/finance/wallet')
  check('403 隐藏余额并保留会话')
  walletStatus = 200
  malformed = true
  await page.getByRole('button', { name: '刷新余额', exact: true }).click()
  await page.getByText('余额响应格式无效', { exact: true }).waitFor()
  check('非法金额和币种响应被拒绝')
  malformed = false
  walletStatus = 401
  await page.getByRole('button', { name: '刷新余额', exact: true }).click()
  authenticated = false
  await page.waitForURL(`${base}/login`)
  check('业务接口 401 同步清理界面并跳转登录')
  walletStatus = 200
  available = 123
  await login()
  await page.waitForURL(`${base}/finance/wallet`)
  await page.getByText('¥1.23', { exact: true }).waitFor()
  check('重新登录查询新余额，不复用上一会话缓存')
  failLogout = true
  await logout()
  await page.getByText('退出暂时失败', { exact: true }).waitFor()
  assert.equal(new URL(page.url()).pathname, '/console/finance/wallet')
  check('退出请求失败保留登录界面')
  failLogout = false
  walletDelay = 700
  await page.getByRole('button', { name: '刷新余额', exact: true }).click()
  const before = walletRequests
  await logout()
  await page.waitForURL(`${base}/login`)
  await page.waitForTimeout(900)
  assert.equal(new URL(page.url()).pathname, '/console/login')
  assert.equal(await page.getByText('¥1.23', { exact: true }).count(), 0)
  assert.ok(walletRequests >= before)
  check('退出取消在途余额请求，旧响应不能恢复已退出界面')
  assert.deepEqual(errors, [])
  check('所有页面无浏览器运行时错误')
  await context.close()
  console.log(JSON.stringify({ passed: results.length, screenshots: output }, null, 2))
} finally { await browser.close() }
