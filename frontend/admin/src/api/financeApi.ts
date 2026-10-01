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
  params: { page: number; page_size: number; status?: string },
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
