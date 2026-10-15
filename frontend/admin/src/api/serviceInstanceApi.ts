import type { TableSortQuery } from './tableQuery'
import { ApiRequestError, request } from './httpClient.ts'

/** 当前支持独立查看的服务类型。 */
export type ServiceProductType = 'cloud_server' | 'physical_server' | 'server_colocation'

/** 已创建资源的公共实例字段；类型专属配置由对应业务域管理。 */
export interface ServiceInstance {
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
