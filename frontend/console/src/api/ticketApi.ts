import { isObject, request } from './httpClient.ts'
import { ticketStatuses, type TicketStatus, type TicketMessage, type TicketMessageWindow, type TicketCommandResult } from '../../../packages/support-tickets/index.ts'
export { ticketStatuses }
export type { TicketStatus, TicketMessage, TicketMessageWindow, TicketCommandResult }
export interface TicketSummary { id: number; ticket_no: string; type_id: number; type_name: string | null; subject: string; priority: string; status: TicketStatus; created_at: string; updated_at: string; closed_at: string | null }
export interface MemberTicket extends TicketSummary { content: string; service: { id: number; instance_no: string; display_name: string } | null }
export interface TicketPage { data: TicketSummary[]; total: number; page: number; page_size: number }
export interface TicketDetail { ticket: MemberTicket; messages: TicketMessage[]; next_before_id: number | null }
export interface TicketCreateInput { type_id: number; service_id: number | null; subject: string; content: string }
const integer = (value: unknown): value is number => Number.isSafeInteger(value) && Number(value) > 0
const nullableId = (value: unknown): value is number | null => value === null || integer(value)
const fail = (): never => { throw new Error('工单响应格式无效') }

function summary(value: unknown): TicketSummary {
  if (!isObject(value) || !integer(value.id) || !integer(value.type_id) || typeof value.ticket_no !== 'string' || !value.ticket_no
    || (value.type_name !== null && typeof value.type_name !== 'string') || typeof value.subject !== 'string' || typeof value.priority !== 'string'
    || typeof value.status !== 'string' || !Object.hasOwn(ticketStatuses, value.status)
    || typeof value.created_at !== 'string' || typeof value.updated_at !== 'string' || (value.closed_at !== null && typeof value.closed_at !== 'string')) return fail()
  return { id: value.id, ticket_no: value.ticket_no, type_id: value.type_id, type_name: value.type_name as string | null, subject: value.subject,
    priority: value.priority, status: value.status as TicketStatus, created_at: value.created_at, updated_at: value.updated_at, closed_at: value.closed_at as string | null }
}
export function parseTicketMessages(value: unknown): TicketMessageWindow {
  if (!isObject(value) || !Array.isArray(value.data) || value.data.length > 50 || !nullableId(value.next_before_id)) return fail()
  const data = value.data.map(item => {
    if (!isObject(item) || !integer(item.id) || !['member', 'admin'].includes(String(item.author_type)) || typeof item.content !== 'string' || typeof item.created_at !== 'string') return fail()
    return { id: item.id, author_type: item.author_type as 'member' | 'admin', content: item.content, created_at: item.created_at }
  })
  if (data.some((item, index) => index > 0 && item.id <= data[index - 1].id) || (value.next_before_id !== null && value.next_before_id !== data[0]?.id)) return fail()
  return { data, next_before_id: value.next_before_id as number | null }
}
export function parseTicketDetail(value: unknown): TicketDetail {
  if (!isObject(value) || !isObject(value.ticket) || typeof value.ticket.content !== 'string') return fail()
  const source = value.ticket.service
  if (source !== null && (!isObject(source) || !integer(source.id) || typeof source.instance_no !== 'string' || typeof source.display_name !== 'string')) return fail()
  const window = parseTicketMessages({ data: value.messages, next_before_id: value.next_before_id })
  return { ticket: { ...summary(value.ticket), content: value.ticket.content, service: source === null ? null : { id: Number(source.id), instance_no: String(source.instance_no), display_name: String(source.display_name) } }, messages: window.data, next_before_id: window.next_before_id }
}
export const ticketApi = {
  async types(signal?: AbortSignal): Promise<{ id: number; name: string }[]> {
    const data = await request('get', '/tickets/types', undefined, signal)
    if (!Array.isArray(data) || data.some(row => !isObject(row) || !integer(row.id) || typeof row.name !== 'string')) return fail()
    return data.map(row => ({ id: row.id, name: row.name }))
  },
  async list(page: number, pageSize: number, status: string, signal?: AbortSignal): Promise<TicketPage> {
    const query = new URLSearchParams({ page: String(page), page_size: String(pageSize) }); if (status) query.set('status', status)
    const value = await request('get', `/tickets?${query}`, undefined, signal)
    if (!isObject(value) || !Array.isArray(value.data) || !Number.isSafeInteger(value.total) || Number(value.total) < 0 || value.page !== page || value.page_size !== pageSize || value.data.length > pageSize) return fail()
    return { data: value.data.map(summary), total: Number(value.total), page, page_size: pageSize }
  },
  async detail(id: number, signal?: AbortSignal) { return parseTicketDetail(await request('get', `/tickets/${id}`, undefined, signal)) },
  async messages(id: number, before: number, signal?: AbortSignal) { return parseTicketMessages(await request('get', `/tickets/${id}/messages?before_id=${before}`, undefined, signal)) },
  async command(action: 'create' | 'reply' | 'close', id: number | null, values: object, key: string): Promise<TicketCommandResult> {
    const value = await request('post', action === 'create' ? '/tickets' : `/tickets/${id}/${action === 'reply' ? 'replies' : 'close'}`, { ...values, request_key: key })
    if (!isObject(value) || !integer(value.ticket_id) || !nullableId(value.message_id)) return fail()
    return { ticket_id: value.ticket_id, message_id: value.message_id }
  },
}
