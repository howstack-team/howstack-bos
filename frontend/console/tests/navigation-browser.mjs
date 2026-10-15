import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = (process.env.CONSOLE_BASE_URL ?? 'http://127.0.0.1:5186/console').replace(/\/$/, '')
const output = '/tmp/howstack-console-navigation'; await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
const names = ['控制台', '云服务器', '物理服务器', '服务器托管', '续费管理', '充值中心', '资金明细', '订单管理', '退款管理', '发票管理', '优惠券', '账户设置', '安全设置', '实名认证', '登录记录', '消息中心', '工单管理']
const routes = ['/overview', '/services/cloud-servers', '/services/physical-servers', '/services/colocation', '/services/renewals', '/finance/recharge', '/finance/transactions', '/finance/orders', '/finance/refunds', '/finance/invoices', '/finance/coupons', '/account/profile', '/account/security', '/account/verification', '/account/login-history', '/account/messages', '/support/tickets']
try {
 const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } })
 let authenticated = true
 const errors = [], calls = []
 await ctx.route('**/api/console/v1/**', async route => {
  const path = new URL(route.request().url()).pathname.replace('/api/console/v1', ''); calls.push(path)
  let status = 200, data = null
  if (path === '/auth/me') { status = authenticated ? 200 : 401; data = authenticated ? { user: { id: '72846193', nickname: '测试会员', email: 'fixture@example.com', mobile: '13800138000' } } : null }
  else if (path === '/wallet') data = { currency: 'CNY', available_minor: 325050, frozen_minor: 12000 }
  else if (path === '/messages/summary') data = { unread: 0 }
  else if (path === '/auth/logout') authenticated = false
  else status = 404
  await route.fulfill({ status, json: { code: status === 200 ? 0 : status * 100, message: 'ok', data } })
 })
 const page = await ctx.newPage(); page.setDefaultTimeout(8000); page.on('pageerror', e => errors.push(e.message))
 const top = page.getByRole('navigation', { name: '控制台顶部导航', exact: true })
 const shortcuts = page.getByRole('navigation', { name: '工单与费用导航', exact: true })
 const side = page.getByRole('navigation', { name: '会员控制台导航', exact: true })
 const mobile = page.getByRole('navigation', { name: '移动端会员导航', exact: true })
 async function menu(nav) {
  assert.deepEqual((await nav.getByRole('menuitem').allTextContents()).map(s => s.trim()), names)
  for (const name of ['产品与服务', '财务管理', '账户管理', '服务与帮助']) await nav.getByText(name, { exact: true }).waitFor()
 }
 async function noOverflow() { assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)) }
 async function linkAppearance() {
  await page.mouse.move(0, 59)
  return page.locator('.console-top-navigation a, .console-top-shortcuts a').evaluateAll(links => links.map(link => {
   const style = getComputedStyle(link)
   return { color: style.color, background: style.backgroundColor, border: style.borderBottomWidth, decoration: style.textDecorationLine }
  }))
 }
 await page.goto(`${base}/overview`); await page.getByRole('region', { name: '欢迎卡片', exact: true }).waitFor(); await menu(side)
 assert.deepEqual((await top.getByRole('link').allTextContents()).map(s => s.trim()), ['控制台'])
 assert.deepEqual((await shortcuts.getByRole('link').allTextContents()).map(s => s.trim()), ['工单', '费用'])
 assert.equal(await top.getByRole('menuitem').count() + await shortcuts.getByRole('menuitem').count(), 0)
 assert.equal(await page.locator('.console-top-navigation [aria-current], .console-top-shortcuts [aria-current]').count(), 0)
 assert.equal(await top.getByRole('link', { name: '控制台', exact: true }).getAttribute('href'), new URL(`${base}/overview`).pathname)
 assert.equal(await shortcuts.getByRole('link', { name: '工单', exact: true }).getAttribute('href'), new URL(`${base}/support/tickets`).pathname)
 assert.equal(await shortcuts.getByRole('link', { name: '费用', exact: true }).getAttribute('href'), new URL(`${base}/finance/wallet`).pathname)
 const defaultLinkAppearance = await linkAppearance()
 assert.ok(defaultLinkAppearance.every(style => style.background === 'rgba(0, 0, 0, 0)' && style.border === '0px' && style.decoration === 'none'))
 for (let i = 0; i < names.length; i++) {
  const item = side.getByRole('menuitem', { name: names[i], exact: true })
  await item.click(); await page.waitForURL(`${base}${routes[i]}`)
  if (names[i] === '控制台') await page.getByRole('region', { name: '欢迎卡片', exact: true }).waitFor()
  else await page.getByRole('heading', { name: names[i], exact: true }).waitFor()
  assert.ok(await item.evaluate(el => el.className.includes('selected'))); await noOverflow()
  assert.deepEqual(await linkAppearance(), defaultLinkAppearance)
  if (!['控制台', '账户设置'].includes(names[i])) await page.getByText('功能正在建设中，开放后即可在这里使用。', { exact: true }).waitFor()
 }
 await page.reload(); await page.getByRole('heading', { name: '工单管理', exact: true }).waitFor()
 await page.goBack(); await page.getByRole('heading', { name: '消息中心', exact: true }).waitFor()
 await page.goForward(); await page.getByRole('heading', { name: '工单管理', exact: true }).waitFor()
 for (const removedPath of ['/support/help', '/support/announcements']) {
  await page.goto(`${base}${removedPath}`); await page.getByText('页面不存在', { exact: true }).waitFor()
 }
 await shortcuts.getByRole('link', { name: '工单', exact: true }).click(); await page.waitForURL(`${base}/support/tickets`)
 await page.getByRole('heading', { name: '工单管理', exact: true }).waitFor()
 await shortcuts.getByRole('link', { name: '费用', exact: true }).click(); await page.waitForURL(`${base}/finance/wallet`)
 await page.getByText('¥3,250.50', { exact: true }).waitFor()
 await top.getByRole('link', { name: '控制台', exact: true }).focus(); await page.keyboard.press('Enter'); await page.waitForURL(`${base}/overview`)
 const overviewBox = await top.boundingBox(), shortcutBox = await shortcuts.boundingBox(), avatarBox = await page.getByRole('button', { name: '会员菜单', exact: true }).boundingBox()
 assert.ok(shortcutBox.x > overviewBox.x + overviewBox.width && shortcutBox.x < avatarBox.x)
 await page.getByRole('region', { name: '欢迎卡片', exact: true }).waitFor()
 await page.getByText('¥3,250.50', { exact: true }).waitFor()
 await side.evaluate(el => { el.scrollTop = 0 })
 await page.screenshot({ path: `${output}/desktop.png`, fullPage: true })
 await side.getByRole('menuitem', { name: '工单管理', exact: true }).scrollIntoViewIfNeeded()
 assert.ok(await side.evaluate(el => el.scrollTop > 0 && el.scrollHeight > el.clientHeight))
 await page.screenshot({ path: `${output}/desktop-menu-bottom.png`, fullPage: true })
 await page.getByRole('button', { name: '收起侧边栏' }).click()
 await page.waitForFunction(() => document.querySelector('.console-sider').getBoundingClientRect().width === 48)
 await side.getByRole('menuitem', { name: '云服务器', exact: true }).click(); await page.waitForURL(`${base}/services/cloud-servers`); await noOverflow()
 await page.getByRole('button', { name: '展开侧边栏' }).click()
 for (const width of [390, 320, 768]) {
  await page.setViewportSize({ width, height: 844 })
  await page.getByRole('button', { name: '打开导航菜单' }).waitFor()
  assert.equal(await page.locator('.console-header').evaluate(el => el.getBoundingClientRect().height), 104)
  await shortcuts.getByRole('link', { name: '工单', exact: true }).click(); await page.waitForURL(`${base}/support/tickets`)
  await page.getByRole('button', { name: '打开导航菜单' }).click(); await menu(mobile)
  await mobile.getByRole('menuitem', { name: '工单管理', exact: true }).click(); await page.waitForURL(`${base}/support/tickets`)
  await mobile.waitFor({ state: 'hidden' }); await page.getByRole('heading', { name: '工单管理', exact: true }).waitFor()
  await page.getByRole('button', { name: '打开导航菜单' }).click()
  await mobile.getByRole('menuitem', { name: '工单管理', exact: true }).scrollIntoViewIfNeeded()
  await page.screenshot({ path: `${output}/mobile-menu-bottom-${width}.png`, fullPage: true })
  await mobile.getByRole('menuitem', { name: '账户设置', exact: true }).click(); await page.waitForURL(`${base}/account/profile`)
  await page.getByRole('button', { name: '打开导航菜单' }).waitFor(); await mobile.waitFor({ state: 'hidden' }); await noOverflow()
  const topBox = await top.boundingBox(), rightBox = await shortcuts.boundingBox()
  assert.equal(topBox.y, 60); assert.equal(rightBox.y, 60); assert.ok(rightBox.x > topBox.x)
  await page.screenshot({ path: `${output}/mobile-${width}.png`, fullPage: true })
  await page.getByRole('button', { name: '打开导航菜单' }).click(); await page.keyboard.press('Escape')
  await page.getByRole('button', { name: '打开导航菜单' }).waitFor()
 }
 await page.getByRole('button', { name: '切换深色模式' }).click(); assert.equal(await page.locator('body').getAttribute('arco-theme'), 'dark')
 await page.getByRole('button', { name: '会员菜单', exact: true }).click()
 await page.getByRole('menuitem', { name: '退出登录', exact: true }).click(); await page.waitForURL(`${base}/login`)
 assert.ok(calls.every(p => ['/auth/me', '/wallet', '/messages/summary', '/auth/logout'].includes(p)))
 assert.deepEqual(errors, []); await ctx.close()
 console.log(JSON.stringify({ passed: true, menus: names, widths: [1440, 390, 320, 768], screenshots: output, evidence: '模拟会话与余额，未创建真实服务、工单或财务记录' }))
} finally { await browser.close() }
