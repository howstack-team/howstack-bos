import { isObject, request } from './httpClient.ts'

export type RechargeStatus = 'creating' | 'pending' | 'unknown' | 'paid' | 'closed' | 'failed'
export interface Recharge { id: number; recharge_no: string; payment_no: string; amount_minor: number; currency: 'CNY'; plugin_id: string; status: RechargeStatus; qr_code: string | null; expires_at: string; paid_at: string | null; created_at: string; message: string }
export interface RechargeOptions { enabled: boolean; currency: 'CNY'; min_minor: number; max_minor: number; channels: Array<{ id: number; name: string; plugin_id: string }> }
export const rechargePaths = { root: '/recharges', options: '/recharges/options', detail: (id: number) => `/recharges/${id}`, query: (id: number) => `/recharges/${id}/query`, close: (id: number) => `/recharges/${id}/close` }
export const rechargeLabels: Record<RechargeStatus, string> = { creating: '处理中', pending: '待付款', unknown: '待确认', paid: '已到账', closed: '已关闭', failed: '未创建成功' }
export const terminalRecharge = (status: RechargeStatus) => ['paid', 'closed', 'failed'].includes(status)
/** 元文本转换为整数分，不使用浮点乘法。 */
export function rechargeAmount(value: string): number | null {
  const match = /^(\d{1,7})(?:\.(\d{1,2}))?$/.exec(value.trim())
  if (!match) return null
  const minor = Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'))
  return Number.isSafeInteger(minor) && minor >= 1 && minor <= 100000000 ? minor : null
}
export function parseRecharge(value: unknown): Recharge {
  if (!isObject(value) || typeof value.id !== 'number' || !Number.isSafeInteger(value.id) || value.id < 1
    || typeof value.recharge_no !== 'string' || typeof value.payment_no !== 'string' || typeof value.amount_minor !== 'number' || !Number.isSafeInteger(value.amount_minor) || value.amount_minor < 1
    || value.currency !== 'CNY' || typeof value.plugin_id !== 'string' || typeof value.status !== 'string' || !Object.hasOwn(rechargeLabels, value.status)
    || typeof value.expires_at !== 'string' || typeof value.created_at !== 'string' || typeof value.message !== 'string' || (value.paid_at !== null && typeof value.paid_at !== 'string')
    || (value.qr_code !== null && typeof value.qr_code !== 'string')) throw new Error('充值响应格式无效')
  if (typeof value.qr_code === 'string' && value.qr_code.length > 2048) throw new Error('支付二维码格式无效')
  return { id: value.id, recharge_no: value.recharge_no, payment_no: value.payment_no, amount_minor: value.amount_minor, currency: 'CNY', plugin_id: value.plugin_id,
    status: value.status as RechargeStatus, expires_at: value.expires_at, paid_at: value.paid_at, created_at: value.created_at, qr_code: value.qr_code, message: value.message }
}
export async function getRechargeOptions(signal?: AbortSignal): Promise<RechargeOptions> {
  const value = await request('get', rechargePaths.options, undefined, signal)
  if (!isObject(value) || typeof value.enabled !== 'boolean' || value.currency !== 'CNY' || typeof value.min_minor !== 'number' || typeof value.max_minor !== 'number' || !Array.isArray(value.channels)) throw new Error('支付方式响应格式无效')
  const channels = value.channels.map(row => { if (!isObject(row) || typeof row.id !== 'number' || !Number.isSafeInteger(row.id) || row.id < 1 || typeof row.name !== 'string' || typeof row.plugin_id !== 'string') throw new Error('支付方式格式无效'); return { id: row.id, name: row.name, plugin_id: row.plugin_id } })
  return { enabled: value.enabled, currency: 'CNY', min_minor: value.min_minor, max_minor: value.max_minor, channels }
}
export async function listRecharges(page: number, signal?: AbortSignal): Promise<{ data: Recharge[]; total: number }> {
  const value = await request('get', `${rechargePaths.root}?page=${page}&page_size=20`, undefined, signal)
  if (!isObject(value) || !Array.isArray(value.data) || typeof value.total !== 'number' || !Number.isSafeInteger(value.total) || value.total < 0) throw new Error('充值列表响应格式无效')
  return { data: value.data.map(parseRecharge), total: value.total }
}
export async function createRecharge(channel_id: number, amount_minor: number, request_key: string): Promise<Recharge> { return parseRecharge(await request('post', rechargePaths.root, { channel_id, amount_minor, request_key })) }
export async function getRecharge(id: number, signal?: AbortSignal): Promise<Recharge> { return parseRecharge(await request('get', rechargePaths.detail(id), undefined, signal)) }
export async function queryRecharge(id: number, close = false): Promise<Recharge> { return parseRecharge(await request('post', close ? rechargePaths.close(id) : rechargePaths.query(id))) }
