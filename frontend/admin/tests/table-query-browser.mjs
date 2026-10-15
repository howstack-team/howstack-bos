import assert from 'node:assert/strict'
import { readFile, mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = '/tmp/howstack-table-query'
await mkdir(output, { recursive: true })
const routes = await readFile(new URL('../src/routes.ts', import.meta.url), 'utf8')
const permissions = [...new Set([...routes.matchAll(/['"](admin\.[\w.-]+)['"]/g)].map(match => match[1]))]
permissions.push('admin.members.create', 'admin.content.help-entries.create', 'admin.content.news-entries.create')
const resources = Object.fromEntries(['regions', 'zones', 'lines', 'flavor_groups', 'flavors', 'image_types', 'images', 'disk_types', 'network_policies', 'protections'].map(key => [key, []]))
const browser = await chromium.launch({ headless: true })
const requests = []
let writes = 0
const errors = []
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } })
  await context.route('**/api/admin/v1/**', async route => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/admin/v1', '')
    requests.push({ path, params: Object.fromEntries(url.searchParams), method: request.method() })
    if (request.method() !== 'GET') writes++
    let data = { data: [], total: 40 }
    if (path === '/auth/me') data = { user: { id: 1, username: 'mock', nickname: '模拟管理员', is_founder: true }, permissions }
    else if (path === '/system-updates/notice') data = { available: false }
    else if (path === '/member-catalog/options') data = { groups: [], levels: [] }
    else if (path === '/compute-resources' || path === '/provider-connections/references') data = resources
    else if (path === '/cloud-servers/delivery-rules') data = []
    else if (path === '/cloud-servers/delivery-rules/references') data = { resources, connections: [], lines: [] }
    else if (path === '/application-center/owned') data = { items: [], jobs: [] }
    else if (path === '/application-center/store-link') data = { store_url: '' }
    else if (path === '/provider-connections') data = []
    else if (path === '/cloud-products/references') data = { resources, connections: [], delivery_rules: [] }
    else if (path === '/members') data = { data: [{ id: 1, nickname: url.searchParams.get('nickname') || '模拟会员', mobile: '13800000000', email: 'test@example.com', group_id: null, level_id: null, status: 1 }], total: 40 }
    else if (path === '/real-name-authentications') data = { data: [{ id: 1, mobile: '13800000000', nickname: '模拟会员', authentication_type: 'enterprise', status: 'pending', subject_name: '测试主体', company_name: '测试企业', submitted_at: '2026-10-06 10:00:00' }], total: 40 }
    // Hold one response to check request cancellation when browser history changes.
    if (path === '/members' && url.searchParams.get('nickname') === '慢请求') await new Promise(resolve => setTimeout(resolve, 500))
    await route.fulfill({ json: { code: 0, message: '成功', data, request_id: 'table-query-mock' } })
  })
  const page = await context.newPage()
  page.on('pageerror', error => errors.push(error.message))
  const query = () => page.getByRole('form', { name: '表格查询', exact: true })
  const ready = async () => { await query().getByRole('button', { name: '查询', exact: true }).waitFor(); await page.waitForFunction(() => !document.querySelector('.table-query-actions button')?.disabled) }
  const noOverflow = async () => assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `页面横向溢出：${page.url()}`)
  const pages = [
    ['members', '昵称'], ['member-groups', '名称'], ['member-levels', '名称'], ['real-name-authentications', '会员手机号'],
    ['orders', '订单号'], ['services/cloud-servers', '实例编号'], ['support/tickets', '工单编号'],
    ['support/ticket-types', '名称'], ['support/quick-replies', '标题'], ['content/help', '标题'],
    ['content/help-categories', '分类名称'], ['content/news', '标题'], ['content/news-categories', '分类名称'],
    ['audit/operations', '操作账号'], ['audit/logins', '账号'], ['products/cloud-servers?tab=offers', '方案名称'],
    ['products/cloud-servers?tab=regions', '名称'], ['products/cloud-servers?tab=delivery', '规则名称'], ['application-center', '应用名称'],
  ]
  for (const [path, label] of pages) {
    console.log('检查查询区', path)
    await page.goto(`${base}/${path}`)
    await ready()
    assert.equal(await query().getByRole('button', { name: '重置', exact: true }).count(), 1)
    assert.equal(await query().getByRole('textbox', { name: label, exact: true }).count(), 1)
    assert.equal(await query().locator('.arco-input-search').count(), 0)
    assert.equal(await query().evaluate(element => element.closest('.page-toolbar')), null)
    assert.equal(await query().getByText('关键词', { exact: true }).count(), 0)
    await noOverflow()
  }
  await page.goto(`${base}/real-name-authentications?page=3&page_size=20`)
  await ready()
  const initial = requests.filter(item => item.path === '/real-name-authentications').length
  await query().getByRole('textbox', { name: '会员手机号', exact: true }).fill('13800000000')
  await query().getByRole('textbox', { name: '认证主体', exact: true }).fill('测试企业')
  await query().locator('.arco-select').nth(0).click()
  await page.locator('.arco-select-popup:visible').getByText('企业认证', { exact: true }).click()
  await query().locator('.arco-select').nth(1).click()
  await page.locator('.arco-select-popup:visible').getByText('待审核', { exact: true }).click()
  await page.waitForTimeout(350)
  assert.equal(requests.filter(item => item.path === '/real-name-authentications').length, initial, '待填写条件不能隐式请求')
  const response = page.waitForResponse(r => r.url().includes('/real-name-authentications?') && new URL(r.url()).searchParams.get('subject') === '测试企业')
  await query().getByRole('button', { name: '查询', exact: true }).click()
  await response; await ready()
  let params = new URL(page.url()).searchParams
  assert.equal(params.get('page'), '1'); assert.equal(params.get('page_size'), '20')
  for (const [key, value] of Object.entries({ mobile: '13800000000', subject: '测试企业', type: 'enterprise', status: 'pending' })) assert.equal(params.get(key), value)
  assert.equal(params.has('keyword'), false)
  const actual = requests.filter(item => item.path === '/real-name-authentications').at(-1).params
  assert.equal(actual.mobile, '13800000000'); assert.equal(actual.subject, '测试企业'); assert.equal(actual.status, 'pending')
  await page.reload(); await ready()
  assert.equal(await query().getByRole('textbox', { name: '认证主体', exact: true }).inputValue(), '测试企业')
  await page.screenshot({ path: `${output}/authentication-desktop.png` })
  const nextPage = page.waitForResponse(r => r.url().includes('/real-name-authentications?') && new URL(r.url()).searchParams.get('page') === '2')
  await page.locator('.arco-pagination-item').filter({ hasText: /^2$/ }).click()
  await nextPage; await ready()
  assert.equal(new URL(page.url()).searchParams.get('page'), '2')
  assert.equal(new URL(page.url()).searchParams.get('subject'), '测试企业')

  const resetResponse = page.waitForResponse(r => r.url().includes('/real-name-authentications?') && !new URL(r.url()).searchParams.has('subject'))
  await query().getByRole('button', { name: '重置', exact: true }).click()
  await resetResponse; await ready()
  params = new URL(page.url()).searchParams
  for (const key of ['mobile', 'subject', 'nickname', 'status', 'type']) assert.equal(params.has(key), false)
  assert.equal(await query().getByRole('textbox', { name: '认证主体', exact: true }).inputValue(), '')
  await page.goBack(); await ready()
  assert.equal(await query().getByRole('textbox', { name: '认证主体', exact: true }).inputValue(), '测试企业')
  await page.goto(`${base}/members?nickname=原条件&status=0&page=2&page_size=20`); await ready()
  await query().getByRole('textbox', { name: '昵称', exact: true }).fill('慢请求')
  const started = page.waitForRequest(r => r.url().includes('/members?') && new URL(r.url()).searchParams.get('nickname') === '慢请求')
  await query().getByRole('textbox', { name: '昵称', exact: true }).press('Enter'); await started
  await page.goBack(); await ready(); await page.waitForTimeout(550)
  assert.equal(new URL(page.url()).searchParams.get('nickname'), '原条件')
  assert.equal(await page.locator('td').getByText('慢请求', { exact: true }).count(), 0, '旧响应不能覆盖历史恢复结果')
  await query().getByRole('textbox', { name: '昵称', exact: true }).fill('新条件')
  const enter = page.waitForResponse(r => r.url().includes('/members?') && new URL(r.url()).searchParams.get('nickname') === '新条件')
  await query().getByRole('textbox', { name: '昵称', exact: true }).press('Enter'); await enter; await ready()
  assert.equal(requests.filter(item => item.path === '/members' && item.params.nickname === '新条件').length, 1, '回车只提交一次')
  assert.equal(new URL(page.url()).searchParams.get('status'), '0', '失败/禁用状态 0 不能丢失')

  await page.goto(`${base}/products/cloud-servers?tab=offers`); await ready()
  await query().getByRole('textbox', { name: '方案名称', exact: true }).fill('香港')
  await query().getByRole('textbox', { name: '方案标识', exact: true }).fill('hk')
  await query().getByRole('button', { name: '查询', exact: true }).click(); await ready()
  assert.equal(new URL(page.url()).searchParams.get('tab'), 'offers')
  assert.equal(new URL(page.url()).searchParams.get('name'), '香港')
  assert.equal(new URL(page.url()).searchParams.get('code'), 'hk')
  await query().getByRole('button', { name: '重置', exact: true }).click(); await ready()
  assert.equal(new URL(page.url()).searchParams.get('tab'), 'offers')
  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 844 })
    await page.goto(`${base}/real-name-authentications`); await ready()
    await query().getByRole('button', { name: '筛选', exact: true }).click()
    const drawer = page.locator('.arco-drawer:visible')
    await drawer.getByRole('textbox', { name: '认证主体', exact: true }).fill('未提交')
    await page.locator('.arco-drawer-mask:visible').click({ position: { x: 4, y: 4 } })
    await drawer.waitFor({ state: 'hidden' })
    await query().getByRole('button', { name: '筛选', exact: true }).click()
    assert.equal(await drawer.getByRole('textbox', { name: '认证主体', exact: true }).inputValue(), '')
    await drawer.getByRole('textbox', { name: '认证主体', exact: true }).fill('移动查询')
    await drawer.getByRole('button', { name: '查询', exact: true }).click()
    await drawer.waitFor({ state: 'hidden' }); await ready()
    assert.equal(new URL(page.url()).searchParams.get('subject'), '移动查询')
    await noOverflow()
    await page.screenshot({ path: `${output}/authentication-${width}.png` })
  }
  assert.deepEqual(errors, [])
  assert.equal(writes, 0)
  console.log('通过：19 个查询区；多条件提交、重置、URL/返回恢复、状态 0、回车单次提交、旧响应隔离、390/320px 筛选与遮罩关闭。全部为模拟接口，业务写入 0。')
} finally { await browser.close() }
