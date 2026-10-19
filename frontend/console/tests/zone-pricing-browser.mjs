import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
import { consoleBootstrap } from './console-bootstrap-fixture.mjs'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
const output = '/tmp/howstack-zone-pricing'; await mkdir(output, { recursive: true })
const errors = []
let currentPage
try {
 for (const mobile of [false, true]) {
  const port = mobile ? 5177 : 5176, base = `http://127.0.0.1:${port}/console`
  const context = await browser.newContext({ viewport: { width: mobile ? 390 : 1440, height: 900 }, isMobile: mobile, hasTouch: mobile, ...(mobile ? { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1' } : {}) })
  const page = await context.newPage(); currentPage = page; page.on('pageerror', error => errors.push(error.message))
  let orders = 0
  const list = [{ id: 3, name: '云服务器', version_id: 7, product_type: 'cloud_server', description: '按需选配', purchase_url: '/console/purchase/cloud-server?offer=3' }]
  const minor = selection => (selection.duration_months ?? 1) * (selection.zone_id === 12 ? 4500 : 2000) * (selection.quantity ?? 1)
  const format = (data, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify({ code: status === 200 ? 0 : 1001, message: '测试响应', data }) })
  await context.route('**/api/public/v1/products**', async route => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/products')) return route.fulfill(format(list))
    const body = route.request().postDataJSON(), s = body.selection
    if (path.endsWith('/quote')) return route.fulfill(format({ currency: 'CNY', configuration: s, quantity: s.quantity ?? 1, unit_amount_minor: minor({ ...s, quantity: 1 }), amount_minor: minor(s), subtotal_minor: minor(s), discount_minor: 0, unit_breakdown: [{ key: 'compute', label: '计算规格', quantity: 1, unit_price_minor: s.zone_id === 12 ? 4500 : 2000, amount_minor: minor({ ...s, quantity: 1 }) }] }))
    const options = {
      regions: [{ id: 1, name: '华东' }], zones: s.region_id ? [{ id: 11, name: '华东一区' }, { id: 12, name: '华东二区' }] : [],
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
    if (path === '/auth/me') return route.fulfill(format(null, 401))
    if (path === '/orders' && route.request().method() === 'POST') orders++
    return route.fulfill(format(null, 404))
  })
  await page.goto(`${base}/purchase/cloud-server?offer=3`)
  await page.getByText('每台 ¥20.00 × 1 台', { exact: true }).waitFor()
  const selectZone = async name => {
    if (mobile) {
      const field = page.locator('.m-purchase-control').filter({ has: page.locator('span').getByText('可用区', { exact: true }) })
      await field.getByRole('button').click()
      await page.getByText(name, { exact: true }).tap()
      await page.waitForFunction(name => [...document.querySelectorAll('.arco-picker-column-item.selected')].some(item => item.textContent === name), name)
      await page.getByText('确认', { exact: true }).click()
    } else {
      await page.getByLabel('可用区', { exact: true }).click()
      await page.getByRole('option', { name, exact: true }).click()
    }
  }
  await selectZone('华东二区')
  await page.getByText('每台 ¥45.00 × 1 台', { exact: true }).waitFor()
  assert.equal(await page.getByText('每台 ¥20.00 × 1 台', { exact: true }).count(), 0)
  let saved = await page.evaluate(() => JSON.parse(sessionStorage.getItem('console.purchase.cloud-server.v1')))
  assert.equal(saved.selection.zone_id, 12)
  assert.equal(saved.selection.flavor_id, 31)
  assert.equal(saved.selection.system_disk.type_id, 51)
  assert.equal('amount_minor' in saved, false, '浏览器不保存报价金额')
  await page.reload()
  await page.getByText('每台 ¥45.00 × 1 台', { exact: true }).waitFor()
  for (const width of mobile ? [390, 320] : [1440, 390]) {
    await page.setViewportSize({ width, height: 900 })
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true)
    await page.screenshot({ path: `${output}/${mobile ? 'mobile' : 'pc'}-${width}.png`, fullPage: true })
  }
  await selectZone('华东一区')
  await page.getByText('每台 ¥20.00 × 1 台', { exact: true }).waitFor()
  assert.equal(orders, 0)
  assert.deepEqual(errors, [])
  await context.close()
  console.log(`${mobile ? '移动端' : 'PC'}：相同规格/存储切换可用区重新报价，刷新恢复及窄屏检查通过（模拟接口）`)
 }
} catch (error) {
 if (currentPage) console.error((await currentPage.locator('body').innerText()).slice(-2000))
 throw error
} finally { await browser.close() }
