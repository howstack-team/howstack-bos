import { ApiRequestError, request } from './httpClient.ts'

/** 当前后台有固定表单实现的设置分类。 */
export type SettingsCategoryCode = 'base' | 'security'

/** 分类仅控制标签名称与排序，不承载表单定义。 */
export interface SettingsCategory {
  id: number
  code: SettingsCategoryCode
  name: string
  sort_order: number
}

/** 网站基础信息。 */
export interface BaseSettings {
  site_title: string
  site_subtitle: string
  site_keywords: string
  site_description: string
  site_url: string
  copyright: string
  icp_record: string
  public_security_record: string
  business_license: string
}

/** 后台登录安全策略。 */
export interface SecuritySettings {
  /** 新登录签发的凭据有效期，单位分钟。 */
  session_expire: number
  /** 一个时间窗口内同一 IP 允许的登录尝试总次数，包含成功和失败。 */
  login_attempts: number
  /** 登录频率限制窗口，单位秒。 */
  login_window: number
}

/** 设置页面使用的服务端权限码。 */
export const settingsPermissions = {
  view: 'admin.page.settings.view',
  update: 'admin.page.settings.update',
} as const

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function invalidResponse(): never {
  throw new ApiRequestError('系统设置响应格式无效，请刷新后重试', 500, 50000)
}

function baseSettings(value: unknown): BaseSettings {
  if (!isRecord(value)
    || typeof value.site_title !== 'string' || typeof value.site_subtitle !== 'string'
    || typeof value.site_keywords !== 'string'
    || typeof value.site_description !== 'string' || typeof value.site_url !== 'string'
    || typeof value.copyright !== 'string' || typeof value.icp_record !== 'string'
    || typeof value.public_security_record !== 'string' || typeof value.business_license !== 'string') return invalidResponse()

  return {
    site_title: value.site_title, site_subtitle: value.site_subtitle, site_keywords: value.site_keywords,
    site_description: value.site_description, site_url: value.site_url, copyright: value.copyright,
    icp_record: value.icp_record, public_security_record: value.public_security_record,
    business_license: value.business_license,
  }
}

function securitySettings(value: unknown): SecuritySettings {
  if (!isRecord(value)
    || typeof value.session_expire !== 'number' || !Number.isInteger(value.session_expire)
    || value.session_expire < 5 || value.session_expire > 1440
    || typeof value.login_attempts !== 'number' || !Number.isInteger(value.login_attempts)
    || value.login_attempts < 1 || value.login_attempts > 100
    || typeof value.login_window !== 'number' || !Number.isInteger(value.login_window)
    || value.login_window < 10 || value.login_window > 3600) return invalidResponse()

  return { session_expire: value.session_expire, login_attempts: value.login_attempts, login_window: value.login_window }
}

/** 读取有固定表单实现的分类；新增数据库分类不会自动生成未知配置界面。 */
export async function listSettingsCategories(signal?: AbortSignal): Promise<SettingsCategory[]> {
  const value = await request<unknown>({ url: '/settings/categories', signal })
  if (!Array.isArray(value)) return invalidResponse()
  const categories: SettingsCategory[] = []
  for (const row of value) {
    if (!isRecord(row) || typeof row.id !== 'number' || !Number.isInteger(row.id) || row.id < 1
      || typeof row.code !== 'string' || typeof row.name !== 'string' || !row.name.trim()
      || typeof row.sort_order !== 'number' || !Number.isInteger(row.sort_order)) return invalidResponse()
    if (row.code !== 'base' && row.code !== 'security') continue
    if (categories.some((category) => category.code === row.code)) return invalidResponse()
    categories.push({ id: row.id, code: row.code, name: row.name, sort_order: row.sort_order })
  }
  return categories.sort((left, right) => left.sort_order - right.sort_order || left.id - right.id)
}

/** 读取基础设置并校验服务端返回的数据。 */
export async function getBaseSettings(signal?: AbortSignal): Promise<BaseSettings> {
  return baseSettings(await request<unknown>({ url: '/settings/base', signal }))
}

/** 完整保存基础设置，返回服务器实际保存后的值。 */
export async function saveBaseSettings(values: BaseSettings): Promise<BaseSettings> {
  const data: BaseSettings = {
    site_title: values.site_title.trim(), site_subtitle: values.site_subtitle?.trim() ?? '',
    site_keywords: values.site_keywords?.trim() ?? '',
    site_description: values.site_description?.trim() ?? '', site_url: values.site_url.trim(),
    copyright: values.copyright?.trim() ?? '',
    icp_record: values.icp_record?.trim() ?? '',
    public_security_record: values.public_security_record?.trim() ?? '',
    business_license: values.business_license?.trim() ?? '',
  }
  return baseSettings(await request<unknown>({ url: '/settings/base', method: 'PUT', data }))
}

/** 读取当前登录安全策略。 */
export async function getSecuritySettings(signal?: AbortSignal): Promise<SecuritySettings> {
  return securitySettings(await request<unknown>({ url: '/settings/security', signal }))
}

/** 完整保存登录安全策略。 */
export async function saveSecuritySettings(values: SecuritySettings): Promise<SecuritySettings> {
  const data: SecuritySettings = {
    session_expire: values.session_expire, login_attempts: values.login_attempts, login_window: values.login_window,
  }
  return securitySettings(await request<unknown>({ url: '/settings/security', method: 'PUT', data }))
}

/** 提取可映射到原生 Form.Item 的服务端字段校验错误。 */
export function settingsFieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ApiRequestError) || error.status !== 422 || !isRecord(error.data)) return {}
  const errors = isRecord(error.data.errors) ? error.data.errors : error.data
  const result: Record<string, string> = {}
  for (const [key, value] of Object.entries(errors)) {
    const message = typeof value === 'string' ? value : Array.isArray(value) ? value.find((item) => typeof item === 'string') : undefined
    if (typeof message === 'string') result[key.replace(/^settings\./, '')] = message
  }
  return result
}
