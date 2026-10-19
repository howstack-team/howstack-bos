import type { TableSortQuery } from './tableQuery'
import { ApiRequestError, request } from './httpClient.ts'

export type FinanceResource = 'recharges' | 'payments' | 'transactions' | 'refunds' | 'invoices' | 'withdrawals'

export type FinanceRecord = Record<string, string | number | null> & { id: number; created_at: string }

function isRecord(value: unknown): value is FinanceRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    && typeof (value as FinanceRecord).id === 'number'
    && typeof (value as FinanceRecord).created_at === 'string'
}

export async function listFinanceRecords(
  resource: FinanceResource,
  params: TableSortQuery & { page: number; page_size: number; status?: string; number?: string; member_id?: string; created_from?: string; created_to?: string },
): Promise<{ data: FinanceRecord[]; total: number }> {
  const result = await request<unknown>({ url: `/finance/${resource}`, params })
  if (typeof result !== 'object' || result === null || !('data' in result) || !('total' in result)
    || !Array.isArray(result.data) || !result.data.every(isRecord) || typeof result.total !== 'number') {
    throw new ApiRequestError('财务记录响应格式无效', 500, 50000)
  }
  return { data: result.data, total: result.total }
}

export async function getFinanceRecord(resource: FinanceResource, id: number): Promise<FinanceRecord> {
  const result = await request<unknown>({ url: `/finance/${resource}/${id}` })
  if (!isRecord(result)) throw new ApiRequestError('财务记录响应格式无效', 500, 50000)
  return result
}

export type FinanceAction = 'create' | 'approve' | 'reject' | 'execute' | 'issue' | 'red' | 'payout'
export interface FinanceOption { id: number; label: string; available_minor?: number; order_id?: number }
export interface FinanceInput {
  request_key: string
  member_id?: number
  source_id?: number
  amount_minor?: number
  remark?: string
  reason?: string
  title?: string
  invoice_type?: string
  review_reason?: string
  payout_note?: string
  external_reference?: string
}

export async function financeOptions(resource: FinanceResource, memberId: number): Promise<FinanceOption[]> {
  return request<FinanceOption[]>({ url: `/finance/${resource}/options`, params: { member_id: memberId } })
}

export async function financeMembers(resource: FinanceResource, search = ''): Promise<FinanceOption[]> {
  return request<FinanceOption[]>({ url: `/finance/${resource}/members`, params: { search } })
}

export async function writeFinanceRecord(resource: FinanceResource, action: FinanceAction, id: number | null, input: FinanceInput): Promise<{ id: number }> {
  return request<{ id: number }>({ url: action === 'create' ? `/finance/${resource}` : `/finance/${resource}/${id}/${action}`,
    method: 'POST', data: input })
}
