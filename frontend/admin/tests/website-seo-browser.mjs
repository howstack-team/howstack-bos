import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = process.env.WEBSITE_BROWSER_OUTPUT ?? '/tmp/howstack-seo-ui'
const browser = await chromium.launch({ headless: true }), errors = []
const empty = { title: '', description: '', keywords: '', indexable: null }
function bundle() {
  return { revision: 1, active_theme_id: 'default', pages: [
    { id: 'home', scope: 'core', kind: 'core', title: '首页', path: '/', settings: { ...empty }, effective: { ...empty, title: '云业务平台', indexable: true }, sources: { title: 'default', keywords: 'default', description: 'default', indexable: 'page_default' } },
    { id: 'company', scope: 'theme:default', kind: 'custom', title: '公司介绍', path: '/company', settings: { ...empty }, effective: { ...empty, title: '公司介绍 - 云业务平台', indexable: true }, sources: { title: 'page_default', keywords: 'default', description: 'default', indexable: 'page_default' } },
  ] }
}
async function setup(permissions = ['admin.website.seo.view', 'admin.website.seo.update']) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } }), page = await context.newPage()
  const state = { catalog: bundle(), writes: [], status: 200 }
  page.on('pageerror', error => errors.push(error.message))
  await context.route('**/api/admin/v1/**', async route => {
    const request = route.request(), path = new URL(request.url()).pathname.replace('/api/admin/v1', '')
    let status = 200, data = [], message = 'ok'
    if (path === '/auth/me') data = { user: { id: 1, username: 'seo', nickname: 'SEO管理员', is_founder: false, last_login_at: null }, permissions }
    else if (path.startsWith('/website/seo') && request.method() === 'PUT') {
      const body = request.postDataJSON(); state.writes.push({ path, body }); status = state.status
      if (status === 409) { data = null; message = '修订已更新' }
      else if (status === 422) { data = { errors: { 'settings.title': ['标题不符合要求'] } }; message = '参数校验失败' }
      else {
        { const item = state.catalog.pages.find(page => path.endsWith(`/${page.id}`)); item.settings = body.settings; item.effective = { ...item.effective, ...body.settings, title: item.id === 'home' ? (body.settings.title ? `云业务平台 - ${body.settings.title}` : '云业务平台') : `${body.settings.title || item.title} - 云业务平台`, indexable: body.settings.indexable ?? true }; item.sources.title = 'override' }
        state.catalog.revision++; data = state.catalog
      }
    } else if (path === '/website/seo') data = state.catalog
    await route.fulfill({ status, json: { code: status >= 400 ? status * 100 : 0, message, data, request_id: 'seo-browser' } })
  })
  await page.goto(`${base}/website/seo`)
  await page.getByRole('row').filter({ has: page.getByText('/company', { exact: true }) }).waitFor()
  assert.equal(await page.getByRole('button', { name: '默认 SEO', exact: true }).count(), 0)
  return { context, page, state }
}
try {
  await mkdir(output, { recursive: true })
  const { context, page, state } = await setup()
  const row = page.getByRole('row').filter({ has: page.getByText('/company', { exact: true }) })
  await row.getByText('编辑', { exact: true }).click()
  await page.getByRole('textbox', { name: 'SEO 标题', exact: true }).fill('公司介绍与服务')
  await page.getByRole('textbox', { name: 'SEO 描述', exact: true }).fill('专属页面描述')
  await page.getByText('禁止', { exact: true }).last().click()
  await page.screenshot({ path: `${output}/edit-desktop.png` })
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await page.getByText('SEO 配置已保存', { exact: true }).waitFor()
  assert.equal(state.writes.at(-1).body.scope, 'theme:default')
  assert.equal(state.writes.at(-1).body.settings.indexable, false)
  assert.equal(state.writes.at(-1).body.revision, 1)
  await page.getByRole('row').filter({ has: page.getByText('/', { exact: true }) }).getByText('编辑', { exact: true }).click()
  await page.getByRole('textbox', { name: '首页副标题', exact: true }).fill('首页专属副标题')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await page.getByRole('textbox', { name: '首页副标题', exact: true }).waitFor({ state: 'hidden' })
  assert.equal(state.writes.at(-1).path, '/website/seo/pages/home')
  assert.equal(state.writes.at(-1).body.revision, 2)
  await page.screenshot({ path: `${output}/list-desktop.png` })
  await page.setViewportSize({ width: 390, height: 844 })
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true)
  await context.close()
  const readOnly = await setup(['admin.website.seo.view'])
  await readOnly.page.getByRole('row').filter({ has: readOnly.page.getByText('/company', { exact: true }) }).getByText('查看', { exact: true }).click()
  assert.equal(await readOnly.page.getByRole('textbox', { name: 'SEO 标题', exact: true }).isDisabled(), true)
  assert.equal(await readOnly.page.getByRole('button', { name: '保存', exact: true }).count(), 0)
  await readOnly.context.close()
  const conflict = await setup(); conflict.state.status = 409
  await conflict.page.getByRole('row').filter({ has: conflict.page.getByText('/company', { exact: true }) }).getByText('编辑', { exact: true }).click()
  const input = conflict.page.getByRole('textbox', { name: 'SEO 标题', exact: true })
  await input.fill('冲突后保留的标题'); await conflict.page.getByRole('button', { name: '保存', exact: true }).click()
  await conflict.page.getByText('配置已更新，输入已保留。请取消后重新加载，再核对并保存。', { exact: true }).waitFor()
  assert.equal(await input.inputValue(), '冲突后保留的标题')
  assert.equal(await conflict.page.getByRole('button', { name: '保存', exact: true }).isDisabled(), true)
  await conflict.context.close()
  assert.deepEqual(errors, [])
  console.log('页面SEO模拟浏览器通过：逐页/首页保存，无独立默认SEO、修订递增、false索引、只读、409保留输入、窄屏无页面溢出。')
} finally { await browser.close() }
