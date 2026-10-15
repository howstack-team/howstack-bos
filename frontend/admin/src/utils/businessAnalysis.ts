import { isAnalysisDate, type BusinessAnalysisQuery } from '../api/businessAnalysisApi.ts'

/** 经营分析查询的字段错误，不静默改写用户提供的非法条件。 */
export type AnalysisQueryErrors = Partial<Record<keyof BusinessAnalysisQuery, string>>
/** 按服务端返回的业务时区计算今天，首次读取前使用系统当前默认时区。 */
export function analysisToday(timezone = 'Asia/Shanghai', now = new Date()): string {
  const parts = new Intl.DateTimeFormat('en', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(now)
  return ['year', 'month', 'day'].map(type => parts.find(part => part.type === type)?.value).join('-')
}
/** 校验自然日、包含边界的366天区间、未来日期及趋势粒度。 */
export function validateAnalysisQuery(query: { start_date?: string; end_date?: string; granularity: string }, today: string): AnalysisQueryErrors {
  const errors: AnalysisQueryErrors = {}
  if (!['day', 'month'].includes(query.granularity)) errors.granularity = '请选择按日或按月统计'
  if (query.start_date !== undefined && !isAnalysisDate(query.start_date)) errors.start_date = '开始日期须为有效的 YYYY-MM-DD 日期'
  if (query.end_date !== undefined && !isAnalysisDate(query.end_date)) errors.end_date = '结束日期须为有效的 YYYY-MM-DD 日期'
  const end = query.end_date ?? today
  if (!errors.start_date && !errors.end_date) {
    if (end > today) errors.end_date = '结束日期不能晚于业务时区今天'
    if (query.start_date !== undefined) {
      if (query.start_date > end) errors.start_date = '开始日期不能晚于结束日期'
      else if ((Date.parse(end) - Date.parse(query.start_date)) / 86400000 >= 366) errors.start_date = '日期范围最多包含366天'
    }
  }
  return errors
}
/** 增长率以同长度前期的绝对值作基数，保留两位小数；前期为零时无对比。 */
export function analysisChange(current: number, previous: number): string {
  if (previous === 0) return '暂无对比'
  const delta = BigInt(current) - BigInt(previous), base = BigInt(Math.abs(previous))
  const absolute = delta < 0n ? -delta : delta
  const hundredths = (absolute * 10000n + base / 2n) / base
  return `${delta > 0n ? '+' : delta < 0n ? '-' : ''}${hundredths / 100n}.${String(hundredths % 100n).padStart(2, '0')}%`
}
