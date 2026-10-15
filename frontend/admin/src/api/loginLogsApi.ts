import type { TableSortQuery } from './tableQuery'
import { ApiRequestError, request } from './httpClient.ts'

/** 一次管理员登录尝试的展示快照。 */
export interface LoginLogRecord {
  id: number
  logged_at: string
  nickname: string
  /** 1 为成功，0 为失败。 */
  status: 0 | 1
  client_ip: string
  client_ip_location: string
  browser: string
  operating_system: string
}

/** 验证来自 Webman 的日志快照字段。 */
function isLoginLogRecord(value: unknown): value is LoginLogRecord {
  if (typeof value !== 'object' || value === null) return false
  const row = value as Record<string, unknown>
  return typeof row.id === 'number' && Number.isInteger(row.id)
    && typeof row.logged_at === 'string'
    && typeof row.nickname === 'string'
    && (row.status === 0 || row.status === 1)
    && typeof row.client_ip === 'string'
    && typeof row.client_ip_location === 'string'
    && typeof row.browser === 'string'
    && typeof row.operating_system === 'string'
}

/** 分页读取当前账号有权限查看的登录日志。 */
export async function listLoginLogs(params: TableSortQuery & { username?: string; nickname?: string; client_ip?: string; client_ip_location?: string; keyword?: string; status?: 0 | 1; page: number; page_size: number }): Promise<{ data: LoginLogRecord[]; total: number }> {
  const value = await request<unknown>({ url: '/login-logs', params })
  if (typeof value !== 'object' || value === null) throw new ApiRequestError('登录日志响应格式无效', 500, 50000)
  const result = value as Record<string, unknown>
  if (!Array.isArray(result.data) || !result.data.every(isLoginLogRecord)
    || typeof result.total !== 'number' || !Number.isInteger(result.total) || result.total < 0) {
    throw new ApiRequestError('登录日志响应格式无效', 500, 50000)
  }

  return { data: result.data, total: result.total }
}
