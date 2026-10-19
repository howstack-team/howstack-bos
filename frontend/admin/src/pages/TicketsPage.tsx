import { StatusBadge } from '../components/TableEnum'
import { TICKET_STATUSES as STATUSES, TICKET_PRIORITIES } from '../utils/businessEnums'
import { enumLabels, enumText } from '../utils/tableEnum'
import useTableQuery from '../hooks/useTableQuery'
import TableQueryForm from '../components/TableQueryForm'
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Form, Alert, Button, Descriptions, Drawer, Input, Link, Message, Modal, Select, Space, Table } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { supportApi } from '../api/supportContentApi.ts'
import type { QuickReply, Ticket, TicketMessage } from '../api/supportContentApi.ts'
import { useAdminAuth } from '../auth/AuthContext.ts'
import TableActions from '../components/TableActions.tsx'
import { tableCellText } from '../utils/tableCellText.ts'
import { TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'
import { ticketSubmission, ticketFieldErrors } from '../../../packages/support-tickets'

const PRIORITIES = enumLabels(TICKET_PRIORITIES)

export default function TicketsPage() {
  const searchFields = [
    { key: 'ticket_no', label: '工单编号', maxLength: 64 },
    { key: 'subject', label: '主题', maxLength: 128 },
    { key: 'member_mobile', label: '会员手机号', maxLength: 20 },
    { key: 'member_nickname', label: '会员昵称', maxLength: 64 },
  ]
  const { queryParams, sortColumn, filterColumn, onTableChange, draftFields, setField, discard, page, pageSize, setPage, setPageSize, search, reset, refreshKey, status, draftStatus, setDraftStatus } = useTableQuery({ sortFields: ['updated_at'], enumFields: { priority: Object.keys(PRIORITIES) }, textFields: searchFields.map(field => field.key), statusValues: Object.keys(STATUSES) })
  const { permissions } = useAdminAuth()
  const canReply = permissions.includes('admin.tickets.list.reply')
  const canClose = permissions.includes('admin.tickets.list.close')
  const canUseQuickReply = permissions.includes('admin.tickets.quick-replies.view')
  const [rows, setRows] = useState<Ticket[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [ticket, setTicket] = useState<Ticket | null>(null)
  const [messages, setMessages] = useState<TicketMessage[]>([])
  const [quickReplies, setQuickReplies] = useState<QuickReply[]>([])
  const [replyForm] = Form.useForm()
  const [nextBefore, setNextBefore] = useState<number | null>(null)
  const [older, setOlder] = useState(false), [closing, setClosing] = useState(false)
  const attempt = useRef<ReturnType<typeof ticketSubmission> | null>(null), writing = useRef(false), selected = useRef(selectedId)
  useLayoutEffect(() => { selected.current = selectedId; return () => { selected.current = null } }, [selectedId])
  const [saving, setSaving] = useState(false)
  const [detailError, setDetailError] = useState('')

  const listRequest = useRef(0)
  const invalidateList = useCallback(() => { listRequest.current++ }, [])
  const load = useCallback(async () => {
    const request = ++listRequest.current
    setLoading(true); setError('')
    try { const result = await supportApi.tickets({ ...queryParams, status, page, page_size: pageSize }); if (request !== listRequest.current) return
      setRows(result.data); setTotal(result.total) }
    catch (reason) {
      if (request !== listRequest.current) return
      setError(reason instanceof Error ? reason.message : '工单加载失败') }
    finally { if (request === listRequest.current) setLoading(false) }
  }, [queryParams, status, page, pageSize])

  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => { invalidateList(); window.clearTimeout(timer) } }, [load, refreshKey, invalidateList])
  useEffect(() => {
    if (selectedId === null) return
    let active = true
    const timer = window.setTimeout(() => {
      setTicket(null); setMessages([]); setDetailError(''); setClosing(false); setOlder(false); replyForm.resetFields(); attempt.current = null
      void supportApi.ticket(selectedId).then((result) => { if (active) { setTicket(result.ticket); setMessages(result.messages); setNextBefore(result.next_before_id) } })
        .catch((reason: unknown) => { if (active) setDetailError(reason instanceof Error ? reason.message : '详情加载失败') })
      if (canUseQuickReply) void supportApi.catalog<QuickReply>('quick-replies', { status: 1, page: 1, page_size: 100 })
        .then((result) => { if (active) setQuickReplies(result.data) }).catch(() => { if (active) setQuickReplies([]) })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [selectedId, canUseQuickReply, replyForm])

  async function latest(id: number) {
    const result = await supportApi.ticket(id)
    if (selected.current !== id) return
    setTicket(result.ticket); setMessages(result.messages); setNextBefore(result.next_before_id); setOlder(false)
  }

  async function earlier() {
    if (selectedId === null || nextBefore === null || writing.current) return
    const id = selectedId; setSaving(true); writing.current = true
    try { const result = await supportApi.messages(id, nextBefore); if (selected.current === id) { setMessages(result.data); setNextBefore(result.next_before_id); setOlder(true) } }
    catch (error) { if (selected.current === id) Message.error(error instanceof Error ? error.message : '记录加载失败') }
    finally { writing.current = false; setSaving(false) }
  }

  async function sendReply() {
    if (selectedId === null || writing.current) return
    let values: { content: string }; try { values = await replyForm.validate() } catch { return }
    if (writing.current) return
    const id = selectedId, content = values.content.trim()
    attempt.current = ticketSubmission(attempt.current, ['reply', id, content]); setSaving(true); writing.current = true
    try {
      await supportApi.reply(id, content, attempt.current.key)
      if (selected.current !== id) return
      attempt.current = null; Message.success('回复成功'); replyForm.resetFields()
      await latest(id); await load()
    } catch (reason) { if (selected.current !== id) return; const fields = ticketFieldErrors(reason); if (fields.content) replyForm.setFields({ content: { error: { message: fields.content } } }); else Message.error(reason instanceof Error ? reason.message : '回复失败') }
    finally { writing.current = false; setSaving(false) }
  }

  async function closeTicket() {
    if (selectedId === null || writing.current) return
    const id = selectedId; attempt.current = ticketSubmission(attempt.current, ['close', id]); setSaving(true); writing.current = true
    try { await supportApi.close(id, attempt.current.key); if (selected.current !== id) return; attempt.current = null; setClosing(false); Message.success('工单已关闭'); await latest(id); await load() }
    catch (reason) { if (selected.current === id) Message.error(reason instanceof Error ? reason.message : '关闭失败') }
    finally { writing.current = false; setSaving(false) }
  }

  const columns: TableColumnProps<Ticket>[] = [
    { title: '序号', width: 76, render: (_, __, index) => (page - 1) * pageSize + index + 1 },
    { title: '工单编号', width: 180, ellipsis: true, render: (_, row) => <Link title={row.ticket_no} onClick={() => setSelectedId(row.id)}>{row.ticket_no}</Link> },
    { title: '主题', dataIndex: 'subject', width: 260, ellipsis: true, render: tableCellText },
    { title: '类型', dataIndex: 'type_name', width: 150, ellipsis: true, render: (value) => tableCellText(value) },
    { title: '会员', dataIndex: 'member_nickname', width: 150, ellipsis: true, render: tableCellText },
    { ...filterColumn('priority', PRIORITIES), title: '优先级', width: 100, render: (_, row) => enumText(TICKET_PRIORITIES, row.priority) },
    { ...filterColumn('status', enumLabels(STATUSES)), title: '状态', width: 120, render: (_, row) => <StatusBadge value={row.status} mapping={STATUSES} /> },
    { ...sortColumn('updated_at'), title: '更新时间', dataIndex: 'updated_at', width: 180, render: tableCellText },
    { title: '操作', width: 114, fixed: 'right', render: (_, row) => <TableActions actions={[{ key: 'detail', label: '详情', onClick: () => setSelectedId(row.id) }]} /> },
  ]

  const detailRows = ticket ? [
    ['工单编号', ticket.ticket_no], ['主题', ticket.subject], ['类型', ticket.type_name],
    ['会员', ticket.member_nickname], ['会员手机号', ticket.member_mobile],
    ['优先级', PRIORITIES[ticket.priority] ?? ticket.priority], ['状态', STATUSES[ticket.status]?.text ?? ticket.status],
    ['创建时间', ticket.created_at], ['内容', ticket.content],
    ['关联服务', ticket.service_no ? `${ticket.service_name ?? ''} (${ticket.service_no})` : '-'],
  ].map(([label, value], index) => ({ key: String(index), label, value: <span style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{tableCellText(value)}</span> })) : []

  return <section className="page-card">
    <TableQueryForm fields={searchFields} values={draftFields} onFieldChange={setField}
      appliedCount={Object.keys(queryParams).filter(key => !['sort_by', 'sort_order'].includes(key)).length} onCancelFilters={discard}
      onSearch={search} onReset={reset} loading={loading}>

      <Form.Item label="状态" className="table-query-field" style={{ marginBottom: 0, marginRight: 0, minWidth: 0, maxWidth: '100%' }}><Select value={draftStatus} allowClear placeholder="全部状态" style={{ width: 140 }} onChange={setDraftStatus}
        options={Object.entries(STATUSES).map(([value, item]) => ({ value, label: item.text }))} /></Form.Item>
    </TableQueryForm>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="table-scroll"><Table onChange={onTableChange} rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: 1300 }}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: setPageSize }} /></div>
    <Drawer maskClosable title="工单详情" visible={selectedId !== null} width="min(760px, calc(100vw - 24px))" footer={null}
      onCancel={() => { setSelectedId(null); setTicket(null); replyForm.resetFields(); setClosing(false) }}>
      {detailError && <Alert type="error" content={detailError} />}
      {ticket && <>
        <Descriptions title="工单信息" data={detailRows} border column={{ xs: 1, sm: 1, md: 2, lg: 2, xl: 2 }} />
        <div style={{ marginTop: 24 }}><strong>沟通记录</strong>
          <Space wrap style={{ marginTop: 12, display: 'flex' }}>{nextBefore && <Button loading={saving} onClick={() => void earlier()}>查看更早记录</Button>}{older && <Button disabled={saving} onClick={() => { if (selectedId) void latest(selectedId).catch(error => Message.error(error.message)) }}>返回最新记录</Button>}</Space>
          <div style={{ marginTop: 12 }}>{messages.length === 0 ? '暂无回复' : messages.map((message) =>
            <div key={message.id} style={{ padding: '12px 0', borderBottom: '1px solid var(--color-border-2)' }}>
              <div>{message.author_type === 'admin' ? '后台人员' : '会员'} · {message.created_at}</div>
              <div style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', marginTop: 8 }}>{message.content}</div>
            </div>)}</div>
        </div>
        {canReply && ticket.status !== 'closed' && <div style={{ marginTop: 24 }}>
          {quickReplies.length > 0 && <Select placeholder="选择快捷回复" allowClear style={{ width: '100%', marginBottom: 12 }}
            options={quickReplies.map((item) => ({ value: item.id, label: item.title }))}
            onChange={(id) => { const item = quickReplies.find((entry) => entry.id === id); if (item) replyForm.setFieldValue('content', item.content) }} />}
          <Form form={replyForm} layout="vertical" disabled={saving}><Form.Item field="content" label="回复内容" rules={[{ validator: (value, callback) => callback(!value?.trim() ? '请输入回复内容' : value.trim().length > 10000 ? '回复内容最多10000个字符' : undefined) }]}><Input.TextArea autoSize={{ minRows: 4, maxRows: 8 }} maxLength={10000} placeholder="输入回复内容，请勿填写密码或密钥" /></Form.Item></Form>
          <Button type="primary" loading={saving} onClick={() => void sendReply()} style={{ marginTop: 12 }}>发送回复</Button>
        </div>}
        {canClose && ticket.status !== 'closed' && <Button status="danger" disabled={saving} style={{ marginTop: 16 }} onClick={() => setClosing(true)}>关闭工单</Button>}
        {ticket.status === 'closed' && <p>工单已关闭，不能继续回复。</p>}
      </>}
    </Drawer>
    <Modal maskClosable title="关闭工单" visible={closing} onCancel={() => setClosing(false)} onOk={() => void closeTicket()} confirmLoading={saving}>关闭后不能继续回复，仅结束沟通，不会执行退款或资源操作。</Modal>
  </section>
}
