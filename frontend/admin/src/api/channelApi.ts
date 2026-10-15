import { ApiRequestError, request } from './httpClient'

/** 七类通道共用配置契约，实际业务执行仍由各类别的固定契约承担。 */
export const CHANNEL_CATEGORIES = {
  payment: '支付通道', sms: '短信通道', mail: '邮件通道',
  identity: '实名认证', storage: '文件存储', oauth: '第三方登录', captcha: '人机验证',
} as const
export type ChannelCategory = keyof typeof CHANNEL_CATEGORIES
export type ConfigurationValue = string | number | boolean

/** 由宿主验证的配置字段；不接受页面执行代码或远程表单定义。 */
export interface ChannelConfigurationField {
  key: string
  label: string
  type: 'text' | 'password' | 'url' | 'integer' | 'boolean' | 'select'
  required: boolean
  max_length?: number
  minimum?: number
  maximum?: number
  default?: ConfigurationValue
  placeholder?: string
  description?: string
  unit?: string
  options?: { label: string; value: string }[]
}

export interface ChannelPlatform {
  id: string
  name: string
  category: ChannelCategory
  version: string
  configuration_fields: ChannelConfigurationField[]
  platform_available: boolean
  /** 由插件实际接口识别的可选模板能力；不是某个模板的审核状态。 */
  template_review: { submit: boolean; query: boolean }
}

/** 列表不包含配置值；详细配置需另行读取，秘密只返回是否已设置。 */
export interface ChannelRecord {
  /** 未配置的平台为 0，不是持久化通道身份。 */
  id: number
  configured: boolean
  category: ChannelCategory
  name: string
  plugin_id: string
  platform_name: string
  platform_version: string
  platform_available: boolean
  configuration_current: boolean
  status: 0 | 1
  is_default: boolean
  sort_order: number
  description: string
  revision: number
  created_at: string
  updated_at: string
}

export interface ChannelDetail extends ChannelRecord {
  configuration: Record<string, ConfigurationValue>
  configured_secrets: Record<string, boolean>
  configuration_fields: ChannelConfigurationField[]
}

/** SMTP 测试结果，accepted 表示服务器接收而非收件箱送达。 */
export interface MailTestResult {
  status: 'accepted' | 'failed' | 'unknown'
  message_id: string
  message: string
}

export interface ChannelInput {
  name: string
  plugin_id: string
  configuration: Record<string, ConfigurationValue>
  clear_secrets: string[]
  status: 0 | 1
  is_default: boolean
  sort_order: number
  description: string
  revision: number
}

function record(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}
function invalid(): never {
  throw new ApiRequestError('通道配置响应格式无效，请刷新后重试', 500, 50000)
}
function fields(value: unknown): ChannelConfigurationField[] {
  if (!Array.isArray(value) || value.length > 16) return invalid()
  return value.map((field: unknown) => {
    if (!record(field) || typeof field.key !== 'string' || !/^[a-z][a-z0-9_]{0,63}$/.test(field.key)
      || typeof field.label !== 'string' || typeof field.required !== 'boolean'
      || !['text', 'password', 'url', 'integer', 'boolean', 'select'].includes(String(field.type))) return invalid()
    if (field.type === 'integer' && (!Number.isSafeInteger(field.minimum) || !Number.isSafeInteger(field.maximum))) return invalid()
    if (field.type === 'select' && (!Array.isArray(field.options) || field.options.some(option =>
      !record(option) || typeof option.label !== 'string' || typeof option.value !== 'string'))) return invalid()
    for (const key of ['placeholder', 'description', 'unit']) if (field[key] !== undefined && typeof field[key] !== 'string') return invalid()
    return field as unknown as ChannelConfigurationField
  })
}
function channel(value: unknown, category: ChannelCategory): ChannelRecord {
  if (!record(value) || value.category !== category || !Number.isSafeInteger(value.id) || Number(value.id) < 0
    || !Number.isSafeInteger(value.revision) || Number(value.revision) < 0
    || typeof value.configured !== 'boolean'
    || (value.configured ? Number(value.id) < 1 || Number(value.revision) < 1 : value.id !== 0 || value.revision !== 0 || value.status !== 0 || value.is_default !== false)
    || !Number.isSafeInteger(value.sort_order) || Number(value.sort_order) < 0
    || ![0, 1].includes(Number(value.status)) || typeof value.status !== 'number'
    || ['name', 'plugin_id', 'platform_name', 'platform_version', 'description', 'created_at', 'updated_at'].some(key => typeof value[key] !== 'string')
    || ['platform_available', 'configuration_current', 'is_default'].some(key => typeof value[key] !== 'boolean')) return invalid()
  return value as unknown as ChannelRecord
}
function detail(value: unknown, category: ChannelCategory): ChannelDetail {
  const base = channel(value, category)
  if (!record(value) || !record(value.configuration) || !record(value.configured_secrets)
    || Object.values(value.configuration).some(item => !['string', 'number', 'boolean'].includes(typeof item))
    || Object.values(value.configured_secrets).some(item => typeof item !== 'boolean')) return invalid()
  const schema = fields(value.configuration_fields)
  if (schema.some(field => field.type === 'password' && Object.hasOwn(value.configuration as object, field.key))) return invalid()
  return { ...base, configuration: value.configuration as Record<string, ConfigurationValue>,
    configured_secrets: value.configured_secrets as Record<string, boolean>, configuration_fields: schema }
}

/** 通道页面及操作权限与后端目录保持一致。 */
export function channelPermission(category: ChannelCategory, action: 'view' | 'update'): string {
  return `admin.channels.${category}.${action}`
}

/** 所有通道只访问本机固定用例，不从浏览器访问第三方平台。 */
export const channelApi = {
  async list(category: ChannelCategory, params: Record<string, string | number>, signal?: AbortSignal) {
    const result = await request<unknown>({ url: `/channels/${category}`, params, signal })
    if (!record(result) || !Array.isArray(result.data) || !Number.isSafeInteger(result.total) || Number(result.total) < 0) return invalid()
    if (result.default_label !== null && !['主用通道', '默认存储'].includes(String(result.default_label))) return invalid()
    return { data: result.data.map(value => channel(value, category)), total: result.total as number,
      defaultLabel: result.default_label as string | null }
  },
  async platforms(category: ChannelCategory, signal?: AbortSignal): Promise<ChannelPlatform[]> {
    const result = await request<unknown>({ url: `/channels/${category}/platforms`, signal })
    if (!Array.isArray(result)) return invalid()
    return result.map((value: unknown) => {
      if (!record(value) || value.category !== category || ['id', 'name', 'version'].some(key => typeof value[key] !== 'string') || typeof value.platform_available !== 'boolean') return invalid()
      if (!record(value.template_review) || typeof value.template_review.submit !== 'boolean' || typeof value.template_review.query !== 'boolean') return invalid()
      return { id: value.id as string, name: value.name as string, category, version: value.version as string, configuration_fields: fields(value.configuration_fields), platform_available: value.platform_available,
        template_review: { submit: value.template_review.submit, query: value.template_review.query } }
    })
  },
  async get(category: ChannelCategory, id: number, signal?: AbortSignal): Promise<ChannelDetail> {
    return detail(await request<unknown>({ url: `/channels/${category}/${id}`, signal }), category)
  },
  async save(category: ChannelCategory, input: ChannelInput, id?: number): Promise<ChannelDetail> {
    const data = { configuration: input.configuration, clear_secrets: input.clear_secrets, status: input.status,
      is_default: input.is_default, sort_order: input.sort_order, description: input.description, revision: input.revision }
    return detail(await request<unknown>({ url: `/channels/${category}${id ? `/${id}` : `/platforms/${input.plugin_id}`}`, method: 'PUT', data }), category)
  },
  async status(category: ChannelCategory, row: ChannelRecord): Promise<ChannelDetail> {
    return detail(await request<unknown>({ url: `/channels/${category}/${row.id}/status`, method: 'PATCH', data: { revision: row.revision, status: row.status === 1 ? 0 : 1 } }), category)
  },
  /** 只发送后台固定测试内容；超时后的重试保留同一 UUID，避免重复发送。 */
  async testMail(row: ChannelRecord, recipient: string, requestKey: string): Promise<MailTestResult> {
    const result = await request<unknown>({ url: `/channels/mail/${row.id}/test`, method: 'POST',
      timeout: 180_000, data: { recipient, request_key: requestKey, revision: row.revision } })
    if (!record(result) || !['accepted', 'failed', 'unknown'].includes(String(result.status))
      || typeof result.message !== 'string' || typeof result.message_id !== 'string') return invalid()
    return result as unknown as MailTestResult
  },
}
