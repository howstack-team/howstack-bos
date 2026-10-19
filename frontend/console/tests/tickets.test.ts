import assert from 'node:assert/strict'
import test from 'node:test'
import { ticketSubmission, ticketPagination, ticketFieldErrors } from '../../packages/support-tickets/index.ts'
import { parseTicketDetail, parseTicketMessages } from '../src/api/ticketApi.ts'

test('同内容网络重试沿用幂等键；换目标或内容必须换键', () => {
  const original = ticketSubmission(null, ['reply', 8, '正文'])
  assert.equal(ticketSubmission(original, ['reply', 8, '正文']).key, original.key)
  assert.notEqual(ticketSubmission(original, ['reply', 8, '修改']).key, original.key)
  assert.notEqual(ticketSubmission(original, ['close', 8]).key, original.key)
  assert.match(original.key, /^[a-f0-9]{32}$/)
})
test('会话响应有界、严格正序、游标与窗口一致，不保留私有作者ID', () => {
  const rows = [{ id: 1, author_type: 'admin', author_id: 900, content: '<img src=x onerror=alert(1)>', created_at: '2026-10-19 12:00:00' }]
  const parsed = parseTicketMessages({ data: rows, next_before_id: null })
  assert.equal(parsed.data[0].content, rows[0].content)
  assert.equal(Object.hasOwn(parsed.data[0], 'author_id'), false)
  assert.throws(() => parseTicketMessages({ data: Array(51).fill(rows[0]), next_before_id: null }))
  assert.throws(() => parseTicketMessages({ data: [rows[0], rows[0]], next_before_id: null }))
  assert.throws(() => parseTicketMessages({ data: rows, next_before_id: 2 }))
  assert.throws(() => parseTicketMessages({ data: [{ ...rows[0], author_type: 'unknown' }], next_before_id: null }))
})
test('本人详情丢弃额外身份，保留关闭与空关联；字段422与业务失败分开', () => {
  const detail = parseTicketDetail({ ticket: { id: 1, ticket_no: 'TK1', type_id: 2, type_name: '咨询', subject: '问题', content: '纯文本', priority: 'normal', status: 'closed', created_at: '2026-10-19 12:00:00', updated_at: '2026-10-19 12:00:00', closed_at: '2026-10-19 12:00:00', service: null, member_id: 9 }, messages: [], next_before_id: null })
  assert.equal(Object.hasOwn(detail.ticket, 'member_id'), false)
  assert.equal(detail.ticket.status, 'closed')
  assert.deepEqual(ticketFieldErrors({ status: 422, data: { errors: { content: ['内容不能为空'], request_key: ['无效'] } } }), { content: '内容不能为空' })
  assert.deepEqual(ticketFieldErrors({ status: 409, data: { errors: { content: ['关闭'] } } }), {})
  assert.deepEqual(ticketPagination('?page=0&page_size=999'), { page: 1, pageSize: 20 })
})
