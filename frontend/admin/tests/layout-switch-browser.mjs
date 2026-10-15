import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = '/tmp/howstack-layout-switch'
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } })
  const page = await context.newPage()
  const errors = []
  let noticeRequests = 0
  const permissions = ['admin.roles.view', 'admin.departments.view', 'admin.finance.payments.view',
    'admin.content.help-entries.view', 'admin.content.help-categories.view', 'admin.content.news-entries.view',
    'admin.updates.view', 'admin.profile.view']
  page.on('pageerror', error => errors.push(error.message))
  await context.route('**/api/admin/v1/**', route => {
    const path = new URL(route.request().url()).pathname.replace('/api/admin/v1', '')
    let data = { data: [], total: 0, page: 1, page_size: 10 }
    if (path === '/auth/me') data = { user: { id: 1, username: 'mock', nickname: '模拟管理员', is_founder: false }, permissions }
    if (path === '/roles' || path === '/departments') data = []
    if (path === '/system-updates/notice') {
      noticeRequests++
      data = { available: true, current_version: '0.1.0', latest_version: '0.2.0', force_update: false,
        changelog: { added: [], improved: [], fixed: [], notices: [] }, checked_at: '2026-10-06 12:00:00', last_error: null }
    }
    return route.fulfill({ json: { code: 0, message: '成功', data, request_id: 'layout-switch-test' } })
  })
  const classic = page.getByRole('navigation', { name: '后台导航', exact: true })
  const primary = page.getByRole('navigation', { name: '一级导航', exact: true })
  const secondary = page.getByRole('navigation', { name: '二级导航', exact: true })
  async function switchLayout(name) {
    const url = page.url()
    const content = await page.locator('.page-card').elementHandle()
    const previousRequests = noticeRequests
    await page.getByRole('button', { name: '页面配置', exact: true }).click()
    await page.getByText(name, { exact: true }).click()
    assert(await page.getByRole('radio', { name, exact: true }).isChecked())
    await page.mouse.click(10, 120)
    await page.getByText(name, { exact: true }).waitFor({ state: 'hidden' })
    assert.equal(page.url(), url)
    assert(await content.evaluate(element => element.isConnected), '切换布局不能重新挂载业务页面')
    assert.equal(noticeRequests, previousRequests, '切换布局不能重复获取更新通知')
  }
  async function checkWidth(width) {
    await page.waitForFunction(width => document.querySelector('aside')?.getBoundingClientRect().width === width, width)
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
  }
  async function checkClassicTrigger() {
    const button = page.getByRole('button', { name: /^(收起|展开)导航菜单$/ })
    const bounds = await button.boundingBox()
    const sidebar = await page.locator('aside').boundingBox()
    assert(bounds && sidebar)
    assert.equal(bounds.width, 24)
    assert.equal(bounds.height, 24)
    assert(Math.abs(sidebar.x + sidebar.width - bounds.x - bounds.width - 12) < 1,
      '经典侧栏折叠入口应距离右侧12px')
    assert(Math.abs(sidebar.y + sidebar.height - bounds.y - bounds.height - 12) < 1,
      '经典侧栏折叠入口应距离底部12px')
  }
  await page.goto(`${base}/roles`)
  await classic.getByText('角色权限', { exact: true }).waitFor()
  await checkWidth(220)
  await checkClassicTrigger()
  assert.equal(await primary.count(), 0)
  assert.equal(await classic.getByText('客户管理', { exact: true }).count(), 0)
  assert.equal(await classic.getByText('个人设置', { exact: true }).count(), 0)
  await classic.locator('[aria-label="发现新版本 v0.2.0"]').first().waitFor()
  await classic.getByText('内容管理', { exact: true }).click()
  await classic.getByText('帮助文档', { exact: true }).click()
  await classic.getByText('新闻公告', { exact: true }).click()
  await classic.getByText('公告列表', { exact: true }).waitFor()
  assert(await classic.getByText('文档列表', { exact: true }).isVisible())
  await page.screenshot({ path: `${output}/classic-1440.png` })
  await switchLayout('双列布局')
  await secondary.getByText('角色权限', { exact: true }).waitFor()
  await checkWidth(256)
  assert.equal(await classic.count(), 0)
  await page.reload()
  await secondary.getByText('角色权限', { exact: true }).waitFor()
  await checkWidth(256)
  await switchLayout('经典布局')
  await classic.getByText('角色权限', { exact: true }).waitFor()
  await page.reload()
  await classic.getByText('角色权限', { exact: true }).waitFor()
  await page.getByRole('button', { name: '收起导航菜单', exact: true }).click()
  await checkWidth(48)
  await checkClassicTrigger()
  await page.waitForFunction(() => getComputedStyle(document.querySelector('[class*="layout-content_"]')).paddingLeft === '48px')
  await page.screenshot({ path: `${output}/classic-collapsed-1440.png` })
  await page.getByRole('button', { name: '展开导航菜单', exact: true }).click()
  await checkWidth(220)
  await checkClassicTrigger()
  console.log('通过：默认经典、配置切换与刷新保存、菜单权限、多个分组展开、更新徽标及经典折叠')
  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 844 })
    await page.getByRole('button', { name: '打开导航菜单' }).click()
    await page.waitForFunction(() => Math.abs(document.querySelector('nav[aria-label="后台导航"]').getBoundingClientRect().x) < 0.1)
    await checkWidth(220)
    await page.screenshot({ path: `${output}/classic-${width}.png` })
    await page.keyboard.press('Escape')
    await page.getByRole('button', { name: '打开导航菜单' }).waitFor()
    await page.getByRole('button', { name: '打开导航菜单' }).click()
    await page.getByRole('button', { name: '关闭导航菜单', exact: true }).last().click({ position: { x: width - 12, y: 100 } })
  }
  await page.setViewportSize({ width: 390, height: 844 })
  await page.getByRole('button', { name: '打开导航菜单' }).click()
  await classic.getByText('财务管理', { exact: true }).click()
  await classic.getByText('支付记录', { exact: true }).click()
  await page.waitForURL('**/admin/payments')
  await page.getByRole('button', { name: '打开导航菜单' }).waitFor()
  await switchLayout('双列布局')
  await page.getByRole('button', { name: '打开导航菜单' }).click()
  await secondary.getByText('支付记录', { exact: true }).waitFor()
  await checkWidth(256)
  await page.screenshot({ path: `${output}/double-column-390.png` })
  await page.keyboard.press('Escape')
  await page.evaluate(() => localStorage.setItem('admin-layout-style', 'unknown-layout'))
  await page.reload()
  await page.getByRole('button', { name: '打开导航菜单' }).click()
  await classic.getByText('支付记录', { exact: true }).waitFor()
  await checkWidth(220)
  assert.deepEqual(errors, [])
  console.log('通过：390px和320px、遮罩和Escape关闭、手机切换、跳转收起、未知偏好回退，无运行错误')
  await context.close()
} finally { await browser.close() }
