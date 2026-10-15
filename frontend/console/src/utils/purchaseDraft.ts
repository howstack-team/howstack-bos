import type { PurchaseSelection } from '../api/purchaseApi.ts'
import { isObject } from '../api/httpClient.ts'

/** 草稿不包含密码、令牌、金额、订单及供应商参数，仅在当前标签页保存两小时。 */
export const purchaseDraftKey = 'console.purchase.cloud-server.v1'
export interface PurchaseDraft { offer_id?: number; selection: PurchaseSelection; request_key: string; updated_at: number }
export function purchaseRequestKey(): string {
  // getRandomValues 可在局域网 HTTP 下使用，不依赖仅安全上下文可用的 randomUUID。
  return Array.from(crypto.getRandomValues(new Uint8Array(16)), value => value.toString(16).padStart(2, '0')).join('')
}
const numeric = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value >= 0 && value <= 1_000_000
/** 逐层白名单，未知及敏感字段即便来自篡改存储也不会恢复。 */
export function cleanPurchaseSelection(value: unknown): PurchaseSelection {
  if (!isObject(value)) return {}
  const result: Record<string, unknown> = {}
  for (const key of ['region_id', 'zone_id', 'line_id', 'flavor_group_id', 'flavor_id', 'image_type_id', 'image_id', 'protection_id', 'duration_months', 'quantity']) {
    if (numeric(value[key])) result[key] = value[key]
  }
  const disk = (item: unknown) => isObject(item) && numeric(item.type_id) && numeric(item.size_gib) ? { type_id: item.type_id, size_gib: item.size_gib } : null
  if (disk(value.system_disk)) result.system_disk = disk(value.system_disk)
  if (Array.isArray(value.data_disks)) result.data_disks = value.data_disks.slice(0, 32).map(disk).filter(item => item !== null)
  if (isObject(value.network) && ['egress_mbps', 'ingress_mbps', 'ipv4_count'].every(key => numeric((value.network as Record<string, unknown>)[key]))) {
    result.network = { billing_mode: 'fixed', egress_mbps: value.network.egress_mbps, ingress_mbps: value.network.ingress_mbps, ipv4_count: value.network.ipv4_count }
  }
  return { ...result, billing_mode: 'prepaid', login_method: 'password' }
}
export function readPurchaseDraft(storage: Pick<Storage, 'getItem' | 'removeItem'>, now = Date.now()): PurchaseDraft | null {
  try {
    const value: unknown = JSON.parse(storage.getItem(purchaseDraftKey) || 'null')
    if (!isObject(value) || !numeric(value.offer_id) || !isObject(value.selection) || typeof value.updated_at !== 'number'
      || value.updated_at > now || now - value.updated_at > 2 * 60 * 60 * 1000 || typeof value.request_key !== 'string' || !/^[a-f0-9]{32}$/.test(value.request_key)) {
      storage.removeItem(purchaseDraftKey); return null
    }
    return { offer_id: value.offer_id, selection: cleanPurchaseSelection(value.selection), request_key: value.request_key, updated_at: value.updated_at }
  } catch { return null }
}
export function savePurchaseDraft(storage: Pick<Storage, 'setItem'>, draft: PurchaseDraft): boolean {
  try { storage.setItem(purchaseDraftKey, JSON.stringify({ ...draft, selection: cleanPurchaseSelection(draft.selection) })); return true }
  catch { return false }
}
