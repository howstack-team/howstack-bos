import type { ComputeSelection, PreviewOptions, PricePreview } from './cloudProductApi'
import type { TableSortQuery } from './tableQuery'
import { ApiRequestError, request } from './httpClient.ts'

export interface OrderSummary {
  id: number
  order_no: string
  member_id: number
  member_mobile_snapshot: string
  member_nickname_snapshot: string
  order_type: string
  status: string
  payment_status: string
  currency: string
  subtotal_minor: number
  discount_minor: number
  payable_minor: number
  paid_minor: number
  refunded_minor: number
  remark: string
  paid_at: string | null
  completed_at: string | null
  cancelled_at: string | null
  created_at: string
  updated_at: string
}

export interface OrderItem {
  id: number
  line_no: number
  product_type: string
  product_type_version: string
  sales_version: string
  product_name: string
  quantity: number
  unit_price_minor: number
  total_minor: number
}

export interface OrderUnit {
  id: number
  order_item_id: number
  unit_index: number
  service_id: number | null
  allocated_minor: number
  refunded_minor: number
  reserved_refund_minor: number
}
export interface OrderDetail extends OrderSummary { items: OrderItem[]; units?: OrderUnit[] }

function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isOrder(value: unknown): value is OrderSummary {
  if (!isObject(value)) return false
  return typeof value.id === 'number' && typeof value.order_no === 'string'
    && typeof value.member_id === 'number' && typeof value.member_mobile_snapshot === 'string'
    && typeof value.member_nickname_snapshot === 'string' && typeof value.order_type === 'string'
    && typeof value.status === 'string' && typeof value.payment_status === 'string'
    && typeof value.currency === 'string' && typeof value.payable_minor === 'number'
    && typeof value.paid_minor === 'number' && typeof value.created_at === 'string'
}

function invalid(): never {
  throw new ApiRequestError('订单响应格式无效', 500, 50000)
}

export async function listOrders(params: TableSortQuery & { order_no?: string; member_mobile?: string; member_nickname?: string; keyword?: string; status?: string; order_type?: string; payment_status?: string; page: number; page_size: number }): Promise<{ data: OrderSummary[]; total: number }> {
  const result = await request<unknown>({ url: '/orders', params })
  if (!isObject(result) || !Array.isArray(result.data) || !result.data.every(isOrder)
    || typeof result.total !== 'number') return invalid()
  return { data: result.data, total: result.total }
}

export async function getOrder(id: number): Promise<OrderDetail> {
  const result = await request<unknown>({ url: `/orders/${id}` })
  if (!isOrder(result) || !isObject(result) || !Array.isArray(result.items)
    || !result.items.every((item: unknown) => isObject(item) && typeof item.id === 'number'
      && typeof item.line_no === 'number' && typeof item.product_name === 'string'
      && typeof item.quantity === 'number' && typeof item.unit_price_minor === 'number'
      && typeof item.total_minor === 'number')) return invalid()
  if (result.units !== undefined && (!Array.isArray(result.units) || !result.units.every((unit: unknown) =>
    isObject(unit) && ['id', 'order_item_id', 'unit_index', 'allocated_minor', 'refunded_minor', 'reserved_refund_minor'].every(key => Number.isSafeInteger(unit[key]))
    && (unit.service_id === null || Number.isSafeInteger(unit.service_id))))) return invalid()
  return result as unknown as OrderDetail
}

export interface OrderProduct { id: number; name: string; product_type: string; version_id: number }
export const orderManagementApi = {
  products: () => request<OrderProduct[]>({ url: '/orders/products' }),
  members: (search: string) => request<Array<{ id: number; label: string }>>({ url: '/orders/members', params: { search } }),
  options: (offerId: number, selection: ComputeSelection, versionId?: number) => request<PreviewOptions>({ url: '/orders/preview', method: 'POST', data: { offer_id: offerId, version_id: versionId, selection, mode: 'options' } }),
  price: (offerId: number, selection: ComputeSelection, versionId?: number) => request<PricePreview>({ url: '/orders/preview', method: 'POST', data: { offer_id: offerId, version_id: versionId, selection, mode: 'price' } }),
  create: (input: { request_key: string; member_id: number; offer_id: number; version_id: number; selection: ComputeSelection; expected_amount_minor: number; remark: string }) => request<{ id: number }>({ url: '/orders', method: 'POST', data: input }),
  act: (id: number, action: 'pay' | 'cancel' | 'update', data: { remark?: string; expected_remark?: string } = {}) => request<{ id: number }>({ url: `/orders/${id}/${action}`, method: 'POST', data }),
}
