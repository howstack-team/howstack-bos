import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { isWebsiteSchema, newWebsiteValue, validateWebsiteValue, websiteLinkErrors, type WebsiteSchema } from '../src/utils/websiteSchema.ts'
import { buildPermissionTree, permissionPages } from '../src/access/permissionTree.ts'
import { routes } from '../src/routes.ts'
const schema = JSON.parse(readFileSync(new URL('../../../backend/themes/default/website/settings.schema.json', import.meta.url), 'utf8')).properties.global as WebsiteSchema

test('默认主题声明受控，拒绝远程引用、执行钩子与过深结构', () => {
  assert(isWebsiteSchema(schema))
  assert(!isWebsiteSchema({ ...schema, $ref: 'https://example.com/schema' }))
  assert(!isWebsiteSchema({ type: 'string', maxLength: 100, 'x-widget': 'script' }))
  let deep: WebsiteSchema = { type: 'string', maxLength: 10 }
  for (let i = 0; i < 10; i++) deep = { type: 'array', maxItems: 10, items: deep }
  assert(!isWebsiteSchema(deep))
})
test('新增项保存允许的空图片，零和 false 保留；错误值不被强行改写', () => {
  const item = schema.properties!.banners.items!
  assert.deepEqual(newWebsiteValue(item), { title: '', description: '', image_id: '' })
  assert.deepEqual(validateWebsiteValue(newWebsiteValue(item), item), {})
  const integers: WebsiteSchema = { type: 'integer', minimum: 0, maximum: 10 }
  assert.deepEqual(validateWebsiteValue(0, integers), {})
  assert(validateWebsiteValue(1.5, integers).settings)
  assert(validateWebsiteValue(null, integers).settings)
  assert.deepEqual(validateWebsiteValue(false, { type: 'boolean' }), {})
  assert(validateWebsiteValue('#xyzxyz', schema.properties!.primary_color).settings)
  assert(validateWebsiteValue('abc', item.properties!.image_id).settings)
})
test('嵌套字段错误定位到完整路径，列表边界和未知字段被拒绝', () => {
  const value = newWebsiteValue(schema) as Record<string, unknown>
  value.primary_color = '#165DFF'; value.features = [{ title: 'a'.repeat(41), description: '' }]
  assert(validateWebsiteValue(value, schema)['settings.features.0.title'])
  value.features = []; value.banners = Array(5).fill(newWebsiteValue(schema.properties!.banners.items!))
  assert(validateWebsiteValue(value, schema)['settings.banners'])
  value.extra = '不应保存'; assert(validateWebsiteValue(value, schema)['settings.extra'])
})
test('导航使用稳定标识，危险地址和失效页面保留字段错误', () => {
  const pages = [{ id: 'home', kind: 'core' }, { id: 'team', kind: 'custom' }]
  assert.deepEqual(websiteLinkErrors({ navigation: [{ label: '团队', target: 'theme_page', theme_id: 'default', page_id: 'team' }], footer_links: [] }, 'default', pages), {})
  for (const url of ['javascript:alert(1)', '//example.com', 'https://user:pass@example.com', 'https://example.com\\evil', 'https://example.com/a b']) {
    assert(websiteLinkErrors({ navigation: [{ label: '外部', target: 'external', url }] }, 'default', pages)['settings.navigation.0.url'])
  }
  assert(websiteLinkErrors({ navigation: [{ label: '未知', target: 'theme_page', theme_id: 'other', page_id: 'team' }] }, 'default', pages)['settings.navigation.0.page_id'])
})
test('官网页面独立授权查看、配置、安装、切换和预览', () => {
  const pages = permissionPages(buildPermissionTree(routes, {
    'admin.page.website.view': { label: '查看官网与主题', group: '官网与主题' },
    'admin.page.website.update': { label: '配置官网与主题及上传图片', group: '官网与主题' },
    'admin.page.website.install': { label: '安装官网主题', group: '官网与主题' },
    'admin.page.website.activate': { label: '切换官网主题', group: '官网与主题' },
    'admin.page.website.preview': { label: '预览官网主题', group: '官网与主题' },
  }))
  assert.equal(pages.length, 1); assert.equal(pages[0].viewCode, 'admin.page.website.view')
  assert.deepEqual(new Set(pages[0].permissions.map(item => item.code)), new Set(['admin.page.website.update', 'admin.page.website.install', 'admin.page.website.activate', 'admin.page.website.preview']))
})

test('页面SEO在官网管理中可独立分配查看与修改权限', () => {
  const pages = permissionPages(buildPermissionTree(routes, {
    'admin.website.seo.view': { label: '查看页面SEO', group: '页面SEO' },
    'admin.website.seo.update': { label: '修改页面SEO', group: '页面SEO' },
  }))
  assert.equal(pages.length, 1)
  assert.equal(pages[0].viewCode, 'admin.website.seo.view')
  assert.deepEqual(pages[0].permissions.map(item => item.code), ['admin.website.seo.update'])
})
