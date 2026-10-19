import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
import { consoleBootstrap } from './console-bootstrap-fixture.mjs'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
const output = '/tmp/howstack-purchase'; await mkdir(output, { recursive: true })
const errors = []
let currentPage
try {
 for (const mobile of [false, true]) {
  const port = mobile ? 5177 : 5176, base = `http://127.0.0.1:${port}/console`
  const context = await browser.newContext({ viewport: { width: mobile ? 390 : 1440, height: 900 }, isMobile: mobile })
  const page = await context.newPage(); currentPage = page; page.on('pageerror', error => errors.push(error.message))
  let authenticated = false, orders = 0, creates = 0
  const user = { id: '728461931', mobile: '13800138000', email: null, nickname: '购买测试会员' }
  const list = [{ id: 3, name: '云服务器', version_id: 7, product_type: 'cloud_server', description: '按需选配', purchase_url: '/console/purchase/cloud-server?offer=3' }]
  const minor = selection => (selection.duration_months ?? 1) * 2000 * (selection.quantity ?? 1)
  const format = (data, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify({ code: status === 200 ? 0 : 1001, message: '测试响应', data }) })
  await context.route('**/api/public/v1/products**', async route => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/products')) return route.fulfill(format(list))
    const body = route.request().postDataJSON(), s = body.selection
    if (path.endsWith('/quote')) return route.fulfill(format({ currency: 'CNY', configuration: s, quantity: s.quantity ?? 1, unit_amount_minor: minor({ ...s, quantity: 1 }), amount_minor: minor(s), subtotal_minor: minor(s), discount_minor: 0, unit_breakdown: [{ key: 'compute', label: '计算规格', quantity: 1, unit_price_minor: 2000, amount_minor: minor({ ...s, quantity: 1 }) }] }))
    const options = {
      regions: [{ id: 1, name: '华东' }, { id: 2, name: '华南' }], zones: s.region_id ? [{ id: s.region_id === 1 ? 11 : 12, name: s.region_id === 1 ? '华东一区' : '华南一区' }] : [],
      lines: s.zone_id ? [{ id: 21, name: 'BGP' }] : [], flavor_groups: s.zone_id ? [{ id: 41, name: '通用型' }] : [],
      flavors: s.zone_id ? [{ id: 31, name: '基础型', cpu: 2, memory_mib: 2048, group_id: 41 }] : [],
      image_types: s.zone_id ? [{ id: 71, name: 'Ubuntu' }] : [], images: s.zone_id ? [{ id: 61, name: 'Ubuntu 24.04', type_id: 71, min_disk_gib: 20 }] : [],
      disk_types: s.zone_id ? [{ id: 51, name: 'SSD', min_gib: 20, max_gib: 1024, step_gib: 10, max_count: 4 }] : [], protections: [],
    }
    const band = { min: 0, max: 100, step: 1, default: 5, included: 5, price_minor: 0, pricing_mode: 'flat', tiers: [] }
    return route.fulfill(format({ schema_version: '2.0.0', configuration: s, options, invalid_fields: {}, quantity_limit: 3, system_disk_included_gib: 20,
      durations: s.zone_id ? [{ months: 1, label: '月付', discount_kind: 'rate', rate_bps: 10000 }, { months: 12, label: '年付', discount_kind: 'rate', rate_bps: 8000 }] : [], storage_policy: { default_type_id: 51, default_size_gib: 40, max_data_disks: 4 },
      line_network: s.line_id ? { billing_mode: 'fixed', egress: band, ingress: band, ip: { ...band, min: 1, max: 3, default: 1, included: 1, mode: 'range', options: [1, 2, 3] } } : null }))
  })
  await context.route('**/api/console/v1/**', async route => {
    const path = new URL(route.request().url()).pathname.replace('/api/console/v1', '')
    if (path === '/bootstrap') return route.fulfill(format(consoleBootstrap))
    if (path === '/auth/me') return route.fulfill(format(authenticated ? { user } : null, authenticated ? 200 : 401))
    if (path === '/auth/login') { authenticated = true; return route.fulfill(format({ user })) }
    if (path === '/orders' && route.request().method() === 'POST') {
      creates++; assert.ok(authenticated); const input = route.request().postDataJSON()
      assert.equal(input.offer_id, 3); assert.equal(input.version_id, 7); assert.equal(input.selection.quantity, 2)
      assert.equal(input.selection.system_disk.size_gib, 60); assert.equal(input.selection.network.egress_mbps, 8)
      assert.equal(input.login_password, 'Instance@123456'); assert.equal(input.selection.login_password, undefined); assert.equal(input.expected_amount_minor, 4000); assert.equal('member_id' in input, false); assert.match(input.request_key, /^[a-f0-9]{32}$/)
      orders++; await new Promise(resolve => setTimeout(resolve, 300)); return route.fulfill(format({ id: 99 }))
    }
    if (path === '/orders/99') return route.fulfill(format({ id: 99, order_no: 'ORDER-99', status: 'pending_payment', payment_status: 'unpaid', currency: 'CNY', payable_minor: 4000, paid_minor: 0, created_at: '2026-10-12 12:00:00', items: [{ product_name: '云服务器', quantity: 2, total_minor: 4000 }] }))
    return route.fulfill(format(null, 404))
  })
  await page.goto(`${base}/purchase/cloud-server?offer=3`)
  await page.getByRole('button', { name: '登录后继续', exact: true }).waitFor()
  await page.getByRole('button', { name: '登录后继续', exact: true }).waitFor({ state: 'visible' })
  await page.getByText('每台 ¥20.00 × 1 台', { exact: true }).waitFor()
  // 公开 aria-label 在 InputNumber 的包装节点上时，下钻至原生 input。
  const field = label => page.getByLabel(label, { exact: true }).locator('xpath=self::input | .//input').first()
  await field('购买数量').fill('2'); await field('购买数量').blur()
  await field('系统盘容量').fill('60'); await field('系统盘容量').blur()
  await field('上行带宽').fill('8'); await field('上行带宽').blur()
  await page.waitForFunction(() => { const value = JSON.parse(sessionStorage.getItem('console.purchase.cloud-server.v1') || '{}'); return value.selection?.quantity === 2 && value.selection?.system_disk?.size_gib === 60 && value.selection?.network?.egress_mbps === 8 })
  const before = await page.evaluate(() => JSON.parse(sessionStorage.getItem('console.purchase.cloud-server.v1')))
  await page.reload()
  await page.getByText('每台 ¥20.00 × 2 台', { exact: true }).waitFor()
  assert.equal(await field('购买数量').inputValue(), '2')
  assert.equal(await field('系统盘容量').inputValue(), '60')
  assert.equal(await field('上行带宽').inputValue(), '8')
  await page.getByRole('button', { name: '登录后继续', exact: true }).click()
  await page.getByRole('heading', { name: '欢迎登录' }).waitFor()
  await page.reload(); await page.getByRole('heading', { name: '欢迎登录' }).waitFor()
  await page.locator('input[autocomplete="username"]').fill('13800138000')
  await page.locator('input[type="password"]').fill('Test@123456')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.waitForURL('**/purchase/cloud-server?offer=3')
  await page.getByText('每台 ¥20.00 × 2 台', { exact: true }).waitFor()
  assert.equal(orders, 0, '登录后不能自动提交订单')
  assert.equal(await field('购买数量').inputValue(), '2')
  const restored = await page.evaluate(() => JSON.parse(sessionStorage.getItem('console.purchase.cloud-server.v1')))
  assert.deepEqual(restored.selection, before.selection)
  assert.equal(restored.request_key, before.request_key, '跨登录和刷新保留幂等意图')
  for (const width of mobile ? [320, 390, 430] : [1440, 390]) {
    await page.setViewportSize({ width, height: 900 }); await page.waitForTimeout(200)
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `购买页 ${width}px 不溢出`)
    await page.screenshot({ path: `${output}/${mobile ? 'mobile' : 'pc'}-${width}.png`, fullPage: true })
  }
  const initialPassword = page.locator('input[type=password]')
  await initialPassword.fill('short')
  await page.getByRole('button', { name: '提交订单', exact: true }).click()
  await page.getByText('实例密码须为8至72位且不包含空白字符', { exact: true }).waitFor()
  assert.equal(creates, 0, '实例密码前端校验失败不能请求创建订单')
  assert.equal((await page.evaluate(() => JSON.stringify({ ...sessionStorage }))).includes('short'), false)
  await initialPassword.fill('Instance@123456')
  await page.getByRole('button', { name: '提交订单', exact: true }).evaluate(button => { button.click(); button.click() })
  await page.waitForURL('**/finance/orders/99'); await page.getByText('ORDER-99', { exact: true }).waitFor()
  assert.equal(creates, 1); assert.equal(orders, 1)
  assert.equal(await page.evaluate(() => sessionStorage.getItem('console.purchase.cloud-server.v1')), null)
  await page.reload(); await page.getByText('ORDER-99', { exact: true }).waitFor()
  await context.close(); console.log(`${mobile ? '移动' : 'PC'}购买：匿名选配、刷新、登录恢复、无自动下单、单次提交及订单详情通过。`)
 }
 assert.deepEqual(errors, [])
} catch (error) { if (currentPage) { console.log(await currentPage.evaluate(() => ({draft: sessionStorage.getItem('console.purchase.cloud-server.v1'), inputs: Array.from(document.querySelectorAll('input'), item => ({label: item.getAttribute('aria-label'), value: item.value})), text: document.body.innerText.slice(0, 1600)}))); await currentPage.screenshot({path: output + '/failure.png', fullPage:true}) } throw error } finally { await browser.close() }
