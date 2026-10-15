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
resources.regions = [{ id: 1, name: '地域甲', code: 'region-a', status: 1, sort: 9 }, { id: 2, name: '地域乙', code: 'region-b', status: 1, sort: 1 }]
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
    else if (path === '/roles') data = [{ id: 1, name: '角色甲', status: 1, sort_order: 1, data_scope: 'all', description: '', is_system: false }, { id: 2, name: '角色乙', status: 0, sort_order: 9, data_scope: 'self', description: '', is_system: false }, { id: 3, name: '角色丙', status: 1, sort_order: 9, data_scope: 'department', description: '', is_system: false }]
    else if (path === '/departments') data = []
    else if (path === '/permissions') data = {}
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
  const header = title => page.getByRole('columnheader').filter({ hasText: new RegExp(`^${title}$`) })
  const waitList = (path, key, value) => page.waitForResponse(response => {
    const url = new URL(response.url())
    return url.pathname.endsWith(path) && (value === null ? !url.searchParams.has(key) : url.searchParams.get(key) === value)
  })
  const filter = async (title, label) => {
    await header(title).locator('.arco-table-filters').click()
    const popup = page.locator('.arco-table-filters-popup:visible')
    await popup.getByText(label, { exact: true }).click()
    await popup.getByRole('button', { name: '确定', exact: true }).click()
  }
  await page.goto(`${base}/members?nickname=排序测试&page=3&page_size=20`); await ready()
  let response = waitList('/members', 'sort_order', 'asc')
  await header('注册时间').locator('.arco-table-cell-with-sorter').click(); await response; await ready()
  let params = new URL(page.url()).searchParams
  assert.equal(params.get('sort_by'), 'created_at'); assert.equal(params.get('page'), '1')
  assert.equal(params.get('nickname'), '排序测试'); assert.equal(params.get('page_size'), '20')
  response = waitList('/members', 'sort_order', 'desc')
  await header('注册时间').locator('.arco-table-cell-with-sorter').click(); await response; await ready()
  response = waitList('/members', 'status', '0')
  await filter('状态', '禁用'); await response; await ready()
  assert.equal(new URL(page.url()).searchParams.get('sort_order'), 'desc')
  assert.equal(new URL(page.url()).searchParams.get('nickname'), '排序测试')
  assert.equal(await query().locator('.arco-select-view').innerText(), '禁用', '表头筛选同步查询区')
  await page.reload(); await ready()
  assert.equal(await header('注册时间').locator('.arco-table-sorter-icon-active').count(), 1)
  await page.screenshot({ path: `${output}/column-controls-desktop.png` })
  response = waitList('/members', 'sort_by', null)
  await header('注册时间').locator('.arco-table-cell-with-sorter').click(); await response; await ready()
  assert.equal(new URL(page.url()).searchParams.get('status'), '0', '取消排序保留筛选')
  response = waitList('/members', 'status', null)
  await header('状态').locator('.arco-table-filters').click()
  await page.locator('.arco-table-filters-popup:visible').getByRole('button', { name: '重置', exact: true }).click()
  await response; await ready()
  assert.equal(new URL(page.url()).searchParams.get('nickname'), '排序测试')
  await page.goBack(); await ready()
  assert.equal(new URL(page.url()).searchParams.get('status'), '0')
  await query().getByRole('button', { name: '重置', exact: true }).click(); await ready()
  for (const key of ['nickname', 'status', 'sort_by', 'sort_order']) assert.equal(new URL(page.url()).searchParams.has(key), false)

  await page.goto(`${base}/orders?order_no=TEST`); await ready()
  response = waitList('/orders', 'sort_by', 'payable_minor')
  await header('应付金额').locator('.arco-table-cell-with-sorter').click(); await response; await ready()
  response = waitList('/orders', 'order_type', 'renewal')
  await filter('订单类型', '续费'); await response; await ready()
  response = waitList('/orders', 'payment_status', 'unpaid')
  await filter('支付状态', '未支付'); await response; await ready()
  const request = requests.filter(item => item.path === '/orders').at(-1).params
  assert.equal(request.order_no, 'TEST'); assert.equal(request.order_type, 'renewal')
  assert.equal(request.payment_status, 'unpaid'); assert.equal(request.sort_by, 'payable_minor')
  await page.reload(); await ready()
  assert.equal(new URL(page.url()).searchParams.get('order_type'), 'renewal')

  await page.goto(`${base}/products/cloud-servers?tab=regions`); await ready()
  await page.getByRole('cell', { name: '地域甲', exact: true }).waitFor()
  await header('排序').locator('.arco-table-cell-with-sorter').click()
  assert.equal(new URL(page.url()).searchParams.get('sort_by'), 'sort')
  assert.deepEqual(await page.locator('tbody tr').evaluateAll(rows => rows.map(row => row.cells[0]?.textContent)), ['地域乙', '地域甲'])
  await header('排序').locator('.arco-table-cell-with-sorter').click()
  assert.deepEqual(await page.locator('tbody tr').evaluateAll(rows => rows.map(row => row.cells[0]?.textContent)), ['地域甲', '地域乙'])

  await page.goto(`${base}/roles`)
  await page.getByRole('columnheader', { name: '角色名称' }).waitFor()
  await page.getByRole('cell', { name: '角色甲', exact: true }).waitFor()
  await header('排序').locator('.arco-table-cell-with-sorter').click()
  let names = await page.locator('tbody tr').evaluateAll(rows => rows.map(row => row.cells[0]?.textContent))
  assert.deepEqual(names, ['角色甲', '角色丙', '角色乙'])
  await header('排序').locator('.arco-table-cell-with-sorter').click()
  names = await page.locator('tbody tr').evaluateAll(rows => rows.map(row => row.cells[0]?.textContent))
  assert.deepEqual(names, ['角色丙', '角色乙', '角色甲'])
  await filter('状态', '禁用')
  assert.equal(await page.getByRole('cell', { name: '角色乙', exact: true }).count(), 1)
  assert.equal(await page.getByRole('cell', { name: '角色甲', exact: true }).count(), 0)
  assert.equal(await page.getByRole('cell', { name: '角色丙', exact: true }).count(), 0)
  await noOverflow()
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto(`${base}/members?status=0&sort_by=created_at&sort_order=desc`); await ready()
  await noOverflow()
  await page.screenshot({ path: `${output}/column-controls-mobile.png` })
  assert.deepEqual(errors, []); assert.equal(writes, 0)
  console.log('通过：表头升降序/取消、状态 0/清空、查询区同步、条件组合、URL/刷新/返回、完整本地列表排序和筛选、390px 无溢出；模拟接口，业务写入 0。')
} finally { await browser.close() }
