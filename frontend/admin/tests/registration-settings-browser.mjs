import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = '/tmp/howstack-registration-settings'; await mkdir(output, { recursive: true })
let values = { mobile_enabled: 1, email_enabled: 0, mobile_verification: 1, email_verification: 1 }
const writes = [], errors = []
const browser = await chromium.launch({ headless: true })
async function context(canUpdate) {
 const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } })
 await ctx.route('**/api/admin/v1/**', async route => {
  const req = route.request(), path = new URL(req.url()).pathname.replace('/api/admin/v1', '')
  let data = { available: false }
  if (path === '/auth/me') data = { user: { id: 1, username: 'mock', nickname: '测试管理员', is_founder: false }, permissions: ['admin.page.settings.view', ...(canUpdate ? ['admin.page.settings.update'] : [])] }
  if (path === '/settings/categories') data = [{ id: 4, code: 'registration', name: '注册与登录', sort_order: 25 }]
  if (path === '/settings/registration') {
   if (req.method() === 'PUT') { values = req.postDataJSON(); writes.push(values) }
   data = values
  }
  await route.fulfill({ json: { code: 0, message: 'ok', data, request_id: 'settings-mock' } })
 })
 const page = await ctx.newPage(); page.on('pageerror', error => errors.push(error.message)); page.setDefaultTimeout(8000)
 return [ctx, page]
}
try {
 const [ctx, page] = await context(true)
 await page.goto(`${base}/settings?tab=registration`)
 const panel = page.locator('#registration-settings')
 await panel.getByRole('switch').first().waitFor()
 assert.deepEqual(await panel.getByRole('switch').evaluateAll(nodes => nodes.map(node => node.getAttribute('aria-checked'))), ['true', 'true', 'false', 'true'])
 await panel.getByRole('switch').nth(2).click(); await panel.getByRole('switch').nth(3).click()
 await panel.getByRole('button', { name: '保存设置' }).click()
 await page.getByText('保存成功', { exact: true }).waitFor()
 assert.deepEqual(writes[0], { mobile_enabled: 1, email_enabled: 1, mobile_verification: 1, email_verification: 0 })
 await page.screenshot({ path: `${output}/desktop.png`, fullPage: true })
 await page.setViewportSize({ width: 390, height: 844 }); await page.reload(); await panel.getByRole('switch').first().waitFor()
 assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
 await page.screenshot({ path: `${output}/mobile.png`, fullPage: true }); await ctx.close()
 const [viewer, read] = await context(false); await read.goto(`${base}/settings?tab=registration`)
 await read.locator('#registration-settings').getByRole('switch').first().waitFor()
 assert.equal(await read.getByRole('button', { name: '保存设置' }).count(), 0)
 for (const node of await read.locator('#registration-settings').getByRole('switch').all()) assert(await node.isDisabled())
 await viewer.close(); assert.deepEqual(errors, [])
 console.log(JSON.stringify({ passed: true, mockWrites: writes.length, realWrites: 0, widths: [1440, 390] }))
} finally { await browser.close() }
