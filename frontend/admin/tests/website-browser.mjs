import assert from 'node:assert/strict'
import { readFile, mkdir } from 'node:fs/promises'
import { execFileSync } from 'node:child_process'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = process.env.WEBSITE_BROWSER_OUTPUT ?? '/tmp/howstack-website-ui'
await mkdir(output, { recursive: true })
const json = async path => JSON.parse(await readFile(new URL(path, import.meta.url), 'utf8'))
const schema = await json('../../../backend/themes/default/website/settings.schema.json')
const global = schema.properties.global
const company = schema.properties.pages.properties.company
const defaults = schema.default
const themeSchema = { type: 'object', additionalProperties: false, required: ['global', 'pages'], properties: { global, pages: { type: 'object', additionalProperties: false, required: ['company'], properties: { company } } } }
const backend = new URL('../../../backend/', import.meta.url).pathname
const [siteDefaults, siteSchema] = JSON.parse(execFileSync('php', ['-r', "require 'vendor/autoload.php'; echo json_encode([app\\modules\\website\\validation\\WebsiteSettings::defaults(),app\\modules\\website\\validation\\WebsiteSettings::schema()], JSON_THROW_ON_ERROR);"], { cwd: backend, encoding: 'utf8' }))
const digest = 'a'.repeat(64), imageId = 'b'.repeat(32)
const allPermissions = ['admin.website.base.view', 'admin.website.base.update', 'admin.website.navigation.view', 'admin.website.navigation.update', 'admin.page.website.view', 'admin.page.website.update', 'admin.page.website.install', 'admin.page.website.activate', 'admin.channels.storage.view']
const pages = [{ id: 'home', kind: 'core', title: '首页', path: '/' }, { id: 'console.home', kind: 'core', title: '会员中心', path: '/console/' }, { id: 'company', kind: 'custom', title: '公司介绍', path: '/company' }]
const browser = await chromium.launch({ headless: true }), errors = []
async function setup(permissions = allPermissions) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } }), page = await context.newPage()
  page.setDefaultTimeout(10000); page.on('pageerror', error => errors.push(error.message))
  const state = { writes: [], reads: [], saveStatus: 200, available: true, loadError: false, malformed: false,
    website: { active_theme_id: 'default', active_theme_version: '2.0.0', active_theme_digest: digest, revision: 1, settings: structuredClone(siteDefaults), schema: siteSchema, site: { site_title: '测试平台', site_url: 'http://127.0.0.1:8989' } },
    theme: { theme_id: 'default', schema_version: 1, revision: 1, settings: structuredClone(defaults), defaults, schema: themeSchema } }
  await context.route('**/api/admin/v1/**', async route => {
    const request = route.request(), path = new URL(request.url()).pathname.replace('/api/admin/v1', '')
    state.reads.push(path); let status = 200, data = null, message = '成功'
    if (path === '/auth/me') data = { user: { id: 1, username: 'website', nickname: '网站管理员', is_founder: false, last_login_at: null }, permissions }
    else if (request.method() === 'PUT') {
      const body = request.postDataJSON(); state.writes.push({ path, body }); status = state.saveStatus
      if (status === 409) message = '配置修订冲突'
      else if (status === 422) data = { errors: { 'settings.seo_title': ['标题不符合规则'] } }
      else if (path === '/website/base' || path === '/website/navigation') {
        Object.assign(state.website.settings, body.settings); state.website.revision++
        data = { ...state.website, settings: body.settings }
      } else { Object.assign(state.theme, { settings: body.settings, revision: state.theme.revision + 1 }); data = state.theme }
    } else if (path === '/website/base' || path === '/website/navigation') {
      const section = path.endsWith('base') ? 'base' : 'navigation'
      const keys = section === 'base' ? ['logo_image_id', 'favicon_image_id', 'company'] : ['navigation', 'footer_links']
      data = { ...state.website, settings: Object.fromEntries(keys.map(key => [key, state.website.settings[key]])), schema: { type: 'object', additionalProperties: false, required: keys, properties: Object.fromEntries(keys.map(key => [key, siteSchema.properties[key]])) }, ...(section === 'navigation' ? { pages } : {}) }
      if (state.loadError) { status = 500; message = '加载失败' }
      if (state.malformed) data.schema = { $ref: 'https://invalid.test' }
    } else if (path === '/website/settings') data = state.website
    else if (path === '/themes') data = [{ id: 1, theme_id: 'default', name: '默认官方主题', version: '2.0.0', digest, components: ['website', 'pc'], active: ['website', 'pc'], builtin: true, cover: '/theme-assets/default/cover.svg' }]
    else if (path === '/themes/bindings') data = { release_id: 1, default_release_id: 1, revision: 1, website: { release_id: 1, fallback: false }, console: { release_id: 1, fallback: false } }
    else if (path.endsWith('/pages')) data = pages
    else if (path.endsWith('/settings')) data = state.theme
    else if (path === '/website/images/availability') data = { available: state.available, message: state.available ? '' : '请先配置并启用默认存储通道', configure_path: '/channels/storage' }
    else if (path === '/website/images') { status = 201; data = { id: imageId, url: `/api/public/v1/website/images/${imageId}`, alt: '测试图片' } }
    else if (path === '/auth/refresh') { status = 401; message = '未登录' }
    else data = []
    await route.fulfill({ status, json: { code: status >= 400 ? status * 100 : 0, message, data, request_id: 'website-browser' } })
  })
  return { context, page, state }
}
try {
  const { context, page, state } = await setup()
  await page.goto(`${base}/website/base`)
  await page.getByRole('textbox', { name: '公司简介', exact: true }).waitFor()
  assert.equal(await page.getByRole('textbox', { name: '首页 SEO 标题', exact: true }).count(), 0)
  await page.getByRole('textbox', { name: '公司简介', exact: true }).fill('独立的公司资料')
  await page.getByRole('button', { name: '保存基础设置', exact: true }).click()
  await page.getByText('配置已保存', { exact: true }).waitFor()
  assert.equal(state.writes.at(-1).path, '/website/base')
  assert.equal(state.writes.at(-1).body.settings.company.introduction, '独立的公司资料')
  assert.equal('navigation' in state.writes.at(-1).body.settings, false)
  assert.equal(state.reads.includes('/themes'), false)
  await page.screenshot({ path: `${output}/base-desktop.png`, fullPage: true })
  await page.goto(`${base}/website/navigation`)
  await page.getByRole('textbox', { name: '顶部导航1名称', exact: true }).waitFor()
  await page.getByRole('textbox', { name: '顶部导航1名称', exact: true }).fill('新的首页导航')
  await page.getByRole('button', { name: '保存导航管理', exact: true }).click()
  await page.getByText('配置已保存', { exact: true }).waitFor()
  assert.equal(state.writes.at(-1).path, '/website/navigation')
  assert.deepEqual(Object.keys(state.writes.at(-1).body.settings).sort(), ['footer_links', 'navigation'])
  assert.equal(state.website.settings.company.introduction, '独立的公司资料')
  await page.screenshot({ path: `${output}/navigation-desktop.png`, fullPage: true })
  await page.goto(`${base}/themes`)
  await page.getByText('配置', { exact: true }).first().click()
  await page.getByRole('textbox', { name: '首页主标题', exact: true }).waitFor()
  assert.equal(await page.getByRole('textbox', { name: '公司简介', exact: true }).count(), 0)
  assert.equal(await page.getByRole('textbox', { name: 'SEO 描述', exact: true }).count(), 0)
  await page.getByRole('textbox', { name: '首页主标题', exact: true }).fill('新的主题标题')
  await page.getByRole('button', { name: '保存', exact: true }).last().click()
  await page.getByText('主题配置已保存', { exact: true }).waitFor()
  assert.equal(state.writes.at(-1).path, '/themes/1/components/website/settings')
  await page.getByRole('button', { name: '取消', exact: true }).click()
  await page.getByRole('button', { name: '上传主题', exact: true }).waitFor()
  await page.screenshot({ path: `${output}/themes-desktop.png`, fullPage: true })
  await context.close()
  const readOnly = await setup(['admin.website.base.view'])
  await readOnly.page.goto(`${base}/website/base`)
  const description = readOnly.page.getByRole('textbox', { name: '公司简介', exact: true })
  await description.waitFor(); assert.equal(await description.isDisabled(), true)
  assert.equal(await readOnly.page.getByRole('button', { name: '保存基础设置', exact: true }).count(), 0)
  assert.equal(readOnly.state.writes.length, 0)
  await readOnly.context.close()
  const conflict = await setup(); conflict.state.saveStatus = 409
  await conflict.page.goto(`${base}/website/base`)
  const input = conflict.page.getByRole('textbox', { name: '公司简介', exact: true }); await input.fill('冲突后保留输入')
  await conflict.page.getByRole('button', { name: '保存基础设置', exact: true }).click()
  await conflict.page.getByText('配置已被更新，您的输入已保留，请重新加载后再保存。', { exact: true }).waitFor()
  assert.equal(await input.inputValue(), '冲突后保留输入')
  assert.equal(await conflict.page.getByRole('button', { name: '保存基础设置', exact: true }).isDisabled(), true)
  await conflict.context.close()
  const failure = await setup(); failure.state.malformed = true
  await failure.page.goto(`${base}/website/base`)
  await failure.page.getByText('官网配置响应格式无效，请重新加载', { exact: true }).waitFor()
  assert.equal(failure.state.writes.length, 0); await failure.context.close()
  assert.deepEqual(errors, [])
  console.log('官网三页面、分区保存、主题配置隔离、只读权限、409保留输入及错误响应检查通过。模拟API，不代表真实业务写入。')
} finally { await browser.close() }
