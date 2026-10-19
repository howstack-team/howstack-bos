/** 续费三端共用公开契约；金额始终为整数分。 */
export interface RenewalQuote {
  service_id: number
  expires_at: string
  durations: { months: number; label: string }[]
  months?: number
  currency?: 'CNY'
  amount_minor?: number
}
export function parseRenewalQuote(value: unknown): RenewalQuote {
  if (!value || typeof value !== 'object') throw new Error('续费报价格式无效')
  const row = value as Record<string, unknown>
  if (!Number.isSafeInteger(row.service_id) || typeof row.expires_at !== 'string' || !Array.isArray(row.durations)
    || !row.durations.every(item => item && Number.isSafeInteger(item.months) && item.months > 0 && typeof item.label === 'string')
    || (row.months !== undefined && (!Number.isSafeInteger(row.months) || row.currency !== 'CNY' || !Number.isSafeInteger(row.amount_minor) || Number(row.amount_minor) < 1))) throw new Error('续费报价格式无效')
  return row as unknown as RenewalQuote
}
export const lifecycleLabels: Record<string, string> = { unpaid: '续费待付款', queued: '等待处理', processing: '处理中', waiting: '等待供应商确认', succeeded: '已完成', attention: '待管理员核查', cancelled: '已取消', failed: '执行失败' }
export function lifecycleLabel(action?: string | null, status?: string | null) {
  if (!status) return '—'
  const label = ({ renew: '续费', suspend: '到期暂停', resume: '续费恢复', power_on: '开机', power_off: '关机', reboot: '重启', reinstall: '重装系统', reset_password: '重置密码' } as Record<string, string>)[action ?? ''] ?? ''
  return `${label}${status === 'unpaid' ? '待付款' : `：${lifecycleLabels[status] ?? '未知状态'}`}`
}
/** 局域网HTTP下也可生成请求键；仅作幂等键，不是业务公开编号。 */
export function renewalRequestKey() {
  return `renew-${Array.from(crypto.getRandomValues(new Uint8Array(16)), value => value.toString(16).padStart(2, '0')).join('')}`
}

export const lifecycleErrors: Record<string, string> = {
  provider_result_unknown: '等待供应商确认原请求结果', adapter_changed: '原供应插件已变化，请核查原插件版本',
  lifecycle_unsupported: '原供应插件不支持续费和到期处理', service_state_conflict: '实例状态发生变化，需管理员核查',
  expiry_not_confirmed: '供应商尚未确认目标到期时间',
}
