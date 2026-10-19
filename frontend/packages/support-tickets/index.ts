/** 三端共用工单枚举与命令键，不依赖任一应用的组件。 */
export const ticketStatuses = { open: '等待后台', processing: '处理中', waiting_member: '等待会员', closed: '已关闭' } as const
export type TicketStatus = keyof typeof ticketStatuses
export interface TicketMessage { id: number; author_type: 'admin' | 'member'; content: string; created_at: string }
export interface TicketMessageWindow { data: TicketMessage[]; next_before_id: number | null }
export interface TicketCommandResult { ticket_id: number; message_id: number | null }
export function ticketPagination(search: string) {
  const values = new URLSearchParams(search), rawPage = values.get('page') ?? '1', size = Number(values.get('page_size') ?? 20)
  return { page: /^[1-9][0-9]*$/.test(rawPage) && Number(rawPage) <= 100000 ? Number(rawPage) : 1, pageSize: [10, 20, 50, 100].includes(size) ? size : 20 }
}
/** 两端错误类各自实现；仅提取422已登记字段，不重复弹业务提示。 */
export function ticketFieldErrors(error: unknown): Record<string, string> {
  if (!error || typeof error !== 'object' || !('status' in error) || error.status !== 422 || !('data' in error) || !error.data || typeof error.data !== 'object' || !('errors' in error.data) || !error.data.errors || typeof error.data.errors !== 'object') return {}
  return Object.fromEntries(Object.entries(error.data.errors).filter(([field, values]) => ['type_id', 'service_id', 'subject', 'content'].includes(field) && Array.isArray(values) && values.every(item => typeof item === 'string')).map(([field, values]) => [field, (values as string[]).join('；')]))
}
export function ticketRequestKey(): string {
  return Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('')
}
/** 网络重试沿用同内容同键；改内容或目标后重新生成，成功后由调用方重置。 */
export function ticketSubmission(previous: { fingerprint: string; key: string } | null, value: unknown) {
  const fingerprint = JSON.stringify(value)
  return previous?.fingerprint === fingerprint ? previous : { fingerprint, key: ticketRequestKey() }
}
