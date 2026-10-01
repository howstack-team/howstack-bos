import { ApiRequestError, request } from './httpClient.ts'

/** 操作日志列表仅包含可快速浏览的摘要。 */
export interface OperationLogSummary {
  id: number
  operated_at: string
  operator_nickname: string
  module: string
  operation: string
  status: 0 | 1
  client_ip: string
  client_ip_location: string
  duration_ms: number
}

/** 单条操作日志的有限审计详情。 */
export interface OperationLogDetail extends OperationLogSummary {
  operator_username: string
  method: string
  request_url: string
  http_status: number
  browser: string
  operating_system: string
  request_id: string
  request_params: string | null
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isSummary(value: unknown): value is OperationLogSummary {
  if (!isRecord(value)) return false
  return typeof value.id === 'number' && Number.isInteger(value.id)
    && typeof value.operated_at === 'string'
    && typeof value.operator_nickname === 'string'
    && typeof value.module === 'string'
    && typeof value.operation === 'string'
    && (value.status === 0 || value.status === 1)
    && typeof value.client_ip === 'string'
    && typeof value.client_ip_location === 'string'
    && typeof value.duration_ms === 'number' && Number.isInteger(value.duration_ms)
}

function isOptionalText(value: unknown): value is string | null {
  return value === null || typeof value === 'string'
}

function isDetail(value: unknown): value is OperationLogDetail {
  if (!isSummary(value)) return false
  const row = value as unknown as Record<string, unknown>
  return typeof row.operator_username === 'string'
    && typeof row.method === 'string'
    && typeof row.request_url === 'string'
    && typeof row.http_status === 'number'
    && typeof row.browser === 'string'
    && typeof row.operating_system === 'string'
    && typeof row.request_id === 'string'
    && isOptionalText(row.request_params)
}

/** 分页读取操作日志。 */
export async function listOperationLogs(params: { keyword?: string; status?: 0 | 1; page: number; page_size: number }): Promise<{ data: OperationLogSummary[]; total: number }> {
  const value = await request<unknown>({ url: '/operation-logs', params })
  if (!isRecord(value) || !Array.isArray(value.data) || !value.data.every(isSummary)
    || typeof value.total !== 'number' || !Number.isInteger(value.total) || value.total < 0) {
    throw new ApiRequestError('操作日志响应格式无效', 500, 50000)
  }

  return { data: value.data, total: value.total }
}

/** 读取操作日志的脱敏详情。 */
export async function getOperationLog(id: number): Promise<OperationLogDetail> {
  const value = await request<unknown>({ url: `/operation-logs/${id}` })
  if (!isDetail(value)) throw new ApiRequestError('操作日志详情格式无效', 500, 50000)
  return value
}
