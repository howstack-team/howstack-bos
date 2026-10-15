import { ApiRequestError, request } from './httpClient.ts'

/** 经营分析只读查询，日期按服务端业务时区解释，最多包含366天。 */
export interface BusinessAnalysisQuery { start_date?: string; end_date?: string; granularity: 'day' | 'month' }
/** 金额均为整数分；净实付允许负数，付费会员按整个区间去重。 */
export interface AnalysisSummary {
  paid_amount_minor: number; refund_amount_minor: number; net_paid_amount_minor: number
  created_orders: number; paid_orders: number; paid_members: number; new_members: number; average_paid_order_minor: number
}
/** 服务端实际查询范围及同长度前期，金额固定人民币。 */
export interface AnalysisPeriod {
  start_date: string; end_date: string; previous_start_date: string; previous_end_date: string
  granularity: 'day' | 'month'; timezone: string; currency: 'CNY'; generated_at: string
}
/** 连续补零的日或月趋势，不包含按日累加的付费会员数。 */
export interface AnalysisTrend {
  date: string; created_orders: number; paid_orders: number; paid_amount_minor: number
  refund_amount_minor: number; net_paid_amount_minor: number; new_members: number
}
/** 类型分布的创建和付款统计分别基于各自发生时间。 */
export interface AnalysisOrderType { order_type: string; created_orders: number; paid_orders: number; paid_amount_minor: number }
/** 与 admin-business-analysis.v1.yaml 对齐的全站聚合响应，不包含客户或交易明细。 */
export interface BusinessAnalysis {
  period: AnalysisPeriod; summary: AnalysisSummary; previous_summary: AnalysisSummary
  trend: AnalysisTrend[]; order_types: AnalysisOrderType[]; order_statuses: Array<{ status: string; count: number }>
}
function object(value: unknown): value is Record<string, unknown> { return typeof value === 'object' && value !== null && !Array.isArray(value) }
function integers(value: Record<string, unknown>, fields: readonly string[], signed = '') {
  return fields.every(field => Number.isSafeInteger(value[field]) && (field === signed || Number(value[field]) >= 0))
}
/** 验证真实自然日，拒绝被 Date 自动归一化的非法日期。 */
export function isAnalysisDate(value: unknown): value is string {
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)
    && Number.isFinite(Date.parse(`${value}T00:00:00Z`)) && new Date(`${value}T00:00:00Z`).toISOString().slice(0, 10) === value
}
const summaryFields = ['paid_amount_minor', 'refund_amount_minor', 'net_paid_amount_minor', 'created_orders', 'paid_orders', 'paid_members', 'new_members', 'average_paid_order_minor'] as const
const trendFields = ['paid_amount_minor', 'refund_amount_minor', 'net_paid_amount_minor', 'created_orders', 'paid_orders', 'new_members'] as const
function validAnalysis(value: unknown): value is BusinessAnalysis {
  if (!object(value) || !object(value.period) || !object(value.summary) || !object(value.previous_summary)) return false
  const period = value.period
  if (!['start_date', 'end_date', 'previous_start_date', 'previous_end_date'].every(key => isAnalysisDate(period[key]))
    || !['day', 'month'].includes(String(period.granularity)) || period.currency !== 'CNY'
    || typeof period.timezone !== 'string' || !period.timezone
    || typeof period.generated_at !== 'string' || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(period.generated_at)) return false
  try { new Intl.DateTimeFormat('en', { timeZone: period.timezone }) } catch { return false }
  const start = String(period.start_date), end = String(period.end_date)
  if (start > end || (Date.parse(end) - Date.parse(start)) / 86400000 >= 366) return false
  if (!integers(value.summary, summaryFields, 'net_paid_amount_minor') || !integers(value.previous_summary, summaryFields, 'net_paid_amount_minor')) return false
  if (!Array.isArray(value.trend) || value.trend.length < 1 || value.trend.length > 366 || !value.trend.every(point => object(point)
    && (period.granularity === 'day' ? isAnalysisDate(point.date) : typeof point.date === 'string' && /^\d{4}-\d{2}$/.test(point.date) && isAnalysisDate(`${point.date}-01`))
    && integers(point, trendFields, 'net_paid_amount_minor'))) return false
  if (!Array.isArray(value.order_types) || !value.order_types.every(row => object(row) && typeof row.order_type === 'string' && integers(row, ['created_orders', 'paid_orders', 'paid_amount_minor']))) return false
  return Array.isArray(value.order_statuses) && value.order_statuses.every(row => object(row) && typeof row.status === 'string' && integers(row, ['count']))
}
/** 读取真实聚合结果并在API边界校验，支持取消旧查询；无权限由服务端拒绝。 */
export async function getBusinessAnalysis(query: BusinessAnalysisQuery, signal?: AbortSignal): Promise<BusinessAnalysis> {
  const value = await request<unknown>({ url: '/business-analysis', params: query, signal })
  if (!validAnalysis(value)) throw new ApiRequestError('经营分析响应格式无效，请重试', 500, 50000)
  return value
}
