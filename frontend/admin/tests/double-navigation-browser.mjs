import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = '/tmp/howstack-double-navigation'
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
const permissions = ['admin.roles.view', 'admin.departments.view', 'admin.page.dashboard.view', 'admin.finance.payments.view',
  'admin.content.help-entries.view', 'admin.content.help-categories.view', 'admin.content.news-entries.view',
  'admin.updates.view', 'admin.profile.view']
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } })
  await context.addInitScript(() => localStorage.setItem('admin-layout-style', 'double-column'))
  const page = await context.newPage()
  const errors = []
  page.on('pageerror', error => errors.push(error.message))
  await context.route('**/api/admin/v1/**', route => {
    const path = new URL(route.request().url()).pathname.replace('/api/admin/v1', '')
    let data = { data: [], total: 0, page: 1, page_size: 10 }
    if (path === '/auth/me') data = { user: { id: 1, username: 'mock', nickname: '模拟管理员', is_founder: false }, permissions }
    if (path === '/roles' || path === '/departments') data = []
    if (path === '/system-updates/notice') data = { available: true, current_version: '0.1.0', latest_version: '0.2.0', force_update: false,
      changelog: { added: [], improved: [], fixed: [], notices: [] }, checked_at: '2026-10-06 12:00:00', last_error: null }
    return route.fulfill({ json: { code: 0, message: '成功', data, request_id: 'double-navigation-test' } })
  })
  const primary = page.getByRole('navigation', { name: '一级导航' })
  const secondary = page.getByRole('navigation', { name: '二级导航' })
  await page.goto(`${base}/roles`)
  await secondary.getByText('角色权限', {exact:true}).waitFor()
  assert.equal(await primary.getByText('客户管理', {exact:true}).count(), 0)
  assert.equal(await secondary.getByText('系统用户', {exact:true}).count(), 0)
  assert.equal(await secondary.getByText('个人设置', {exact:true}).count(), 0)
  await primary.locator('[aria-label="发现新版本 v0.2.0"]').waitFor()
  assert.equal(await secondary.locator('[aria-label="发现新版本 v0.2.0"]').count(), 1)
  await primary.getByText('财务管理', {exact:true}).click()
  await secondary.getByText('支付记录', {exact:true}).waitFor()
  assert.equal(new URL(page.url()).pathname, '/admin/roles')
  await secondary.getByText('支付记录', {exact:true}).click()
  await page.waitForURL('**/admin/payments')
  await page.goBack()
  await secondary.getByText('角色权限', {exact:true}).waitFor()
  await page.reload()
  await secondary.getByText('角色权限', {exact:true}).waitFor()
  await page.getByRole('button', { name: '收起二级导航' }).click()
  assert.equal(await secondary.count(), 0)
  await page.waitForFunction(() => document.querySelector('aside').getBoundingClientRect().width === 88
    && getComputedStyle(document.querySelector('[class*="layout-content_"]')).paddingLeft === '88px')
  const railBounds = await primary.boundingBox()
  const menuBounds = await primary.getByRole('menu').boundingBox()
  assert(menuBounds.width >= railBounds.width - 1)
  await page.screenshot({ path: `${output}/collapsed-1440.png` })
  await primary.getByText('内容管理', {exact:true}).click()
  await secondary.getByText('帮助文档', {exact:true}).click()
  await secondary.getByText('文档列表', {exact:true}).click()
  await page.waitForURL('**/admin/content/help')
  await secondary.getByText('新闻公告', {exact:true}).click()
  await secondary.getByText('公告列表', {exact:true}).waitFor()
  assert.equal(await secondary.getByText('文档列表', {exact:true}).isVisible(), true)
  await page.screenshot({ path: `${output}/expanded-1440.png` })
  console.log('通过：分类切换、路由同步、返回与刷新、收起展开、三级菜单、权限过滤及更新徽标')
  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 844 })
    await page.getByRole('button', {name:'打开导航菜单'}).click()
    await secondary.getByText(width === 390 ? '文档列表' : '角色权限', {exact:true}).waitFor()
    await page.waitForFunction(() => Math.abs(document.querySelector('nav[aria-label="一级导航"]').getBoundingClientRect().x) < 0.1)
    const bounds = await primary.boundingBox()
    assert(Math.abs(bounds.x) < 1)
    const secondBounds = await secondary.boundingBox()
    assert(secondBounds.x + secondBounds.width <= width-24)
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
    await page.screenshot({ path: `${output}/menu-${width}.png` })
    await page.keyboard.press('Escape')
    await page.getByRole('button', {name:'打开导航菜单'}).waitFor()
    await page.getByRole('button', {name:'打开导航菜单'}).click()
    await primary.getByText('系统管理', {exact:true}).click()
    await secondary.getByText('角色权限', {exact:true}).click()
    await page.waitForURL('**/admin/roles')
    await page.getByRole('button', {name:'打开导航菜单'}).waitFor()
    await page.getByRole('button', {name:'打开导航菜单'}).click()
    await page.getByRole('button', {name:'关闭导航菜单',exact:true}).last().click({ position: { x: width - 12, y: 100 } })
  }
  await page.setViewportSize({width:1440,height:900})
  await secondary.getByText('角色权限', {exact:true}).waitFor()
  await page.getByRole('button',{name:'收起二级导航'}).waitFor()
  assert.equal(await page.getByRole('button',{name:'收起二级导航'}).count(),1)
  assert.deepEqual(errors, [])
  console.log('通过：390px与320px双列面板、遮罩和Escape关闭、跳转收起、恢复桌面，无运行错误')
  await context.close()
} finally { await browser.close() }
