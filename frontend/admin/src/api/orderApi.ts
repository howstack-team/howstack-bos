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

export interface OrderDetail extends OrderSummary { items: OrderItem[] }

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

export async function listOrders(params: { keyword?: string; status?: string; page: number; page_size: number }): Promise<{ data: OrderSummary[]; total: number }> {
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
  return result as unknown as OrderDetail
}
