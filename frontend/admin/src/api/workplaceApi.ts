import { memberApi } from './memberApi'
import { listOrders, type OrderSummary } from './orderApi'
import { listServiceInstances } from './serviceInstanceApi'
import { supportApi } from './supportContentApi'
import { listFinanceRecords } from './financeApi'
import { realNameAuthenticationApi } from './realNameAuthenticationApi'

/** 工作台复用已有列表总数；不对当前分页金额求和作为经营指标。 */
export type WorkplaceKey = 'members' | 'orders' | 'fulfilling' | 'tickets' | 'identity' | 'refunds' | 'invoices' | 'withdrawals' | 'cloud' | 'physical' | 'colocation'
/** 单个工作台数据源结果；最近订单与订单总数共用一次请求。 */
export interface WorkplaceResult { total: number; orders?: OrderSummary[] }
interface WorkplaceQuery { key: WorkplaceKey; permission: string; load: () => Promise<WorkplaceResult> }
const firstPage = { page: 1, page_size: 1 }
function count(result: { total: number }): WorkplaceResult {
  if (!Number.isSafeInteger(result.total) || result.total < 0) throw new Error('业务数量响应格式无效')
  return { total: result.total }
}

/** 查询仅在对应页面查看权限允许时执行，服务端继续校验权限与数据范围。 */
export const workplaceQueries: readonly WorkplaceQuery[] = [
  { key: 'members', permission: 'admin.members.view', load: async () => count(await memberApi.list(firstPage)) },
  { key: 'orders', permission: 'admin.page.order-list.view', load: async () => {
    const result = await listOrders({ page: 1, page_size: 5, sort_by: 'created_at', sort_order: 'desc' })
    if (result.data.some(order => !Number.isSafeInteger(order.payable_minor) || order.payable_minor < 0)) throw new Error('订单金额响应格式无效')
    return { ...count(result), orders: result.data }
  } },
  { key: 'fulfilling', permission: 'admin.page.order-list.view', load: async () => count(await listOrders({ ...firstPage, status: 'fulfilling' })) },
  { key: 'tickets', permission: 'admin.tickets.list.view', load: async () => count(await supportApi.tickets({ ...firstPage, status: 'open' })) },
  { key: 'identity', permission: 'admin.real-name-authentications.view', load: async () => count(await realNameAuthenticationApi.list({ ...firstPage, status: 'pending' })) },
  ...(['refunds', 'invoices', 'withdrawals'] as const).map(resource => ({ key: resource, permission: `admin.finance.${resource}.view`,
    load: async () => count(await listFinanceRecords(resource, { ...firstPage, status: 'pending_review' })) })),
  { key: 'cloud', permission: 'admin.services.cloud-servers.view', load: async () => count(await listServiceInstances({ ...firstPage, product_type: 'cloud_server', status: 'active' })) },
  { key: 'physical', permission: 'admin.services.physical-servers.view', load: async () => count(await listServiceInstances({ ...firstPage, product_type: 'physical_server', status: 'active' })) },
  { key: 'colocation', permission: 'admin.services.server-colocations.view', load: async () => count(await listServiceInstances({ ...firstPage, product_type: 'server_colocation', status: 'active' })) },
]
