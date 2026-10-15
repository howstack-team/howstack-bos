/** 普通会员SPA可读取的公开配置，不含框架挂载生命周期。 */
export type Presentation = 'auto' | 'pc' | 'mobile'
/** 后台维护的公开品牌资料；空版权及备案不展示，图片为空时可使用主题内置图片。 */
export interface ConsoleSite {
  name: string
  logo: string
  favicon: string
  copyright: string
  icp_record: string
  public_security_record: string
}
export interface ConsoleThemeConfig {
  contract: '1'
  basePath: '/console'
  apiBase: '/api/console/v1'
  assetBase: string
  theme: { id: string; version: string; type: 'pc' | 'mobile' }
  settings: Record<string, unknown>
  site: ConsoleSite
  capabilities: string[]
}
/** 校验公开配置，只保留可展示的品牌字段。 */
export function parseConsoleSite(value: unknown): ConsoleSite {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('平台品牌配置无效')
  const record = value as Record<string, unknown>
  const keys = ['name', 'logo', 'favicon', 'copyright', 'icp_record', 'public_security_record'] as const
  if (keys.some(key => typeof record[key] !== 'string')) throw new Error('平台品牌配置无效')
  for (const key of ['logo', 'favicon'] as const) {
    if (record[key] !== '' && !/^\/(?!\/)[^\s\\]*$/.test(record[key] as string)) throw new Error('平台品牌图片地址无效')
  }
  return Object.fromEntries(keys.map(key => [key, record[key]])) as unknown as ConsoleSite
}

/** SPA路由切换时使用的浏览器标题，不读取官网SEO配置。 */
export function consolePageTitle(page: string, platform: string): string {
  return [page.trim() || '会员中心', platform.trim()].filter(Boolean).join(' - ')
}
/** 可选HTTP助手，保留HttpOnly会话及结构化错误。 */
export async function themeRequest<T>(config: ConsoleThemeConfig, path: string, options: RequestInit = {}): Promise<T> {
  if (!/^\/[a-z0-9_/-]+(?:\?[^#]*)?$/i.test(path)) throw new Error('API路径无效')
  const response = await fetch(config.apiBase + path, { ...options, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', ...options.headers } })
  const body = await response.json()
  if (!response.ok || body.code !== 0) throw Object.assign(new Error(body.message || '请求失败'), { status: response.status, data: body.data })
  return body.data as T
}
export function setPresentation(value: Presentation): void {
  if (!confirm('切换后会重新加载页面，请确认已保存正在编辑的内容。')) return
  document.cookie = `console_presentation=${value}; Path=/; SameSite=Lax; Max-Age=31536000${location.protocol === 'https:' ? '; Secure' : ''}`
  location.reload()
}
