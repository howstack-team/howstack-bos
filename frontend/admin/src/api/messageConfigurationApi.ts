import { ApiRequestError, request } from './httpClient'

export type MessageAudience = 'customer' | 'admin'
export type MessageChannel = 'email' | 'sms' | 'message' | 'wechat' | 'dingtalk' | 'wecom' | 'feishu'
export type MessageChannelValues = Record<string, string | number | boolean>
export interface MessageConfigurationRow {
  code: string
  name: string
  description: string
  channels: Partial<Record<MessageChannel, boolean>>
  revision: number
}
export interface MessageRecipientPolicy { mode: 'founder' | 'departments' | 'roles' | 'members'; ids: number[] }
export interface MessageConfigurationDetail extends Omit<MessageConfigurationRow, 'channels'> {
  audience: MessageAudience
  recipients: MessageRecipientPolicy
  recipient_options: Partial<Record<MessageRecipientPolicy['mode'], { id: number; name: string }[]>>
  channels: MessageChannel[]
  variables: { key: string; label: string }[]
  configurations: Partial<Record<MessageChannel, MessageChannelValues>>
  robot_status: Partial<Record<MessageChannel, boolean>>
  channel_options: Partial<Record<MessageChannel, { id: number; name: string; available: boolean }[]>>
}
export interface BatchMessageConfigurationInput {
  items: { code: string; revision: number }[]
  channel: MessageChannel
  channel_id?: number
  enabled?: boolean
}
export interface MessageConfigurationInput {
  recipients?: MessageRecipientPolicy
  revision: number
  configurations: Partial<Record<MessageChannel, MessageChannelValues>>
}
const allowed = { customer: ['email', 'sms', 'message', 'wechat'], admin: ['email', 'dingtalk', 'wecom', 'feishu', 'message'] }
const record = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value)
const invalid = (): never => { throw new ApiRequestError('消息配置响应格式无效，请重新加载', 500, 50000) }
function base(value: unknown): Record<string, unknown> {
  if (!record(value) || ['code', 'name', 'description'].some(key => typeof value[key] !== 'string')
    || !/^[a-z][a-z0-9_]{0,63}$/.test(String(value.code)) || !Number.isSafeInteger(value.revision) || Number(value.revision) < 0) return invalid()
  return value
}
function detail(value: unknown, audience: MessageAudience): MessageConfigurationDetail {
  const data = base(value)
  if (data.audience !== audience || !Array.isArray(data.channels) || data.channels.length < 1 || data.channels.length > allowed[audience].length
    || data.channels.some(channel => typeof channel !== 'string' || !allowed[audience].includes(channel))
    || new Set(data.channels).size !== data.channels.length || !Array.isArray(data.variables)
    || data.variables.some(item => !record(item) || typeof item.key !== 'string' || typeof item.label !== 'string')
    || !record(data.configurations) || !record(data.robot_status) || !record(data.channel_options)) return invalid()
  if (Object.entries(data.robot_status).some(([channel, configured]) => !['dingtalk', 'wecom', 'feishu'].includes(channel) || typeof configured !== 'boolean')) return invalid()
  for (const channel of data.channels as string[]) {
    const config = data.configurations[channel]
    if (!record(config) || typeof config.enabled !== 'boolean'
      || Object.values(config).some(v => !['string', 'number', 'boolean'].includes(typeof v))
      || Object.hasOwn(config, 'webhook_url') || Object.hasOwn(config, 'sign_secret')) return invalid()
    const options = data.channel_options[channel]
    if (options !== undefined && (!Array.isArray(options) || options.some(option => !record(option)
      || !Number.isSafeInteger(option.id) || Number(option.id) < 1 || typeof option.name !== 'string' || typeof option.available !== 'boolean'))) return invalid()
  }
  if (!record(data.recipients) || !['founder', 'departments', 'roles', 'members'].includes(String(data.recipients.mode))
    || !Array.isArray(data.recipients.ids) || data.recipients.ids.some(id => !Number.isSafeInteger(id) || Number(id) < 1)
    || !record(data.recipient_options) || Object.values(data.recipient_options).some(options => !Array.isArray(options)
      || options.some(option => !record(option) || !Number.isSafeInteger(option.id) || typeof option.name !== 'string'))) return invalid()
  return data as unknown as MessageConfigurationDetail
}
export const messageConfigurationApi = {
  async list(audience: MessageAudience, params: Record<string, string | number>, signal?: AbortSignal) {
    const data = await request<unknown>({ url: `/message-configurations/${audience}`, params, signal })
    if (!record(data) || !Array.isArray(data.data) || !Number.isSafeInteger(data.total) || Number(data.total) < 0
      || !record(data.channel_labels) || Object.keys(data.channel_labels).some(channel => !allowed[audience].includes(channel))
      || Object.keys(data.channel_labels).length !== allowed[audience].length || Object.values(data.channel_labels).some(label => typeof label !== 'string')) return invalid()
    const rows = data.data.map(value => {
      const row = base(value)
      if (!record(row.channels) || Object.entries(row.channels).some(([channel, status]) => !allowed[audience].includes(channel) || typeof status !== 'boolean')) return invalid()
      return row as unknown as MessageConfigurationRow
    })
    return { data: rows, total: data.total as number, channelLabels: data.channel_labels as Partial<Record<MessageChannel, string>> }
  },
  async batch(audience: MessageAudience, input: BatchMessageConfigurationInput) {
    const data = await request<unknown>({ url: `/message-configurations/${audience}/batch`, method: 'POST', data: input })
    if (!record(data) || data.updated !== input.items.length || data.channel !== input.channel || !Array.isArray(data.items)
      || data.items.length !== input.items.length || new Set(data.items.map(item => record(item) ? item.code : null)).size !== input.items.length || data.items.some(item => !record(item) || typeof item.code !== 'string'
        || !input.items.some(original => original.code === item.code && Number.isSafeInteger(item.revision) && item.revision === original.revision + 1))) return invalid()
    return data
  },
  async get(audience: MessageAudience, code: string, signal?: AbortSignal) {
    return detail(await request<unknown>({ url: `/message-configurations/${audience}/${code}`, signal }), audience)
  },
  async save(audience: MessageAudience, code: string, data: MessageConfigurationInput) {
    return detail(await request<unknown>({ url: `/message-configurations/${audience}/${code}`, method: 'PUT', data }), audience)
  },
}
