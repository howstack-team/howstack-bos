import { parseVncTicket } from '../../../packages/vnc-runtime'
import { parseInstanceOperations, type OperationInput } from '../../../packages/service-operations'
import { parseRenewalQuote } from '../../../packages/service-renewal'
export { lifecycleLabel } from '../../../packages/service-renewal'
import { request } from './httpClient'
export interface MemberService {
  initial_password_available?: boolean; power_status?: string | null; power_checked_at?: string | null
  renewal_payment_pending?: boolean; can_renew?: boolean; lifecycle_action?: string | null; lifecycle_status?: string | null; lifecycle_error?: string | null; renewal_order_id?: number | null
  id: number; instance_no: string; display_name: string; status: string; provisioning_status: string | null
  addresses: string[]; activated_at: string | null; expires_at: string | null; created_at: string; order_id: number | null; order_no: string | null
}
export const provisioningLabels: Record<string, string> = { queued: '等待开通', processing: '开通处理中', waiting: '等待供应商确认', succeeded: '已开通', failed: '开通失败', attention: '待管理员处理' }
export const serviceLabels: Record<string, string> = { provisioning: '开通中', active: '使用中', suspended: '已暂停', expired: '已到期', terminated: '已终止', failed: '开通失败' }
export function deliveryLabel(status?: string | null, fallback?: string | null) { if (status === 'succeeded' && fallback && fallback !== 'active') return serviceLabels[fallback] ?? fallback; return status ? provisioningLabels[status] ?? '处理中' : fallback ? serviceLabels[fallback] ?? fallback : '等待付款' }
function parse(value: unknown): MemberService {
  if (!value || typeof value !== 'object') throw new Error('服务数据格式无效')
  const row = value as Record<string, unknown>
  if (!Number.isSafeInteger(row.id) || typeof row.instance_no !== 'string' || typeof row.display_name !== 'string' || typeof row.status !== 'string'
    || !Array.isArray(row.addresses) || !row.addresses.every(ip => typeof ip === 'string')) throw new Error('服务数据格式无效')
  return row as unknown as MemberService
}
export async function getMemberServices(page: number, signal?: AbortSignal): Promise<{ data: MemberService[]; total: number }> {
  const result = await request('get', `/services?page=${page}&page_size=20`, undefined, signal) as { data?: unknown[]; total?: number }
  if (!result || !Array.isArray(result.data) || !Number.isSafeInteger(result.total)) throw new Error('服务列表格式无效')
  return { data: result.data.map(parse), total: result.total! }
}
/** 密码只保留在当前弹窗内存，不进入查询缓存、本地存储或 URL。 */
export async function getInitialPassword(id: number): Promise<string> {
  const result = await request('post', `/services/${id}/initial-password`, {}) as { password?: unknown }
  if (!result || typeof result.password !== 'string') throw new Error('初始密码响应格式无效')
  return result.password
}

/** 0返回该实例可用区的续费周期，其余值返回实时费用。 */
export async function quoteServiceRenewal(id: number, months: number, signal?: AbortSignal) {
  return parseRenewalQuote(await request('post', `/services/${id}/renewal-quote`, { months }, signal))
}
export async function createServiceRenewal(id: number, months: number, amount: number, key: string) {
  const value = await request('post', `/services/${id}/renewals`, { months, expected_amount_minor: amount, request_key: key }) as { id?: unknown }
  if (!value || !Number.isSafeInteger(value.id) || Number(value.id) < 1) throw new Error('续费订单格式无效')
  return Number(value.id)
}

export async function getInstanceOperations(id: number, signal?: AbortSignal) {
  return parseInstanceOperations(await request('get', `/services/${id}/operations`, undefined, signal))
}
export async function submitInstanceOperation(id: number, data: OperationInput) {
  return await request('post', `/services/${id}/operations`, data) as { id: number; status: string }
}

/** 不使用React Query或浏览器持久存储保存VNC凭据。 */
export async function openServiceVnc(id: number, signal?: AbortSignal) {
  return parseVncTicket(await request('post', `/services/${id}/vnc`, {}, signal))
}
export async function checkServiceVnc(id: number, signal: AbortSignal): Promise<void> {
  const value = await request('get', `/services/${id}/vnc`, undefined, signal) as { allowed?: unknown }
  if (value?.allowed !== true) throw new Error('当前不能访问VNC')
}
