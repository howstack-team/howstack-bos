import { isObject, publicRequest, request } from './httpClient'

/** 仅含可公开的资源参数，金额始终由服务端计算。 */
export interface PurchaseSelection {
  region_id?: number; zone_id?: number; line_id?: number; flavor_group_id?: number; flavor_id?: number;
  image_type_id?: number; image_id?: number; protection_id?: number; duration_months?: number; quantity?: number;
  billing_mode?: 'prepaid'; login_method?: 'password';
  system_disk?: { type_id: number; size_gib: number }; data_disks?: { type_id: number; size_gib: number }[];
  network?: { billing_mode?: 'fixed'; egress_mbps: number; ingress_mbps: number; ipv4_count: number };
}
export interface PurchaseProduct { id: number; name: string; version_id: number; product_type: 'cloud_server'; description: string; purchase_url: string }
export interface PurchaseResource {
  id: number; name: string; description?: string; cpu?: number; memory_mib?: number; group_id?: number; type_id?: number;
  min_cpu?: number; min_memory_mib?: number; min_disk_gib?: number; min_gib?: number; max_gib?: number; step_gib?: number;
  max_count?: number; cpu_model?: string; base_clock_mhz?: number; boost_clock_mhz?: number; capacity_gbps?: number;
  default_username?: string; default_port?: number; country_code?: string; test_ip?: string;
}
export type PurchaseResourceKind = 'regions' | 'zones' | 'lines' | 'flavor_groups' | 'flavors' | 'image_types' | 'images' | 'disk_types' | 'protections'
export interface BandwidthOption { min: number; max: number; step: number; default: number; included: number }
export interface PurchaseOptions {
  schema_version: '2.0.0'; options: Record<PurchaseResourceKind, PurchaseResource[]>; configuration: PurchaseSelection;
  invalid_fields: Record<string, string>; quantity_limit: number; system_disk_included_gib: number;
  durations: { months: number; label: string; discount_kind: string; rate_bps?: number; billed_months?: number }[];
  storage_policy: { default_type_id: number; default_size_gib: number; max_data_disks: number };
  line_network: null | { egress: BandwidthOption; ingress: BandwidthOption; ip: BandwidthOption & { mode: 'options' | 'range'; options: number[] } };
}
export interface PurchaseQuote {
  configuration: PurchaseSelection; currency: 'CNY'; quantity: number; unit_amount_minor: number; amount_minor: number;
  subtotal_minor: number; discount_minor: number;
  unit_breakdown: { key: string; label: string; amount_minor: number }[];
}
export interface MemberOrder {
  id: number; order_no: string; status: string; payment_status: string; currency: 'CNY'; payable_minor: number; paid_minor: number; created_at: string;
  items?: { product_name: string; quantity: number; total_minor: number }[];
}
const integer = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value >= 0
const resourceKinds: PurchaseResourceKind[] = ['regions', 'zones', 'lines', 'flavor_groups', 'flavors', 'image_types', 'images', 'disk_types', 'protections']

/** 校验匿名目录，不使用服务端提供的 URL 作为任意跳转目标。 */
export async function getPurchaseProducts(signal?: AbortSignal): Promise<PurchaseProduct[]> {
  const value = await publicRequest('get', '/products', undefined, signal)
  if (!Array.isArray(value) || !value.every(row => isObject(row) && integer(row.id) && row.id > 0 && integer(row.version_id)
    && row.version_id > 0 && row.product_type === 'cloud_server' && typeof row.name === 'string' && typeof row.description === 'string')) throw new Error('产品目录格式无效')
  return value as PurchaseProduct[]
}
/** 校验联动选项及数量范围；供应资料不属于公开响应。 */
export async function getPurchaseOptions(product: PurchaseProduct, selection: PurchaseSelection, signal?: AbortSignal): Promise<PurchaseOptions> {
  const value = await publicRequest('post', `/products/${product.id}/options`, { version_id: product.version_id, selection }, signal)
  if (!isObject(value) || value.schema_version !== '2.0.0' || !isObject(value.options) || !isObject(value.configuration) || !isObject(value.invalid_fields)
    || !integer(value.quantity_limit) || value.quantity_limit < 1 || !integer(value.system_disk_included_gib) || !isObject(value.storage_policy)
    || !['default_type_id', 'default_size_gib', 'max_data_disks'].every(key => integer((value.storage_policy as Record<string, unknown>)[key]))
    || !Array.isArray(value.durations) || !value.durations.every(row => isObject(row) && integer(row.months) && row.months > 0 && typeof row.label === 'string')
    || !resourceKinds.every(kind => { const rows = (value.options as Record<string, unknown>)[kind]; return Array.isArray(rows) && rows.every(row => isObject(row) && integer(row.id) && typeof row.name === 'string') })) throw new Error('产品选项格式无效')
  if (value.line_network !== null) {
    if (!isObject(value.line_network) || !['egress', 'ingress', 'ip'].every(key => {
      const band = (value.line_network as Record<string, unknown>)[key]
      return isObject(band) && ['min', 'max', 'step', 'default', 'included'].every(field => integer(band[field])) && Number(band.step) > 0 && Number(band.max) >= Number(band.min)
    })) throw new Error('网络选项格式无效')
  }
  return value as unknown as PurchaseOptions
}
/** 报价必须是安全整数分，拒绝无效币种及明细。 */
export async function getPurchaseQuote(product: PurchaseProduct, selection: PurchaseSelection, signal?: AbortSignal): Promise<PurchaseQuote> {
  const value = await publicRequest('post', `/products/${product.id}/quote`, { version_id: product.version_id, selection }, signal)
  if (!isObject(value) || value.currency !== 'CNY' || !isObject(value.configuration)
    || !['quantity', 'unit_amount_minor', 'amount_minor', 'subtotal_minor', 'discount_minor'].every(key => integer(value[key]))
    || !Array.isArray(value.unit_breakdown) || !value.unit_breakdown.every(row => isObject(row) && typeof row.key === 'string' && typeof row.label === 'string' && integer(row.amount_minor))) throw new Error('产品报价格式无效')
  return value as unknown as PurchaseQuote
}
/** 请求键在同一选配意图重试时保持一致，会员归属只由 Cookie 决定。 */
export async function createPurchaseOrder(product: PurchaseProduct, quote: PurchaseQuote, requestKey: string): Promise<number> {
  const value = await request('post', '/orders', { offer_id: product.id, version_id: product.version_id,
    selection: quote.configuration, expected_amount_minor: quote.amount_minor, request_key: requestKey })
  if (!isObject(value) || !integer(value.id) || value.id < 1) throw new Error('订单响应格式无效')
  return value.id
}
export function parseMemberOrder(value: unknown): MemberOrder {
  if (!isObject(value) || !integer(value.id) || typeof value.order_no !== 'string' || typeof value.status !== 'string'
    || typeof value.payment_status !== 'string' || value.currency !== 'CNY' || !integer(value.payable_minor) || !integer(value.paid_minor) || typeof value.created_at !== 'string'
    || (value.items !== undefined && (!Array.isArray(value.items) || !value.items.every(item => isObject(item) && typeof item.product_name === 'string' && integer(item.quantity) && integer(item.total_minor))))) throw new Error('订单数据格式无效')
  return value as unknown as MemberOrder
}
export async function getMemberOrder(id: number, signal?: AbortSignal): Promise<MemberOrder> { return parseMemberOrder(await request('get', `/orders/${id}`, undefined, signal)) }
export async function getMemberOrders(page: number, signal?: AbortSignal): Promise<{ data: MemberOrder[]; total: number }> {
  const value = await request('get', `/orders?page=${page}&page_size=20`, undefined, signal)
  if (!isObject(value) || !Array.isArray(value.data) || !integer(value.total)) throw new Error('订单列表格式无效')
  return { data: value.data.map(parseMemberOrder), total: value.total }
}
export async function payMemberOrder(id: number): Promise<void> { await request('post', `/orders/${id}/pay-wallet`) }
