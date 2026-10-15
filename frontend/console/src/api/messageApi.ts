import { isObject, request } from './httpClient'

export interface InboxMessage { id: number; title: string; body: string; unread: boolean; created_at: string }
export const messageApi = {
  async summary(signal?: AbortSignal) {
    const value = await request('get', '/messages/summary', undefined, signal)
    if (!isObject(value) || !Number.isSafeInteger(value.unread) || Number(value.unread) < 0) throw new Error('消息响应格式无效')
    return Number(value.unread)
  },
  async list(page: number, signal?: AbortSignal): Promise<{ data: InboxMessage[]; total: number }> {
    const value = await request('get', `/messages?page=${page}&page_size=10`, undefined, signal)
    if (!isObject(value) || !Array.isArray(value.data) || !Number.isSafeInteger(value.total) || Number(value.total) < 0
      || value.data.some(row => !isObject(row) || !Number.isSafeInteger(row.id) || Number(row.id) < 1
        || ['title', 'body', 'created_at'].some(key => typeof row[key] !== 'string') || typeof row.unread !== 'boolean')) throw new Error('消息响应格式无效')
    return value as unknown as { data: InboxMessage[]; total: number }
  },
  read(id: number) { return request('post', `/messages/${id}/read`) },
  readAll() { return request('post', '/messages/read-all') },
}
