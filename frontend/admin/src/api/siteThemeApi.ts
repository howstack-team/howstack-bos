import { request } from './httpClient'
import { isWebsiteSchema, websiteObject, type WebsiteObject, type WebsiteSchema } from '../utils/websiteSchema'
export type ThemeComponent = 'website' | 'pc' | 'mobile'
export interface SiteTheme { id: number; theme_id: string; name: string; version: string; digest: string; components: ThemeComponent[]; active: ThemeComponent[]; cover: string; builtin: boolean }
export interface ThemeBindings { revision: number; release_id: number; default_release_id: number; mobile_enabled: boolean; website: { release_id: number; fallback: boolean }; console: { release_id: number; fallback: boolean; mobile_available: boolean } }
export interface ThemeConfiguration { revision: number; settings: WebsiteObject; schema: WebsiteSchema }
export const themeLabels: Record<ThemeComponent, string> = { website: '官网', pc: 'PC版', mobile: '移动端' }
const root = '/themes'
export const siteThemeApi = {
  async list(signal?: AbortSignal): Promise<SiteTheme[]> {
    const data = await request<unknown>({ url: root, method: 'GET', signal })
    if (!Array.isArray(data) || data.some(row => !websiteObject(row) || !Number.isSafeInteger(row.id) || typeof row.theme_id !== 'string' || typeof row.name !== 'string' || typeof row.version !== 'string' || typeof row.digest !== 'string' || !Array.isArray(row.components) || !Array.isArray(row.active) || [...row.components, ...row.active].some(type => !(type in themeLabels)) || typeof row.cover !== 'string' || !row.cover.startsWith('/theme-assets/') || typeof row.builtin !== 'boolean')) throw new Error('主题列表格式无效')
    return data as SiteTheme[]
  },
  bindings: (signal?: AbortSignal) => request<ThemeBindings>({ url: root + '/bindings', method: 'GET', signal }),
  bind: (revision: number, release_id: number, mobile_enabled: boolean) => request<ThemeBindings>({ url: root + '/bindings', method: 'PUT', data: { revision, release_id, mobile_enabled } }),
  async settings(id: number, type: ThemeComponent, signal?: AbortSignal): Promise<ThemeConfiguration> {
    const data = await request<unknown>({ url: `${root}/${id}/components/${type}/settings`, method: 'GET', signal })
    if (!websiteObject(data) || !Number.isSafeInteger(data.revision) || !websiteObject(data.settings) || !isWebsiteSchema(data.schema)) throw new Error('主题配置格式无效')
    return { revision: Number(data.revision), settings: data.settings as WebsiteObject, schema: data.schema }
  },
  save: (id: number, type: ThemeComponent, revision: number, settings: WebsiteObject) => request({ url: `${root}/${id}/components/${type}/settings`, method: 'PUT', data: { revision, settings } }),
  install(file: File) { const data = new FormData(); data.append('file', file); return request({ url: root + '/install', method: 'POST', data, headers: { 'Content-Type': undefined } }) },
  uninstall: (id: number) => request({ url: `${root}/${id}`, method: 'DELETE' }),
}
