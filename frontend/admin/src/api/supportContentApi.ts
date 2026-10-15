import type { TableSortQuery } from './tableQuery'
import { request } from './httpClient.ts'

export type CatalogStatus = 0 | 1
export type TicketCatalogKind = 'types' | 'quick-replies'
export type ContentKind = 'help' | 'news'
export type ContentResource = 'categories' | 'entries'
export interface TicketType { id: number; name: string; code: string; sort_order: number; status: CatalogStatus; created_at: string }
export interface QuickReply { id: number; title: string; content: string; sort_order: number; status: CatalogStatus; created_at: string }
export interface Ticket {
  id: number; ticket_no: string; member_id: number; member_nickname: string; member_mobile: string
  type_id: number; type_name: string; subject: string; content: string; priority: string; status: string
  created_at: string; updated_at: string; closed_at: string | null
}
export interface TicketMessage { id: number; ticket_id: number; author_type: string; author_id: number; content: string; created_at: string }
export interface ContentCategory {
  id: number; kind: ContentKind; name: string; parent_id: number | null; sort_order: number
  status: CatalogStatus; created_at: string; children?: ContentCategory[]
}
export interface ContentEntry {
  id: number; kind: ContentKind; category_id: number; title: string; summary: string; body: string
  body_format: 'markdown' | 'html'; sort_order: number; status: 'draft' | 'published'; published_at: string | null; created_at: string; updated_at: string
}
export interface PageResult<T> { data: T[]; total: number }
export interface ListParams extends TableSortQuery { priority?: string; name?: string; code?: string; title?: string; content?: string; ticket_no?: string; subject?: string; member_mobile?: string; member_nickname?: string; keyword?: string; status?: string | number; page: number; page_size: number }

export const supportApi = {
  catalog<T extends TicketType | QuickReply>(kind: TicketCatalogKind, params: ListParams) {
    return request<PageResult<T>>({ url: `/ticket-catalog/${kind}`, params })
  },
  saveCatalog<T extends TicketType | QuickReply>(kind: TicketCatalogKind, input: object, id?: number) {
    return request<T>({ method: id ? 'PUT' : 'POST', url: `/ticket-catalog/${kind}${id ? `/${id}` : ''}`, data: input })
  },
  deleteCatalog(kind: TicketCatalogKind, id: number) {
    return request<null>({ method: 'DELETE', url: `/ticket-catalog/${kind}/${id}` })
  },
  tickets(params: ListParams) { return request<PageResult<Ticket>>({ url: '/tickets', params }) },
  ticket(id: number) { return request<{ ticket: Ticket; messages: TicketMessage[] }>({ url: `/tickets/${id}` }) },
  reply(id: number, content: string) { return request<null>({ method: 'POST', url: `/tickets/${id}/replies`, data: { content } }) },
}

export const contentApi = {
  list<T extends ContentCategory | ContentEntry>(kind: ContentKind, resource: ContentResource, params: ListParams) {
    return request<PageResult<T>>({ url: `/content/${kind}/${resource}`, params })
  },
  detail<T extends ContentCategory | ContentEntry>(kind: ContentKind, resource: ContentResource, id: number) {
    return request<T>({ url: `/content/${kind}/${resource}/${id}` })
  },
  save<T extends ContentCategory | ContentEntry>(kind: ContentKind, resource: ContentResource, input: object, id?: number) {
    return request<T>({ method: id ? 'PUT' : 'POST', url: `/content/${kind}/${resource}${id ? `/${id}` : ''}`, data: input })
  },
  delete(kind: ContentKind, resource: ContentResource, id: number) {
    return request<null>({ method: 'DELETE', url: `/content/${kind}/${resource}/${id}` })
  },
}
