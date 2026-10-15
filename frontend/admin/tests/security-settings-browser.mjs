import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'

const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = '/tmp/howstack-security-settings'
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
const errors = []
const writes = []
let security = { session_expire: 120, login_attempts: 10, login_window: 60 }
let entry = { admin_path: 'admin' }
let failure = ''

async function open(canUpdate) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } })
  await context.route('**/api/admin/v1/**', async route => {
    const request = route.request()
    const path = new URL(request.url()).pathname.replace('/api/admin/v1', '')
    let data = { available: false }, status = 200, message = '成功'
    if (path === '/auth/me') data = { user: { id: 1, username: 'mock', nickname: '测试管理员', is_founder: false }, permissions: ['admin.page.settings.view', ...(canUpdate ? ['admin.page.settings.update'] : [])] }
    if (path === '/settings/categories') data = [{ id: 2, code: 'security', name: '安全设置', sort_order: 20 }]
    if (path === '/settings/security') {
      if (request.method() === 'PUT') {
        writes.push({ path, data: request.postDataJSON() })
        if (failure === 'security') { status = 503; message = '安全策略保存失败' }
        else security = request.postDataJSON()
      }
      data = security
    }
    if (path === '/settings/admin-entry') {
      if (request.method() === 'PUT') {
        const values = request.postDataJSON()
        writes.push({ path, data: values })
        if (failure === 'entry') { status = 409; message = '后台入口已由其他管理员修改'; entry = { admin_path: 'other-admin' } }
        else { assert.equal(values.current_admin_path, entry.admin_path); entry = { admin_path: values.admin_path } }
      }
      data = entry
    }
    await route.fulfill({ status, json: { code: status === 200 ? 0 : status, message, data, request_id: 'security-settings-mock' } })
  })
  await context.route('**/new-admin/settings?tab=security', route => route.fulfill({ contentType: 'text/html', body: '<p>新后台入口</p>' }))
  const page = await context.newPage()
  page.on('pageerror', error => errors.push(error.message))
  page.setDefaultTimeout(8000)
  await page.goto(`${base}/settings?tab=security`)
  await page.getByPlaceholder('例如：manage-center').waitFor()
  await page.waitForFunction(() => document.querySelector('input[placeholder="例如：manage-center"]')?.value.length > 0)
  return { context, page }
}

try {
  const { context, page } = await open(true)
  const panel = page.locator('#security-settings')
  const save = panel.getByRole('button', { name: '保存设置', exact: true })
  await save.waitFor({ state: 'visible' })
  await page.waitForFunction(() => !document.querySelector('#security-settings button[type="submit"]')?.disabled)
  await page.waitForTimeout(200)
  assert.equal(await panel.locator('button[type="submit"]').count(), 1)
  await page.screenshot({ path: `${output}/desktop.png`, fullPage: true })
  await panel.getByPlaceholder('例如：manage-center').fill('bad/path')
  await save.click()
  await panel.getByText('请输入3–32位的小写字母、数字或连字符，并以字母开头').waitFor()
  assert.equal(writes.length, 0)
  await panel.getByPlaceholder('例如：manage-center').fill('admin')
  await panel.getByPlaceholder('请输入后台登录有效期').fill('180')
  await save.click()
  await page.getByText('保存成功', { exact: true }).waitFor()
  assert.deepEqual(writes, [{ path: '/settings/security', data: { session_expire: 180, login_attempts: 10, login_window: 60 } }])
  await page.setViewportSize({ width: 390, height: 844 })
  await page.reload()
  await save.waitFor({ state: 'visible' })
  await page.waitForFunction(() => !document.querySelector('#security-settings button[type="submit"]')?.disabled)
  await page.waitForTimeout(200)
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
  assert.equal(await panel.locator('button[type="submit"]').count(), 1)
  await page.screenshot({ path: `${output}/mobile.png`, fullPage: true })

  failure = 'security'
  await panel.getByPlaceholder('例如：manage-center').fill('new-admin')
  await save.click()
  await page.getByText('安全策略保存失败', { exact: true }).waitFor()
  assert.equal(writes.filter(write => write.path === '/settings/admin-entry').length, 0)
  assert.equal(await panel.getByPlaceholder('例如：manage-center').inputValue(), 'new-admin')

  failure = 'entry'
  await save.click()
  await page.getByText('登录安全设置已保存，但后台入口保存失败：后台入口已由其他管理员修改', { exact: true }).waitFor()
  assert(await save.isDisabled())
  assert.equal(new URL(page.url()).pathname, '/admin/settings')
  await page.getByRole('button', { name: '重新加载', exact: true }).click()
  await page.waitForFunction(() => !document.querySelector('#security-settings button[type="submit"]')?.disabled)
  assert.equal(await panel.getByPlaceholder('例如：manage-center').inputValue(), 'new-admin')
  assert.equal(await panel.getByPlaceholder('请输入后台登录有效期').inputValue(), '180')

  failure = ''
  await save.click()
  await page.waitForURL('**/new-admin/settings?tab=security')
  assert.deepEqual(writes.slice(-2), [
    { path: '/settings/security', data: { session_expire: 180, login_attempts: 10, login_window: 60 } },
    { path: '/settings/admin-entry', data: { admin_path: 'new-admin', current_admin_path: 'other-admin' } },
  ])
  await context.close()
  const viewer = await open(false)
  assert.equal(await viewer.page.getByRole('button', { name: '保存设置', exact: true }).count(), 0)
  assert(await viewer.page.getByPlaceholder('例如：manage-center').isDisabled())
  assert(await viewer.page.getByPlaceholder('请输入后台登录有效期').isDisabled())
  await viewer.context.close()
  assert.deepEqual(errors, [])
  console.log(JSON.stringify({ passed: true, widths: [1440, 390], checks: ['single-save', 'validation-before-write', 'unchanged-entry', 'failed-policy', 'entry-conflict', 'preserve-input', 'redirect-after-save', 'view-only'], realWrites: 0 }))
} finally { await browser.close() }
