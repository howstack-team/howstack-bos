import { request, ApiRequestError } from './httpClient.ts'
import { isWebsiteSchema, validateWebsiteValue, websiteObject, type WebsiteObject, type WebsiteSchema } from '../utils/websiteSchema.ts'
export const websitePermissions = { view: 'admin.page.website.view', update: 'admin.page.website.update', install: 'admin.page.website.install', activate: 'admin.page.website.activate' } as const
export interface WebsiteTheme { id: string; name: string; version: string; digest: string; active: boolean }
export interface WebsitePage { id: string; kind: 'core' | 'custom'; title: string; path: string }
export interface WebsiteConfiguration { settings: WebsiteObject; revision: number; schema: WebsiteSchema }
export interface WebsiteSettings extends WebsiteConfiguration { active_theme_id: string; active_theme_version: string; active_theme_digest: string; site: Record<string, string> }
export interface WebsiteThemeSettings extends WebsiteConfiguration { theme_id: string; schema_version: number; defaults: WebsiteObject }
export interface WebsiteStorage { available: boolean; message: string; configure_path: string }
/** 官网基础与导航共用修订号，各自只提交本分区字段。 */
export type WebsiteSection = 'base' | 'navigation'
export interface WebsiteSectionSettings extends WebsiteConfiguration { active_theme_id: string; site: Record<string, string>; pages: WebsitePage[] }
const root = '/website'
function invalid(): never { throw new ApiRequestError('官网配置响应格式无效，请重新加载', 500, 50000) }
function configuration(value: unknown, schema?: WebsiteSchema): WebsiteConfiguration {
  if (!websiteObject(value) || !Number.isSafeInteger(value.revision) || Number(value.revision) < 1) return invalid()
  const declaration = schema ?? value.schema
  if (!isWebsiteSchema(declaration) || declaration.type !== 'object' || !websiteObject(value.settings) || Object.keys(validateWebsiteValue(value.settings, declaration)).length) return invalid()
  return { settings: value.settings as WebsiteObject, revision: Number(value.revision), schema: declaration }
}
async function get(url: string, signal?: AbortSignal) { return request<unknown>({ url: `${root}${url}`, signal }) }
function websiteSettings(value: unknown, current?: WebsiteSettings): WebsiteSettings {
  const base = configuration(value, current?.schema), properties = base.schema.properties
  if (!properties || !['logo_image_id', 'favicon_image_id'].every(key => properties[key]?.type === 'string' && properties[key]?.format === 'image-id')
    || !['navigation', 'footer_links'].every(key => properties[key]?.type === 'array') || !websiteObject(value)) return invalid()
  const site = value.site ?? current?.site
  if (!websiteObject(site) || !Object.values(site).every(item => typeof item === 'string')
    || typeof value.active_theme_id !== 'string' || !/^[a-z][a-z0-9_-]{0,63}$/.test(value.active_theme_id)
    || typeof value.active_theme_version !== 'string' || typeof value.active_theme_digest !== 'string' || !/^[a-f0-9]{64}$/.test(value.active_theme_digest)) return invalid()
  return { ...base, active_theme_id: value.active_theme_id, active_theme_version: value.active_theme_version, active_theme_digest: value.active_theme_digest, site: site as Record<string, string> }
}
/** 官网配置和主题配置独立保存完整值及修订号，PUT 回包不含 Schema 时保留 GET 元数据。 */
export const websiteApi = {
  async section(section: WebsiteSection, signal?: AbortSignal): Promise<WebsiteSectionSettings> {
    const value = await get(`/${section}`, signal), base = configuration(value)
    if (!websiteObject(value) || typeof value.active_theme_id !== 'string' || !websiteObject(value.site)
      || !Object.values(value.site).every(item => typeof item === 'string')) return invalid()
    const pages = value.pages ?? []
    if (!Array.isArray(pages) || !pages.every(row => websiteObject(row) && typeof row.id === 'string' && typeof row.path === 'string' && typeof row.title === 'string' && ['core', 'custom'].includes(String(row.kind)))) return invalid()
    return { ...base, active_theme_id: value.active_theme_id, site: value.site as Record<string, string>, pages: pages as WebsitePage[] }
  },
  async settings(signal?: AbortSignal): Promise<WebsiteSettings> {
    return websiteSettings(await get('/settings', signal))
  },
  async pages(id: string, signal?: AbortSignal): Promise<WebsitePage[]> {
    const value = await get(`/themes/${encodeURIComponent(id)}/pages`, signal)
    if (!Array.isArray(value) || !value.every(row => websiteObject(row) && typeof row.id === 'string' && ['core', 'custom'].includes(String(row.kind)) && typeof row.title === 'string' && typeof row.path === 'string')) return invalid()
    return value as WebsitePage[]
  },
  async save(url: string, current: WebsiteConfiguration, settings: WebsiteObject, signal?: AbortSignal): Promise<WebsiteConfiguration> {
    const value = await request<unknown>({ url: `${root}${url}`, method: 'PUT', data: { revision: current.revision, settings }, signal })
    return configuration(value, current.schema)
  },
  async storage(signal?: AbortSignal): Promise<WebsiteStorage> {
    const value = await get('/images/availability', signal)
    if (!websiteObject(value) || typeof value.available !== 'boolean' || typeof value.message !== 'string' || value.configure_path !== '/channels/storage') return invalid()
    return value as unknown as WebsiteStorage
  },
  async upload(file: File, signal?: AbortSignal): Promise<{ id: string; url: string; alt: string }> {
    const data = new FormData(); data.append('file', file)
    const value = await request<unknown>({ url: `${root}/images`, method: 'POST', data, headers: { 'Content-Type': undefined }, signal })
    if (!websiteObject(value) || typeof value.id !== 'string' || !/^[a-f0-9]{32}$/.test(value.id)
      || value.url !== `${websiteImageUrl(value.id)}` || typeof value.alt !== 'string') return invalid()
    return { id: value.id, url: String(value.url), alt: value.alt }
  },

}
/** 使用稳定公开入口预览图片，不持久化存储签名 URL。 */
export function websiteImageUrl(id: string): string { return /^[a-f0-9]{32}$/.test(id) ? `/api/public/v1/website/images/${id}` : '' }
export function websiteFieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ApiRequestError) || error.status !== 422 || !websiteObject(error.data) || !websiteObject(error.data.errors)) return {}
  return Object.fromEntries(Object.entries(error.data.errors).filter(([, messages]) => Array.isArray(messages))
    .map(([path, messages]) => [path, (messages as unknown[]).filter(message => typeof message === 'string').join('；')]))
}
