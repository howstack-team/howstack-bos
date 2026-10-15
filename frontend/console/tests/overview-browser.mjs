import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = (process.env.CONSOLE_BASE_URL ?? 'http://127.0.0.1:5186/console').replace(/\/$/, '')
const output = '/tmp/howstack-console-overview'
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
try {
 const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
 const errors = [], calls = []
 let status = 200, available = 325050, frozen = 12000
 let member = { id: '72846193', nickname: '测试会员', email: 'fixture@example.com', mobile: '13800138000' }
 await context.route('**/api/console/v1/**', async route => {
  const path = new URL(route.request().url()).pathname.replace('/api/console/v1', ''); calls.push(path)
  let responseStatus = 200, data = null
  if (path === '/auth/me') data = { user: member }
  else if (path === '/wallet') { responseStatus = status; data = { currency: 'CNY', available_minor: available, frozen_minor: frozen } }
  else if (path === '/messages/summary') data = { unread: 0 }
  else responseStatus = 404
  await route.fulfill({ status: responseStatus, json: { code: responseStatus === 200 ? 0 : responseStatus * 100, message: responseStatus === 403 ? '无权访问资金账户' : responseStatus === 500 ? '资金服务暂不可用' : 'ok', data } })
 })
 const page = await context.newPage(); page.setDefaultTimeout(10000); page.on('pageerror', error => errors.push(error.message))
 const moduleNames = ['欢迎卡片', '账户概览', '我的产品', '快捷操作', '费用概览', '帮助文档', '最新公告']
 const account = page.getByRole('region', { name: '账户概览', exact: true })
 const products = page.getByRole('region', { name: '我的产品', exact: true })
 const shortcuts = page.getByRole('region', { name: '快捷操作', exact: true })
 const expenses = page.getByRole('region', { name: '费用概览', exact: true })
 async function noOverflow() { assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), '页面不能横向溢出') }
 await page.goto(`${base}/overview`); await account.getByText('¥3,250.50', { exact: true }).waitFor()
 for (const name of moduleNames) await page.getByRole('region', { name, exact: true }).waitFor()
 assert.equal(await page.locator('.overview-page section').count(), 7)
 assert.equal(await page.getByRole('heading', { name: '控制台', exact: true }).count(), 0)
 assert.equal(await page.getByRole('button', { name: '刷新账户', exact: true }).count(), 0)
 assert.equal(await page.getByText('查看账户与产品，轻松管理云上业务。', { exact: true }).count(), 0)
 await page.getByRole('heading', { name: '欢迎回来，测试会员', exact: true }).waitFor()
 for (const text of ['会员编号：72846193', '手机：138****8000', '邮箱：f***@example.com']) await page.getByText(text, { exact: true }).waitFor()
 assert.equal(await products.getByText('--', { exact: true }).count(), 3)
 assert.equal(await expenses.getByText('--', { exact: true }).count(), 3)
 for (const name of ['帮助文档', '最新公告']) {
  const link = page.getByRole('region', { name, exact: true }).getByRole('link')
  assert.equal(await link.getAttribute('href'), '/'); assert.equal(await link.getAttribute('target'), '_blank')
 }
 const accountBox = await account.boundingBox(), shortcutBox = await shortcuts.boundingBox()
 assert.ok(shortcutBox.x >= accountBox.x + accountBox.width)
 await shortcuts.getByRole('heading', { name: '快捷入口', exact: true }).waitFor()
 const allShortcuts = page.locator('.overview-all-shortcuts')
 await shortcuts.getByRole('button', { name: '查看更多', exact: true }).click(); await allShortcuts.waitFor(); await page.waitForFunction(() => document.activeElement?.classList.contains('overview-all-shortcuts'))
 assert.equal(await allShortcuts.getByRole('button').count(), 16)
 await allShortcuts.getByRole('button', { name: '优惠券', exact: true }).click(); await page.waitForURL(`${base}/finance/coupons`)
 await allShortcuts.waitFor({ state: 'hidden' }); await page.goBack(); await account.waitFor()
 for (const [name, path] of [['账户充值', '/finance/recharge'], ['订单管理', '/finance/orders'], ['提交工单', '/support/tickets'], ['产品续费', '/services/renewals'], ['发票管理', '/finance/invoices'], ['安全设置', '/account/security']]) {
  await shortcuts.getByRole('button', { name, exact: true }).click(); await page.waitForURL(`${base}${path}`)
  await page.getByText('功能正在建设中，开放后即可在这里使用。', { exact: true }).waitFor()
  await page.goBack(); await account.waitFor()
 }
 for (const [index, path] of ['/services/cloud-servers', '/services/physical-servers', '/services/colocation'].entries()) {
  await products.getByRole('link', { name: '查看产品', exact: true }).nth(index).click()
  await page.waitForURL(`${base}${path}`); await page.goBack(); await account.waitFor()
 }
 for (const width of [1440, 1280, 1024, 768, 390, 320]) {
  await page.setViewportSize({ width, height: 1000 }); await noOverflow()
  if (width <= 600) { const box = await shortcuts.boundingBox(), expenseBox = await expenses.boundingBox(); assert.ok(box.y > expenseBox.y) }
  if (width === 1440 || width === 390 || width === 320) {
   await shortcuts.getByRole('button', { name: '查看更多', exact: true }).click(); await allShortcuts.waitFor(); await page.waitForFunction(() => document.activeElement?.classList.contains('overview-all-shortcuts'))
   await noOverflow()
   await page.screenshot({ path: `${output}/all-shortcuts-${width}.png`, fullPage: false })
   await page.keyboard.press('Escape'); await allShortcuts.waitFor({ state: 'hidden' })
   await shortcuts.getByRole('button', { name: '查看更多', exact: true }).click(); await allShortcuts.waitFor(); await page.waitForFunction(() => document.activeElement?.classList.contains('overview-all-shortcuts'))
   await page.mouse.click(12, 200); await allShortcuts.waitFor({ state: 'hidden' })
  }
  await page.screenshot({ path: `${output}/overview-${width}.png`, fullPage: true })
 }
 await page.getByRole('button', { name: '切换深色模式', exact: true }).click(); await noOverflow()
 await page.screenshot({ path: `${output}/overview-dark-320.png`, fullPage: true })
 await page.getByRole('button', { name: '切换浅色模式', exact: true }).click()
 await page.setViewportSize({ width: 1440, height: 1000 })
 status = 500
 await page.reload()
 await account.getByText('资金账户加载失败', { exact: true }).waitFor()
 status = 403
 await page.reload()
 await account.getByText('暂时无法访问资金账户', { exact: true }).waitFor()
 assert.equal(await account.getByText('¥3,250.50', { exact: true }).count(), 0)
 status = 200; available = 0; frozen = 0
 await page.reload()
 await account.getByText('¥0.00', { exact: true }).first().waitFor(); assert.equal(await account.getByText('¥0.00', { exact: true }).count(), 2)
 member = { ...member, nickname: '长昵称用于验证窄屏布局'.repeat(6), mobile: null, email: null }
 await page.reload(); await account.waitFor(); await page.setViewportSize({ width: 320, height: 844 })
 await page.getByText('手机：-', { exact: true }).waitFor(); await page.getByText('邮箱：-', { exact: true }).waitFor(); await noOverflow()
 assert.deepEqual(errors, []); assert.ok(calls.every(path => ['/auth/me', '/wallet', '/messages/summary'].includes(path)))
 console.log(JSON.stringify({ passed: true, modules: moduleNames, widths: [1440, 1280, 1024, 768, 390, 320], errors, screenshots: output, evidence: '模拟会员资料与余额，验证零余额、403 隐藏旧数据、加载失败及快捷入口；未调用未接入的业务 API' }))
 await context.close()
} finally { await browser.close() }
