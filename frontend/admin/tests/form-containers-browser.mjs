import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = '/tmp/howstack-form-containers'
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
const resources = Object.fromEntries(['regions', 'zones', 'lines', 'flavor_groups', 'flavors', 'image_types', 'images', 'disk_types', 'protections', 'network_policies'].map(key => [key, []]))
const disk = { id: 4, name: '历史SSD', code: 'historical_ssd', description: '原描述', status: 1, sort: 2,
  zone_ids: [1], min_gib: 20, max_gib: 2048, step_gib: 1, max_count: 8,
  read_mbps: 500, write_mbps: 300, read_iops: null, write_iops: null,
  prices: { hour: 12, day: 345, month: 1234, year: 5678 } }
resources.zones = [{ id: 1, name: '测试可用区', code: 'mock_zone', region_id: 1, status: 1, sort: 0, description: '' }]
resources.disk_types = [disk]
const connection = { id: 3, name: '模拟供应商', adapter_id: 'magic_cloud', adapter_name: '魔方云', adapter_version: '1.0',
  adapter_available: true, adapter_accepting: true, requires_retest: false, status: 1, revision: 2,
  adapter_catalogs: [{ resource_kind: 'zones', kind: 'zones', label: '可用区' }],
  configuration: {}, configured_secrets: {}, capabilities: { catalog: true, production_ready: false, product_types: ['compute'] } }
const delivery = { id: 8, name: '模拟交付规则', description: '', connection_id: 3, status: 1, priority: 0, line_ids: [], resource_ids: {}, revision: 0 }
let connected = true
const account = () => ({ configured: true, connected, installation_id: 'mock-instance', reconnect_required: false, pairing: null,
  connection: connected ? { account_name: '模拟绑定账号', edition_name: '社区版', edition_code: 'community' } : null })
const member = { id: 7, nickname: '模拟会员', mobile: '13800000000', email: '', description: '', group_id: null, level_id: null, status: 0 }
const entry = { id: 9, kind: 'news', category_id: 1, title: '历史公告', summary: '历史摘要', body: '# 原有正文', sort_order: 2, status: 'draft', published_at: null, created_at: '2026-10-06 12:00:00' }
const category = { id: 1, name: '产品公告', parent_id: null, status: 1, sort_order: 0 }
const draft = { resource_ids: Object.fromEntries(Object.keys(resources).map(key => [key, []])), cycles: ['month'], quantity_limit: 10, setup_minor: 1234, system_disk_included_gib: 30, provider_connection_id: 0, price_overrides: {}, delivery_rule_id: 0, network_policy_id: 0,
  network: { bandwidth_min: 1, bandwidth_max: 100, bandwidth_step: 1, ip_min: 1, ip_max: 1, bandwidth_billing_mode: 'flat', bandwidth_tiers: [], bandwidth_prices: { month: 23 }, ip_prices: { month: 10 } }, trial_policy: { enabled: false, hours: 24, limit_per_member: 1 }, refund_policy: { enabled: false, window_hours: 168, prorated: true, keep_setup_fee: true }, renewal_policy: { pricing: 'current', auto_renew_enabled: false } }
const offer = { id: 5, product_id: 1, name: '历史销售方案', code: 'history', description: '', status: 1, revision: 4, published_version_id: null, draft }
const permissions = ['members', 'administrators', 'roles', 'departments', 'member-groups', 'member-levels', 'compute-resources', 'provider-connections', 'cloud-products', 'cloud-delivery-rules'].flatMap(resource => ['view', 'create', 'update'].map(action => `admin.${resource}.${action}`))
permissions.push('admin.platform.connect', 'admin.license.view', 'admin.updates.view', 'admin.provider-connections.map', 'admin.provider-connections.sync', 'admin.cloud-servers.view', ...['help', 'news'].flatMap(kind => ['entries', 'categories'].flatMap(resource => ['view', 'create', 'update'].map(action => `admin.content.${kind}-${resource}.${action}`))), ...['types', 'quick-replies'].flatMap(kind => ['view', 'create', 'update'].map(action => `admin.tickets.${kind}.${action}`)))
const writes = []
let allowed = permissions
async function mock(route) {
  const request = route.request()
  const path = new URL(request.url()).pathname.replace('/api/admin/v1', '')
  let data = { data: [], total: 0, page: 1, page_size: 10 }
  if (path === '/official-platform/connection/disconnect') connected = false
  if (request.method() !== 'GET') { writes.push({ path, method: request.method(), data: request.postDataJSON() }); data = request.postDataJSON() }
  else if (path === '/auth/me') data = { user: { id: 1, username: 'mock_admin', nickname: '模拟管理员', is_founder: true }, permissions: allowed }
  else if (path === '/members') data = { data: [member], total: 1 }
  else if (path === '/member-catalog/options') data = { groups: [], levels: [] }
  else if (path === '/roles') data = [{ id: 1, name: '测试角色', code: 'test', status: 1, permissions: [], department_ids: [], data_scope: 'self', is_system: false }]
  else if (path === '/departments') data = [{ id: 1, name: '根部门', is_root: true, parent_id: null, status: 1 }]
  else if (path === '/compute-resources') data = resources
  else if (path === '/provider-connections') data = [connection]
  else if (path === '/cloud-servers/delivery-rules') data = [delivery]
  else if (path === '/provider-connections/references') data = resources
  else if (path === '/provider-connections/3/mappings') data = []
  else if (path === '/official-platform/connection') data = account()
  else if (path === '/official-platform/license') data = { edition_name: '社区版', status_text: '无需商业授权', account: account() }
  else if (path === '/system-updates') data = { current_version: '0.1.0', update: null, jobs: [] }
  else if (path === '/system-updates/notice') data = { available: false }
  else if (path === '/provider-connections/adapters') data = [{ id: 'magic_cloud', plugin_id: 'magic_cloud', name: '魔方云', version: '1.0', sdk_version: 'v1', catalogs: [], configuration_fields: Array.from({ length: 12 }, (_, index) => ({ key: `field_${index}`, label: `配置项 ${index + 1}`, type: 'text', required: false })) }]
  else if (path === '/cloud-servers/delivery-rules/references') data = { resources, lines: [], connections: [connection] }
  else if (path === '/cloud-products/references') data = { resources, connections: [], delivery_rules: [] }
  else if (path === '/cloud-products') data = { data: [offer], total: 1 }
  else if (path === '/cloud-products/5') data = offer
  else if (/\/content\/(news|help)\/categories$/.test(path)) data = { data: [category], total: 1 }
  else if (/\/content\/(news|help)\/entries$/.test(path)) data = { data: [entry], total: 1 }
  else if (/\/content\/(news|help)\/entries\/9$/.test(path)) data = entry
  return route.fulfill({ json: { code: 0, message: '成功', data, request_id: 'form-container-mock' } })
}
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } })
  await context.route('**/api/admin/v1/**', mock)
  const page = await context.newPage()
  const errors = []
  page.on('pageerror', error => errors.push(error.message))
  const field = (container, label) => container.locator('.arco-form-item').filter({ has: page.locator('label').getByText(label, { exact: true }) })
  async function noOverflow() { assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), '页面不能横向溢出') }
  async function checkModal(expectedWidth = 560, expectedColumns = 1) {
    const modal = page.locator('.arco-modal:visible')
    await modal.waitFor()
    await page.waitForFunction(width => { const element = document.querySelector('.arco-modal'); return element && Math.abs(element.getBoundingClientRect().width - Math.min(width, innerWidth - 24)) < 0.1 }, expectedWidth)
    const geometry = await modal.evaluate(element => {
      const rect = element.getBoundingClientRect()
      return { top: rect.top, bottom: rect.bottom, overflow: [...element.querySelectorAll('*')].filter(node => /auto|scroll/.test(getComputedStyle(node).overflowY) && node.scrollHeight > node.clientHeight + 1).length,
        columns: getComputedStyle(element.querySelector('form')).display === 'grid' ? getComputedStyle(element.querySelector('form')).gridTemplateColumns.split(' ').length : 1, viewportHeight: innerHeight }
    })
    assert(geometry.top >= -1 && geometry.bottom <= geometry.viewportHeight + 1, JSON.stringify(geometry))
    assert.equal(geometry.overflow, 0)
    assert.equal(geometry.columns, expectedColumns)
    await noOverflow()
    return modal
  }
  async function checkDrawer(expectedWidth = 640) {
    const panel = page.locator('.arco-drawer:visible')
    await panel.waitFor()
    await page.waitForFunction(width => {
      const rect = document.querySelector('.arco-drawer')?.getBoundingClientRect()
      return rect && Math.abs(rect.width - Math.min(width, innerWidth - 24)) < 1 && rect.right <= innerWidth + 1
    }, expectedWidth)
    assert.equal(await page.locator('.arco-modal:visible').count(), 0)
    assert.notEqual(await panel.locator('form').evaluate(element => getComputedStyle(element).display), 'grid')
    await noOverflow()
    return panel
  }
  for (const [path, label] of [['members', '新建会员'], ['administrators', '新建用户'], ['roles', '新建角色'], ['departments', '新建部门'], ['member-groups', '新建会员分组'], ['member-levels', '新建会员等级'], ['support/ticket-types', '新建工单类型'], ['content/news-categories', '新建公告分类']]) {
    await page.goto(`${base}/${path}`)
    await page.getByRole('button', { name: label, exact: true }).click()
    if (['members', 'administrators'].includes(path)) await checkDrawer()
    else await checkModal(path === 'content/news-categories' || path === 'support/ticket-types' ? 520 : 560, 1)
  }
  console.log('通过：短表单窄单列Modal，中等会员/用户CRUD单列Drawer，无页面溢出')
  await page.goto(`${base}/members`)
  await page.getByRole('button', { name: '新建会员', exact: true }).click()
  const modal = await checkDrawer()
  await field(modal, '昵称').locator('input').fill('新增模拟会员')
  await page.screenshot({ path: `${output}/members-desktop.png` })
  await page.setViewportSize({ width: 390, height: 844 })
  const mobilePanel = page.locator('.arco-modal:visible, .arco-drawer:visible')
  const drawer = page.locator('.arco-drawer:visible')
  await mobilePanel.waitFor()
  assert.equal(await field(mobilePanel, '昵称').locator('input').inputValue(), '新增模拟会员')
  await field(mobilePanel, '手机号').locator('input').fill('13800000001')
  await field(mobilePanel, '密码').locator('input').fill('MockPassword123')
  await mobilePanel.getByRole('button', { name: '确定', exact: true }).click()
  await mobilePanel.waitFor({ state: 'hidden' })
  assert.equal(writes.at(-1).path, '/members')
  assert.equal(writes.at(-1).data.nickname, '新增模拟会员')
  assert.equal(writes.at(-1).data.status, 1)
  assert.equal(writes.at(-1).data.group_id, null)
  assert.equal(writes.at(-1).data.level_id, null)
  await page.locator('.page-card').getByText('编辑', { exact: true }).click()
  await mobilePanel.waitFor()
  assert.equal(await field(mobilePanel, '昵称').locator('input').inputValue(), '模拟会员')
  assert.equal(await mobilePanel.getByRole('switch').getAttribute('aria-checked'), 'false')
  await page.waitForFunction(() => { const element = document.querySelector('.arco-drawer') || document.querySelector('.arco-modal'); if (!element) return false; const rect = element.getBoundingClientRect(); return rect.x >= 0 && rect.right <= innerWidth && Math.abs(rect.width - (innerWidth - 24)) < 1 })
  await page.screenshot({ path: `${output}/members-390.png` })
  await noOverflow()
  await page.locator('.arco-drawer-wrapper:visible, .arco-modal-wrapper:visible').click({ position: { x: 2, y: 120 } })
  await mobilePanel.waitFor({ state: 'hidden' })
  console.log('通过：按实际内容保留容器、输入保留、提交类型、编辑回填和遮罩关闭')
  // Short forms remain Modal even on a phone; height, not device type, selects Drawer.
  for (const [width, height] of [[390, 844], [320, 568]]) {
    await page.setViewportSize({ width, height })
    await page.goto(`${base}/content/news-categories`)
    await page.getByRole('button', { name: '新建公告分类', exact: true }).click()
    await checkModal(520, 1)
    assert.equal(await page.locator('.arco-drawer:visible').count(), 0)
  }
  await page.setViewportSize({ width: 1440, height: 380 })
  await page.goto(`${base}/members`)
  await page.getByRole('button', { name: '新建会员', exact: true }).click()
  await drawer.waitFor()
  console.log('通过：短表单不按设备强制改抽屉；中等表单明确使用抽屉')
  await page.setViewportSize({ width: 1440, height: 900 })
  for (const [tab, name] of [['regions', '新建地域'], ['storage', '新建存储类型'], ['images', '新建镜像'], ['delivery', '新建交付规则']]) {
    await page.goto(`${base}/products/cloud-servers?tab=${tab}`)
    await page.getByRole('button', { name, exact: true }).click()
    if (tab === 'regions') await checkModal(560, 1)
    else if (tab === 'storage') {
      await page.waitForURL('**/products/cloud-servers?tab=storage&edit=new')
      await field(page, '读取速度').waitFor()
      assert.equal(await page.locator('.arco-modal:visible, .arco-drawer:visible').count(), 0)
    } else await drawer.waitFor()
    await noOverflow()
  }
  await page.goto(`${base}/providers/connections`)
  await page.getByRole('button', { name: '添加供应商', exact: true }).click()
  await drawer.waitFor()
  await field(drawer, '对接平台').locator('.arco-select').click()
  await page.getByText('魔方云', { exact: true }).last().click()
  await field(drawer, '配置项 12').waitFor()
  await drawer.locator('.arco-drawer-content').evaluate(element => { element.scrollTop = element.scrollHeight })
  assert(await drawer.getByRole('button', { name: '确定', exact: true }).isVisible())
  console.log('通过：简单资源弹窗，存储完整页面，镜像/交付/动态供应商配置使用抽屉')
  for (const width of [1440, 390]) {
    await page.setViewportSize({ width, height: 900 })
    await page.goto(`${base}/content/news`)
    await page.getByRole('button', { name: '新建新闻公告', exact: true }).click()
    await page.waitForURL('**/content/news?edit=new')
    await page.locator('.bytemd').waitFor()
    assert.equal(await page.locator('.arco-modal:visible, .arco-drawer:visible').count(), 0)
    await noOverflow()
    await page.screenshot({ path: `${output}/news-page-${width}.png` })
    await page.getByRole('button', { name: '返回列表', exact: true }).click()
    await page.waitForURL('**/content/news')
    await page.goto(`${base}/content/news?edit=9`)
    await page.locator('.bytemd').waitFor()
    assert.equal(await field(page, '标题').locator('input').inputValue(), '历史公告')
    await page.reload()
    await page.locator('.bytemd').waitFor()
    assert.equal(await field(page, '标题').locator('input').inputValue(), '历史公告')
    await page.getByRole('button', { name: '保存', exact: true }).click()
    await page.waitForURL('**/content/news')
    assert.equal(writes.at(-1).method, 'PUT')
    assert.equal(writes.at(-1).data.body, '# 原有正文')
    assert.equal(writes.at(-1).data.sort_order, 2)
    await page.goto(`${base}/products/cloud-servers?tab=offers&edit=5`)
    await page.locator('#cloud-offer-form').waitFor()
    assert.equal(await page.locator('.arco-modal:visible, .arco-drawer:visible').count(), 0)
    await noOverflow()
    await page.reload()
    await page.locator('#cloud-offer-form').waitFor()
    await page.getByRole('button', { name: '保存草稿', exact: true }).click()
    await page.waitForURL('**/products/cloud-servers?tab=offers')
    assert.equal(writes.at(-1).path, '/cloud-products/5')
    assert.equal(writes.at(-1).data.revision, 4)
    assert.equal(writes.at(-1).data.draft.setup_minor, 1234)
    assert.equal(writes.at(-1).data.draft.system_disk_included_gib, 30)
  }
  console.log('通过：正文/销售方案完整页面，刷新、返回、Tab 保留及原金额/版本提交')
  for (const width of [1440, 390]) {
    await page.setViewportSize({ width, height: 900 })
    await page.goto(`${base}/products/cloud-servers?tab=storage&edit=4`)
    await field(page, '读取速度').waitFor()
    assert.equal(await field(page, '名称').locator('input').inputValue(), '历史SSD')
    await page.reload()
    await field(page, '读取速度').waitFor()
    assert.equal(await field(page, '月价格').locator('input').inputValue(), '12.34')
    await noOverflow()
    await page.screenshot({ path: `${output}/storage-page-${width}.png`, fullPage: true })
    await page.getByRole('button', { name: '保存', exact: true }).click()
    await page.waitForURL('**/products/cloud-servers?tab=storage')
    assert.equal(writes.at(-1).path, '/compute-resources/disk_types/4')
    assert.equal(writes.at(-1).data.prices.month, 1234)
    assert.equal(writes.at(-1).data.read_iops, null)
    assert.deepEqual(writes.at(-1).data.zone_ids, [1])
    assert.equal(writes.at(-1).data.status, 1)
    await page.goto(`${base}/products/cloud-servers?tab=storage&edit=999`)
    await page.getByText('存储类型不存在或已删除', { exact: true }).waitFor()
    assert(await page.getByRole('button', { name: '保存', exact: true }).isDisabled())
    for (const path of ['providers/connections', 'products/cloud-servers?tab=delivery']) {
      await page.goto(`${base}/${path}`)
      await page.locator('.page-card').getByText('资源对应', { exact: true }).click()
      await page.waitForURL(url => url.searchParams.get('mapping') === '3')
      await page.getByText('已设置的资源对应关系', { exact: true }).waitFor()
      assert.equal(await page.locator('.arco-modal:visible, .arco-drawer:visible').count(), 0)
      await page.reload()
      await page.getByText('已设置的资源对应关系', { exact: true }).waitFor()
      await noOverflow()
      await page.screenshot({ path: `${output}/mapping-${path.startsWith('providers') ? 'provider' : 'delivery'}-${width}.png`, fullPage: true })
      await page.getByRole('button', { name: '返回列表', exact: true }).click()
      assert.equal(new URL(page.url()).searchParams.has('mapping'), false)
      if (path.includes('delivery')) assert.equal(new URL(page.url()).searchParams.get('tab'), 'delivery')
    }
    await page.goto(`${base}/providers/connections`)
    await page.locator('.page-card').getByText('资源对应', { exact: true }).click()
    await page.getByText('已设置的资源对应关系', { exact: true }).waitFor()
    await page.goBack()
    await page.getByRole('button', { name: '添加供应商', exact: true }).waitFor()
  }
  console.log('通过：存储/资源对应独立页面，桌面与390px、刷新回填、原价格/空值提交、不存在、返回与后退')
  await page.setViewportSize({ width: 1440, height: 900 })
  await page.goto(`${base}/maintenance`)
  await page.getByRole('button', { name: '授权码激活', exact: true }).click()
  const accountPanel = page.locator('.arco-modal:visible')
  await accountPanel.getByRole('button', { name: '解除绑定', exact: true }).click()
  assert.equal(await page.locator('.arco-modal:visible').count(), 1)
  await accountPanel.getByRole('button', { name: '取消', exact: true }).click()
  assert.equal(connected, true)
  await accountPanel.getByRole('button', { name: '解除绑定', exact: true }).click()
  await accountPanel.getByRole('button', { name: '确认解除绑定', exact: true }).click()
  await accountPanel.getByRole('button', { name: '绑定账号', exact: true }).waitFor()
  assert.equal(connected, false)
  console.log('通过：账号解绑在当前Modal内确认，无Modal叠加，取消不写入，确认才模拟解绑')
  allowed = permissions.filter(permission => !permission.endsWith('.create') && !permission.endsWith('.update'))
  await page.goto(`${base}/content/news?edit=9`)
  await page.getByText('没有编辑权限或分类查看权限', { exact: true }).waitFor()
  assert(await page.getByRole('button', { name: '保存', exact: true }).isDisabled())
  await page.goto(`${base}/products/cloud-servers?tab=offers&edit=new`)
  await page.getByText('没有销售方案编辑权限', { exact: true }).waitFor()
  assert(await page.getByRole('button', { name: '保存草稿', exact: true }).isDisabled())
  await page.goto(`${base}/products/cloud-servers?tab=storage&edit=4`)
  await page.getByText('没有存储类型编辑权限', { exact: true }).waitFor()
  assert(await page.getByRole('button', { name: '保存', exact: true }).isDisabled())
  allowed = allowed.filter(permission => permission !== 'admin.provider-connections.view')
  await page.goto(`${base}/products/cloud-servers?tab=delivery&mapping=3`)
  await page.getByText('没有资源供应商查看权限', { exact: true }).waitFor()
  assert.equal(await page.getByText('已设置的资源对应关系', { exact: true }).count(), 0)
  assert.deepEqual(errors, [])
  console.log('通过：编辑 URL 权限拦截，无浏览器异常；写入全部使用模拟接口')
} finally { await browser.close() }
