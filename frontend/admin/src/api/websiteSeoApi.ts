import { ApiRequestError, request } from './httpClient'
import { websiteObject } from '../utils/websiteSchema'

/** 空文字或 null 表示继承；索引策略不能用字符串或整数代替布尔值。 */
export interface PageSeoSettings { title: string; description: string; keywords: string; indexable: boolean | null }
export type SeoSource = 'override' | 'page_default' | 'default'
export interface SeoPage {
  id: string; scope: string; kind: 'core' | 'custom'; title: string; path: string
  settings: PageSeoSettings; effective: Omit<PageSeoSettings, 'indexable'> & { indexable: boolean }
  sources: Record<keyof PageSeoSettings, SeoSource>
}
export interface WebsiteSeoCatalog { revision: number; active_theme_id: string; pages: SeoPage[] }
export const websiteSeoPermissions = { view: 'admin.website.seo.view', update: 'admin.website.seo.update' } as const
const root = '/website/seo'
function invalid(): never { throw new ApiRequestError('页面 SEO 响应格式无效，请重新加载', 500, 50000) }
function text(value: unknown, length: number): value is string { return typeof value === 'string' && Array.from(value).length <= length }
function settings(value: unknown): value is PageSeoSettings {
  return websiteObject(value) && Object.keys(value).length === 4 && text(value.title, 120) && text(value.description, 500)
    && text(value.keywords, 255) && (value.indexable === null || typeof value.indexable === 'boolean')
}
/** 在HTTP边界校验页面身份、继承值及生效值，拒绝未知或跨主题记录。 */
export function websiteSeoCatalog(value: unknown): WebsiteSeoCatalog {
  if (!websiteObject(value) || !Number.isSafeInteger(value.revision) || Number(value.revision) < 1
    || typeof value.active_theme_id !== 'string' || !/^[a-z][a-z0-9_-]{0,63}$/.test(value.active_theme_id)
    || !Array.isArray(value.pages) || value.pages.length < 1 || value.pages.length > 33) return invalid()
  const ids = new Set<string>()
  for (const page of value.pages) {
    if (!websiteObject(page) || typeof page.id !== 'string' || !/^[a-z][a-z0-9_]{0,63}$/.test(page.id) || ids.has(page.id)
      || page.kind !== (page.id === 'home' ? 'core' : 'custom') || page.scope !== (page.id === 'home' ? 'core' : `theme:${value.active_theme_id}`)
      || !text(page.title, 100) || typeof page.path !== 'string' || (page.id === 'home' ? page.path !== '/' : !/^\/[a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*){0,5}$/.test(page.path))
      || !settings(page.settings) || !websiteObject(page.effective) || !text(page.effective.title, 243) || !text(page.effective.description, 500)
      || !text(page.effective.keywords, 255) || typeof page.effective.indexable !== 'boolean' || !websiteObject(page.sources)
      || !['title', 'description', 'keywords', 'indexable'].every(key => ['override', 'page_default', 'default'].includes(String(page.sources[key])))) return invalid()
    ids.add(page.id)
  }
  if (!ids.has('home')) return invalid()
  return value as unknown as WebsiteSeoCatalog
}
/** 页面与默认SEO共用官网revision，主题切换或其他官网写入后需要重新读取。 */
export const websiteSeoApi = {
  async read(signal?: AbortSignal): Promise<WebsiteSeoCatalog> {
    return websiteSeoCatalog(await request<unknown>({ url: root, signal }))
  },
  async savePage(catalog: WebsiteSeoCatalog, page: SeoPage, value: PageSeoSettings, signal?: AbortSignal): Promise<WebsiteSeoCatalog> {
    return websiteSeoCatalog(await request<unknown>({ url: `${root}/pages/${encodeURIComponent(page.id)}`, method: 'PUT', signal,
      data: { revision: catalog.revision, scope: page.scope, settings: value } }))
  },
}
