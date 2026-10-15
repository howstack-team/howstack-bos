import { ApiRequestError, request } from './httpClient'

export interface InboxMessage { id: number; title: string; body: string; unread: boolean; created_at: string; category: string; topic: string; business_type: string; business_id: number }
export interface InboxSummary { unread: number; categories: Record<string, number>; topics: Record<string, number> }
export interface MessageLog { id: number; name: string; scene: string; purpose: string; business_type: string; business_id: number; created_at: string; delivery_states: Record<string, number>; status: string; groups: { id: string; name: string; audience: string; channel: string; count: number }[] }
export interface MessageDelivery { id: number; audience: string; account_id: number; group_key: string; group_name: string; read_at: string | null; channel: string; channel_name?: string; status: string; attempts: number; error_code: string; updated_at: string }
export interface MessageAttempt { delivery_id: number; attempt_no: number; status: string; error_code: string; duration_ms: number; created_at: string; finished_at: string | null }
export interface MessageLogDetail extends MessageLog { deliveries: MessageDelivery[]; attempts: MessageAttempt[] }
const object = (v: unknown): v is Record<string, unknown> => v !== null && typeof v === 'object' && !Array.isArray(v)
const counts = (v: unknown) => object(v) && Object.values(v).every(n => Number.isSafeInteger(n) && Number(n) >= 0)
const invalid = (): never => { throw new ApiRequestError('消息响应格式无效，请重新加载', 500, 50000) }
function list<T>(v: unknown, valid: (row: unknown) => boolean): { data: T[]; total: number } {
  if (!object(v) || !Array.isArray(v.data) || !v.data.every(valid) || !Number.isSafeInteger(v.total) || Number(v.total) < 0) return invalid()
  return v as { data: T[]; total: number }
}
const inbox = (v: unknown) => object(v) && Number.isSafeInteger(v.id) && Number(v.id) > 0 && typeof v.unread === 'boolean'
  && ['title', 'body', 'created_at', 'category', 'topic', 'business_type'].every(key => typeof v[key] === 'string') && Number.isSafeInteger(v.business_id)
const log = (v: unknown) => object(v) && Number.isSafeInteger(v.id) && ['name', 'scene', 'purpose', 'business_type', 'created_at'].every(key => typeof v[key] === 'string')
  && Number.isSafeInteger(v.business_id) && counts(v.delivery_states) && typeof v.status === 'string'
  && Array.isArray(v.groups) && v.groups.every(g => object(g) && ['id', 'name', 'audience', 'channel'].every(key => typeof g[key] === 'string') && Number.isSafeInteger(g.count) && Number(g.count) > 0)
export const messageApi = {
  async inbox(params: Record<string, string | number>, signal?: AbortSignal) { return list<InboxMessage>(await request<unknown>({ url: '/messages', params, signal }), inbox) },
  async summary(signal?: AbortSignal): Promise<InboxSummary> {
    const v = await request<unknown>({ url: '/messages/summary', signal })
    if (!object(v) || !Number.isSafeInteger(v.unread) || Number(v.unread) < 0 || !counts(v.categories) || !counts(v.topics)) return invalid()
    return v as unknown as InboxSummary
  },
  read(id: number) { return request<null>({ url: `/messages/${id}/read`, method: 'POST' }) },
  readAll() { return request<null>({ url: '/messages/read-all', method: 'POST' }) },
  async logs(params: Record<string, string | number>, signal?: AbortSignal) {
    const v = await request<unknown>({ url: '/message-logs', params, signal })
    const result = list<MessageLog>(v, log)
    if (!object(v) || !Array.isArray(v.scenes) || !v.scenes.every(scene => object(scene) && typeof scene.code === 'string' && typeof scene.name === 'string')) return invalid()
    return { ...result, scenes: v.scenes as { code: string; name: string }[] }
  },
  async detail(id: number, signal?: AbortSignal): Promise<MessageLogDetail> {
    const v = await request<unknown>({ url: `/message-logs/${id}`, signal })
    if (!log(v) || !object(v) || !Array.isArray(v.deliveries) || !Array.isArray(v.attempts)
      || !v.deliveries.every(d => object(d) && Number.isSafeInteger(d.id) && Number.isSafeInteger(d.account_id)
        && Number.isSafeInteger(d.attempts) && ['audience', 'group_key', 'group_name', 'channel', 'status', 'error_code', 'updated_at'].every(key => typeof d[key] === 'string') && (d.channel_name === undefined || typeof d.channel_name === 'string') && (d.read_at === null || typeof d.read_at === 'string'))
      || !v.attempts.every(a => object(a) && ['delivery_id', 'attempt_no', 'duration_ms'].every(key => Number.isSafeInteger(a[key]))
        && ['status', 'error_code', 'created_at'].every(key => typeof a[key] === 'string') && (a.finished_at === null || typeof a.finished_at === 'string'))) return invalid()
    return v as unknown as MessageLogDetail
  },
  retry(id: number) { return request<null>({ url: `/message-logs/deliveries/${id}/retry`, method: 'POST' }) },
}
