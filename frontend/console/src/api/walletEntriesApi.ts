import { isObject, request } from './httpClient.ts'

/** 金额均为人民币整数分；不暴露会员、账户和来源内部主键。 */
export interface WalletEntry {
  entry_no: string
  entry_type: string
  amount_minor: number
  available_after_minor: number
  currency: 'CNY'
  business_type: 'recharge' | 'order' | 'refund' | 'withdrawal' | 'other'
  business_no: string | null
  created_at: string
}
export interface WalletEntryPage { data: WalletEntry[]; total: number; page: number; page_size: number }
export const walletEntryPageSizes = [10, 20, 50, 100] as const
export const walletEntryLabels: Readonly<Record<string, string>> = {
  credit: '充值入账', recharge_credit: '充值入账', spend: '订单支付', order_payment: '订单支付',
  refund_credit: '退款入账', withdrawal_freeze: '资金退还冻结', withdrawal_unfreeze: '资金退还解冻', withdrawal_payout: '冻结资金打款',
}
export const walletBusinessLabels: Readonly<Record<WalletEntry['business_type'], string>> = { recharge: '充值', order: '订单', refund: '退款', withdrawal: '资金退还', other: '其他' }

/** URL 分页与 API 共用范围；非法 URL 使用默认值，不将其他查询参数传给 API。 */
export function walletEntryPagination(search: string): { page: number; pageSize: number } {
  const params = new URLSearchParams(search), page = params.get('page') ?? '1', size = params.get('page_size') ?? '20'
  return {
    page: /^[1-9][0-9]*$/.test(page) && Number(page) <= 100000 ? Number(page) : 1,
    pageSize: /^[1-9][0-9]*$/.test(size) && walletEntryPageSizes.some(item => item === Number(size)) ? Number(size) : 20,
  }
}

/** 有符号余额变动精确显示为元；零变动不伪装成收入。 */
export function walletEntryAmount(minor: number): string {
  if (!Number.isSafeInteger(minor)) throw new Error('金额不是有效整数分')
  const absolute = Math.abs(minor)
  return `${minor > 0 ? '+' : minor < 0 ? '-' : ''}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`
}

/** 对真实接口做严格边界校验，拒绝不安全金额、越界分页和畸形业务字段。 */
export function parseWalletEntryPage(value: unknown): WalletEntryPage {
  if (!isObject(value) || !Array.isArray(value.data) || !Number.isSafeInteger(value.total) || Number(value.total) < 0
    || !Number.isSafeInteger(value.page) || Number(value.page) < 1 || Number(value.page) > 100000
    || !walletEntryPageSizes.some(item => item === value.page_size) || value.data.length > Number(value.page_size)
    || value.data.length > Number(value.total)) throw new Error('资金明细响应格式无效')
  const data: WalletEntry[] = value.data.map(row => {
    if (!isObject(row) || typeof row.entry_no !== 'string' || !row.entry_no || row.entry_no.length > 48
      || typeof row.entry_type !== 'string' || !row.entry_type || row.entry_type.length > 32
      || !Number.isSafeInteger(row.amount_minor) || !Number.isSafeInteger(row.available_after_minor) || Number(row.available_after_minor) < 0
      || row.currency !== 'CNY' || typeof row.business_type !== 'string' || !Object.hasOwn(walletBusinessLabels, row.business_type)
      || (row.business_no !== null && (typeof row.business_no !== 'string' || !row.business_no || row.business_no.length > 64))
      || typeof row.created_at !== 'string' || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(row.created_at)) throw new Error('资金明细响应格式无效')
    return { entry_no: row.entry_no, entry_type: row.entry_type, amount_minor: Number(row.amount_minor), available_after_minor: Number(row.available_after_minor),
      currency: 'CNY', business_type: row.business_type as WalletEntry['business_type'], business_no: row.business_no as string | null, created_at: row.created_at }
  })
  if (new Set(data.map(row => row.entry_no)).size !== data.length) throw new Error('资金明细响应格式无效')
  return { data, total: Number(value.total), page: Number(value.page), page_size: Number(value.page_size) }
}

/** 只读 GET；身份由 HttpOnly 会话决定，无客户端会员/账户选择。 */
export async function getWalletEntries(page: number, pageSize: number, signal?: AbortSignal): Promise<WalletEntryPage> {
  const result = parseWalletEntryPage(await request('get', `/wallet/entries?page=${page}&page_size=${pageSize}`, undefined, signal))
  if (result.page !== page || result.page_size !== pageSize) throw new Error('资金明细分页响应不一致')
  return result
}
