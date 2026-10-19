import { parseVncTicket } from '../../../packages/vnc-runtime'
import { parseInstanceOperations, type OperationInput } from '../../../packages/service-operations'
import { parseRenewalQuote } from '../../../packages/service-renewal'
export { lifecycleLabel } from '../../../packages/service-renewal'
import type { TableSortQuery } from './tableQuery'
import { ApiRequestError, request } from './httpClient.ts'

/** 当前支持独立查看的服务类型。 */
export type ServiceProductType = 'cloud_server' | 'physical_server' | 'server_colocation'

/** 已创建资源的公共实例字段；类型专属配置由对应业务域管理。 */
export interface ServiceInstance {
  initial_password_available?: boolean; power_status?: string | null; power_checked_at?: string | null
  renewal_payment_pending?: boolean; can_renew?: boolean
  lifecycle_action?: string | null
  lifecycle_status?: string | null
  lifecycle_error?: string | null
  renewal_order_id?: number | null
  id: number
  instance_no: string
  product_type: ServiceProductType
  member_id: number
  member_nickname: string
  member_mobile: string
  order_no: string | null
  display_name: string
  status: string
  activated_at: string | null
  expires_at: string | null
  not_created_reference?: string | null
  not_created_reason?: string | null
  not_created_by?: number | null
  not_created_at?: string | null
  provisioning_status?: string | null
  provisioning_attempts?: number | null
  provisioning_error?: string | null
  can_confirm_not_created?: boolean
  addresses?: string[]
  created_at: string
}

function isServiceInstance(value: unknown): value is ServiceInstance {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return false
  const row = value as Record<string, unknown>
  return typeof row.id === 'number' && typeof row.instance_no === 'string'
    && typeof row.product_type === 'string' && typeof row.member_id === 'number'
    && typeof row.member_nickname === 'string' && typeof row.member_mobile === 'string'
    && (row.order_no === null || typeof row.order_no === 'string')
    && typeof row.display_name === 'string' && typeof row.status === 'string'
    && (row.activated_at === null || typeof row.activated_at === 'string')
    && (row.expires_at === null || typeof row.expires_at === 'string')
    && typeof row.created_at === 'string'
}

/** 分页查询某类已创建服务实例。 */
export async function listServiceInstances(params: TableSortQuery & {
  product_type: ServiceProductType
  instance_no?: string
  display_name?: string
  member_mobile?: string
  member_nickname?: string
  keyword?: string
  status?: string
  page: number
  page_size: number
}): Promise<{ data: ServiceInstance[]; total: number }> {
  const result = await request<unknown>({ url: '/service-instances', params })
  if (typeof result !== 'object' || result === null || !('data' in result) || !('total' in result)
    || !Array.isArray(result.data) || !result.data.every(isServiceInstance) || typeof result.total !== 'number') {
    throw new ApiRequestError('服务实例响应格式无效', 500, 50000)
  }
  return { data: result.data, total: result.total }
}

/** 查询已创建服务实例详情。 */
export async function getServiceInstance(id: number): Promise<ServiceInstance> {
  const result = await request<unknown>({ url: `/service-instances/${id}` })
  if (!isServiceInstance(result)) throw new ApiRequestError('服务实例响应格式无效', 500, 50000)
  return result
}

/** 未知结果只恢复查单；服务端独立校验生产操作权限及退款状态。 */
export async function retryServiceProvisioning(id: number): Promise<void> { await request({ method: 'POST', url: `/service-instances/${id}/retry-provisioning` }) }

export async function confirmServiceNotCreated(id: number, reason: string, evidenceReference: string): Promise<void> { await request({ method: 'POST', url: `/service-instances/${id}/confirm-not-created`, data: { acknowledged: true, reason, evidence_reference: evidenceReference } }) }

export async function quoteServiceRenewal(id: number, months: number, signal?: AbortSignal) {
  return parseRenewalQuote(await request<unknown>({ method: 'POST', url: `/service-instances/${id}/renewal-quote`, data: { months }, signal }))
}
export async function createServiceRenewal(id: number, months: number, amount: number, key: string): Promise<number> {
  const value = await request<{ id: number }>({ method: 'POST', url: `/service-instances/${id}/renewals`, data: { months, expected_amount_minor: amount, request_key: key } })
  if (!value || !Number.isSafeInteger(value.id) || value.id < 1) throw new Error('续费订单格式无效')
  return value.id
}
export async function retryServiceLifecycle(id: number) {
  await request({ method: 'POST', url: `/service-instances/${id}/retry-lifecycle` })
}

export async function getInstanceOperations(id: number, signal?: AbortSignal) {
  return parseInstanceOperations(await request<unknown>({ url: `/service-instances/${id}/operations`, signal }))
}
export async function submitInstanceOperation(id: number, data: OperationInput) {
  return request<{ id: number; status: string }>({ method: 'POST', url: `/service-instances/${id}/operations`, data })
}

/** 仅通过认证POST换取当前连接凭据，不放入查询缓存。 */
export async function openServiceVnc(id: number, signal?: AbortSignal) {
  return parseVncTicket(await request<unknown>({ method: 'POST', url: `/service-instances/${id}/vnc`, data: {}, signal }))
}
export async function checkServiceVnc(id: number, signal: AbortSignal): Promise<void> {
  const value = await request<{ allowed: boolean }>({ url: `/service-instances/${id}/vnc`, signal })
  if (value?.allowed !== true) throw new Error('当前不能访问VNC')
}
