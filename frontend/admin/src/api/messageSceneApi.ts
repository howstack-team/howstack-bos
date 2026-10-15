import { ApiRequestError, request } from './httpClient'
import type { MessageAudience, MessageChannel, MessageChannelValues, MessageRecipientPolicy } from './messageConfigurationApi'

export const MESSAGE_CHANNEL_LABELS: Record<MessageChannel, string> = { email: '邮件', sms: '短信', message: '站内消息', wechat: '公众号', dingtalk: '钉钉', wecom: '企业微信', feishu: '飞书' }
export const MESSAGE_GROUP_LABELS = { customer: '关联客户', admin: '内部人员', robot: '群机器人' } as const
export const MESSAGE_PURPOSE_LABELS: Record<string, string> = { notification: '业务通知', verification: '验证码', marketing: '营销消息', system: '系统消息' }
export type MessageGroupKind = keyof typeof MESSAGE_GROUP_LABELS
export interface MessageGroupSummary { id: string; name: string; kind: MessageGroupKind; enabled: boolean; channels: MessageChannel[]; available_channels: MessageChannel[] }
export interface MessageRecipientGroup { id: string; name: string; kind: MessageGroupKind; enabled: boolean; recipients: MessageRecipientPolicy; configurations: Partial<Record<MessageChannel, MessageChannelValues>> }
export interface MessageSceneMetadata { code: string; name: string; description: string; channels: MessageChannel[]; variables: { key: string; label: string }[] }
export interface MessageSceneRow { code: string; name: string; description: string; purpose: string; enabled: boolean; revisions: Partial<Record<MessageAudience, number>>; groups: MessageGroupSummary[]; metadata: Partial<Record<MessageAudience, MessageSceneMetadata>> }
export interface MessageSceneDetail extends Omit<MessageSceneRow, 'enabled' | 'groups'> {
  groups: MessageRecipientGroup[]
  metadata: MessageSceneRow['metadata']
  group_templates: Partial<Record<MessageGroupKind, MessageRecipientGroup>>
  channel_options: Partial<Record<MessageChannel, { id: number; name: string; available: boolean }[]>>
  recipient_options: Partial<Record<MessageRecipientPolicy['mode'], { id: number; name: string }[]>>
  robot_status: Partial<Record<MessageChannel, boolean>>
}
export interface MessageSceneInput { revisions: MessageSceneDetail['revisions']; groups: MessageRecipientGroup[] }
export interface BatchSceneInput { items: { code: string; revisions: MessageSceneDetail['revisions'] }[]; kind: MessageGroupKind; channel: MessageChannel; channel_id?: number; enabled?: boolean }
const groupChannels: Record<MessageGroupKind, readonly string[]> = { customer: ['email', 'sms', 'message', 'wechat'], admin: ['email', 'message'], robot: ['dingtalk', 'wecom', 'feishu'] }
const object = (v: unknown): v is Record<string, unknown> => v !== null && typeof v === 'object' && !Array.isArray(v)
const integer = (v: unknown) => Number.isSafeInteger(v) && Number(v) >= 0
const channelList = (v: unknown) => Array.isArray(v) && new Set(v).size === v.length && v.every(c => typeof c === 'string' && Object.hasOwn(MESSAGE_CHANNEL_LABELS, c))
const invalid = (): never => { throw new ApiRequestError('消息场景响应格式无效，请重新加载', 500, 50000) }
function base(v: unknown): v is Record<string, unknown> {
  return object(v) && ['code', 'name', 'description', 'purpose'].every(k => typeof v[k] === 'string')
    && /^[a-z][a-z0-9_]{0,63}$/.test(String(v.code)) && Object.hasOwn(MESSAGE_PURPOSE_LABELS, String(v.purpose))
    && object(v.revisions) && Object.keys(v.revisions).length > 0 && Object.entries(v.revisions).every(([key, value]) => ['customer', 'admin'].includes(key) && integer(value))
}
function metadata(v: unknown): boolean {
  return object(v) && Object.keys(v).every(key => ['customer', 'admin'].includes(key)) && Object.values(v).every(m => object(m)
    && ['code', 'name', 'description'].every(key => typeof m[key] === 'string') && channelList(m.channels) && Array.isArray(m.variables)
    && m.variables.every(item => object(item) && typeof item.key === 'string' && typeof item.label === 'string'))
}
function group(v: unknown): boolean {
  if (!object(v) || typeof v.id !== 'string' || !/^[a-zA-Z0-9_-]{1,40}$/.test(v.id) || typeof v.name !== 'string'
    || !Object.hasOwn(MESSAGE_GROUP_LABELS, String(v.kind)) || typeof v.enabled !== 'boolean' || !object(v.recipients)
    || !['founder', 'departments', 'roles', 'members'].includes(String(v.recipients.mode)) || !Array.isArray(v.recipients.ids)
    || !v.recipients.ids.every(id => integer(id) && Number(id) > 0) || !object(v.configurations)) return false
  return Object.entries(v.configurations).every(([channel, config]) => groupChannels[v.kind as MessageGroupKind].includes(channel)
    && object(config) && typeof config.enabled === 'boolean' && Object.entries(config).every(([key, value]) =>
      ['enabled', 'channel_id', 'title', 'body', 'template_id', 'variables'].includes(key) && (key === 'enabled' ? typeof value === 'boolean' : key === 'channel_id' ? integer(value) : typeof value === 'string')))
}
function detail(v: unknown): MessageSceneDetail {
  if (!base(v) || !Array.isArray(v.groups) || v.groups.length > 10 || !v.groups.every(group)
    || new Set(v.groups.map(g => (g as MessageRecipientGroup).id)).size !== v.groups.length || !object(v.metadata)
    || Object.keys(v.metadata).some(key => !['customer', 'admin'].includes(key))
    || !Object.values(v.metadata).every(m => object(m) && ['code', 'name', 'description'].every(key => typeof m[key] === 'string') && channelList(m.channels) && Array.isArray(m.variables)
      && m.variables.every(item => object(item) && typeof item.key === 'string' && typeof item.label === 'string'))
    || !object(v.group_templates) || !Object.entries(v.group_templates).every(([key, value]) => Object.hasOwn(MESSAGE_GROUP_LABELS, key) && group(value) && object(value) && value.kind === key)
    || !object(v.channel_options) || !Object.values(v.channel_options).every(options => Array.isArray(options) && options.every(o => object(o) && integer(o.id) && Number(o.id) > 0 && typeof o.name === 'string' && typeof o.available === 'boolean'))
    || !object(v.recipient_options) || !Object.values(v.recipient_options).every(options => Array.isArray(options) && options.every(o => object(o) && integer(o.id) && typeof o.name === 'string'))
    || !object(v.robot_status) || !Object.entries(v.robot_status).every(([channel, status]) => ['dingtalk', 'wecom', 'feishu'].includes(channel) && typeof status === 'boolean')) return invalid()
  const parsed = v as unknown as MessageSceneDetail
  if (parsed.groups.some(item => {
    const template = parsed.group_templates[item.kind]
    return !template || Object.keys(item.configurations).length !== Object.keys(template.configurations).length || Object.keys(item.configurations).some(channel => !Object.hasOwn(template.configurations, channel))
  })) return invalid()
  return parsed
}
export const messageSceneApi = {
  async list(params: Record<string, number>, signal?: AbortSignal) {
    const v = await request<unknown>({ url: '/message-scenes', params, signal })
    if (!object(v) || !Array.isArray(v.data) || !integer(v.total) || !v.data.every(row => base(row) && metadata(row.metadata) && typeof row.enabled === 'boolean'
      && Array.isArray(row.groups) && row.groups.every(g => object(g) && typeof g.id === 'string' && typeof g.name === 'string'
        && typeof g.enabled === 'boolean' && Object.hasOwn(MESSAGE_GROUP_LABELS, String(g.kind)) && channelList(g.channels) && channelList(g.available_channels)))) return invalid()
    return { data: v.data as MessageSceneRow[], total: Number(v.total) }
  },
  async get(code: string, signal?: AbortSignal) { return detail(await request<unknown>({ url: `/message-scenes/${code}`, signal })) },
  async save(code: string, data: MessageSceneInput) { return detail(await request<unknown>({ url: `/message-scenes/${code}`, method: 'PUT', data })) },
  async batch(data: BatchSceneInput) {
    const v = await request<unknown>({ url: '/message-scenes/batch', method: 'POST', data })
    if (!object(v) || v.updated !== data.items.length) return invalid()
    return v
  },
}
