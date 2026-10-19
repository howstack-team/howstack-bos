import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useHistory, useLocation, useParams } from 'react-router-dom'
import { Badge, Button, Card, Descriptions, Empty, Form, Input, Link, Message, Modal, Select, Space, Spin, Table, Typography } from '@arco-design/web-react'
import { IconLeft, IconPlus, IconRefresh } from '@arco-design/web-react/icon'
import { useTicketCommand, useTicketDetail, useTicketOptions, useTickets } from '../hooks/useTickets'
import { ticketApi, ticketStatuses, type MemberTicket } from '../api/ticketApi'
import { ticketFieldErrors } from '../../../packages/support-tickets'
import { useMemberAuth } from '../auth/AuthContext'
import { paths } from '../routePaths'
import PageHeading from '../components/PageHeading'

const textError = (error: unknown) => error instanceof Error ? error.message : '工单操作失败，请重试'
const textRule = (limit: number) => ({ validator: (value: string | undefined, callback: (message?: import('react').ReactNode) => void) => callback(!value?.trim() ? '请输入内容' : value.trim().length > limit ? `最多 ${limit} 个字符` : undefined) })
const statusBadge = (status: keyof typeof ticketStatuses) => <Badge status={status === 'closed' ? 'default' : status === 'processing' ? 'processing' : 'warning'} text={ticketStatuses[status]} />
function useReadError(error: Error | null) {
  const previous = useRef<unknown>(null)
  useEffect(() => { if (error && previous.current !== error) Message.error(error.message); previous.current = error }, [error])
}

export default function TicketsPage() {
  const { id } = useParams<{ id?: string }>()
  return id ? <TicketDetailPage key={id} id={Number(id)} /> : <TicketListPage />
}
function TicketListPage() {
  const query = useTickets(), history = useHistory(), location = useLocation(), [create, setCreate] = useState(false)
  useReadError(query.error)
  return <div className="page-stack">
    <PageHeading title="工单管理" group="服务与帮助" description="提交问题并与工作人员沟通，关闭工单仅结束本次会话。" action={<Space wrap><Button icon={<IconRefresh />} loading={query.isFetching} onClick={() => void query.refetch()}>刷新</Button><Button type="primary" icon={<IconPlus />} onClick={() => setCreate(true)}>提交工单</Button></Space>} />
    <Card className="console-page-card">
      <Form layout="inline" style={{ marginBottom: 16 }}><Form.Item label="状态" style={{ marginBottom: 0 }}><Select aria-label="工单状态" placeholder="全部状态" value={query.status || undefined} allowClear style={{ width: 160 }} options={Object.entries(ticketStatuses).map(([value, label]) => ({ value, label }))} onChange={value => query.change(1, query.pageSize, value ?? '')} /></Form.Item></Form>
      {query.isError ? <Button onClick={() => void query.refetch()}>重新加载</Button> : <Table rowKey="id" data={query.data?.data ?? []} loading={query.isFetching} scroll={{ x: 900 }} noDataElement={<Empty description="暂无工单" />}
        columns={[{ title: '工单编号', dataIndex: 'ticket_no', width: 230, ellipsis: true }, { title: '主题', dataIndex: 'subject', width: 260, ellipsis: true }, { title: '类型', dataIndex: 'type_name', width: 140, render: value => value ?? '-' },
          { title: '状态', width: 130, render: (_, row) => statusBadge(row.status) }, { title: '更新时间', dataIndex: 'updated_at', width: 180 }, { title: '操作', width: 90, fixed: 'right', render: (_, row) => <Link onClick={() => history.push(`${paths.tickets}/${row.id}`, { from: `${location.pathname}${location.search}` })}>详情</Link> }]}
        pagination={{ current: query.page, pageSize: query.pageSize, total: query.data?.total ?? 0, showTotal: true, sizeCanChange: true, sizeOptions: [10, 20, 50, 100], onChange: (page, size) => query.change(page, size) }} />}
    </Card>
    {create && <CreateTicket close={() => setCreate(false)} />}
  </div>
}
function CreateTicket({ close }: { close: () => void }) {
  const [form] = Form.useForm(), options = useTicketOptions(), command = useTicketCommand(), history = useHistory()
  const error = options.types.error
  useReadError(error)
  async function submit() {
    let values: Record<string, unknown>
    try { values = await form.validate() } catch { return }
    try {
      const result = await command.submit('create', null, { type_id: Number(values.type_id), service_id: values.service_id ? Number(values.service_id) : null, subject: String(values.subject).trim(), content: String(values.content).trim() })
      if (result) { Message.success('工单已提交'); close(); history.push(`${paths.tickets}/${result.ticket_id}`) }
    } catch (failure) {
      const errors = ticketFieldErrors(failure)
      if (Object.keys(errors).length) form.setFields(Object.fromEntries(Object.entries(errors).map(([field, message]) => [field, { error: { message } }])))
      else Message.error(textError(failure))
    }
  }
  return <Modal visible maskClosable title="提交工单" onCancel={close} unmountOnExit style={{ width: 'min(560px, calc(100vw - 24px))' }} onOk={() => void submit()} confirmLoading={command.busy} okButtonProps={{ disabled: options.types.isPending || Boolean(error) || !options.types.data?.length }}>
    {options.types.isPending ? <Spin /> : error ? <Button onClick={() => void options.types.refetch()}>重新加载类型</Button> : !options.types.data?.length ? <Empty description="暂无可用工单类型，请联系运营方" /> : <Form form={form} layout="vertical" disabled={command.busy}>
      <Form.Item label="工单类型" field="type_id" rules={[{ required: true, message: '请选择工单类型' }]}><Select placeholder="请选择工单类型" options={options.types.data.map(type => ({ value: type.id, label: type.name }))} /></Form.Item>
      <Form.Item label="关联云服务器" field="service_id" extra="选填，仅可选择本人服务。"><Select aria-label="关联云服务器" allowClear loading={options.services.isFetching} placeholder="不关联服务" options={options.serviceRows.map(service => ({ value: service.id, label: `${service.display_name} (${service.instance_no})` }))} /></Form.Item>
      {options.services.isError && <Button onClick={() => void options.services.refetch()}>重新加载本人服务</Button>}
      {options.services.hasNextPage && <Button loading={options.services.isFetchingNextPage} onClick={() => void options.services.fetchNextPage()}>加载更多本人服务</Button>}
      <Form.Item label="主题" field="subject" rules={[textRule(200)]}><Input placeholder="简要描述问题" maxLength={200} /></Form.Item>
      <Form.Item label="问题描述" field="content" rules={[textRule(10000)]}><Input.TextArea placeholder="请提供问题现象和必要信息，请勿填写密码或密钥" maxLength={10000} autoSize={{ minRows: 5, maxRows: 10 }} /></Form.Item>
    </Form>}
  </Modal>
}
function TicketDetailPage({ id }: { id: number }) {
  const query = useTicketDetail(id), history = useHistory(), location = useLocation<{ from?: string }>()
  useReadError(query.error)
  const back = location.state?.from?.startsWith(paths.tickets) ? location.state.from : paths.tickets
  return <div className="page-stack"><PageHeading title="工单详情" group="服务与帮助" description="沟通内容仅本人及有权限的工作人员可见。" action={<Space wrap><Button icon={<IconLeft />} onClick={() => history.push(back)}>返回列表</Button><Button icon={<IconRefresh />} loading={query.isFetching} onClick={() => void query.refetch()}>刷新</Button></Space>} />
    {query.isPending ? <Spin /> : query.isError ? <Card className="console-page-card"><Button onClick={() => void query.refetch()}>重新加载</Button></Card> : query.data && <>
      <Card className="console-page-card"><Descriptions column={{ xs: 1, sm: 1, md: 2, lg: 2, xl: 2 }} data={[{ label: '工单编号', value: query.data.ticket.ticket_no }, { label: '状态', value: statusBadge(query.data.ticket.status) }, { label: '主题', value: query.data.ticket.subject }, { label: '类型', value: query.data.ticket.type_name ?? '-' }, { label: '关联服务', value: query.data.ticket.service ? `${query.data.ticket.service.display_name} (${query.data.ticket.service.instance_no})` : '-' }, { label: '创建时间', value: query.data.ticket.created_at }]} /><Typography.Paragraph style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', marginTop: 20 }}>{query.data.ticket.content}</Typography.Paragraph></Card>
      <TicketConversation key={id} ticket={query.data.ticket} latest={query.data.messages} next={query.data.next_before_id} />
    </>}
  </div>
}
function TicketConversation({ ticket, latest, next }: { ticket: MemberTicket; latest: import('../api/ticketApi').TicketMessage[]; next: number | null }) {
  const [form] = Form.useForm(), command = useTicketCommand(), [before, setBefore] = useState<number | null>(null), [closing, setClosing] = useState(false), { member } = useMemberAuth()
  const older = useQuery({ queryKey: ['console', 'tickets', member?.id, ticket.id, 'messages', before], queryFn: ({ signal }) => ticketApi.messages(ticket.id, before!, signal), enabled: before !== null, retry: false })
  useReadError(older.error)
  const rows = before === null ? latest : older.data?.data ?? [], cursor = before === null ? next : older.data?.next_before_id
  async function reply() {
    let values: Record<string, unknown>; try { values = await form.validate() } catch { return }
    try { if (await command.submit('reply', ticket.id, { content: String(values.content).trim() })) { Message.success('追问已提交'); form.resetFields(); setBefore(null) } }
    catch (error) { const fields = ticketFieldErrors(error); if (fields.content) form.setFields({ content: { error: { message: fields.content } } }); else Message.error(textError(error)) }
  }
  return <Card className="console-page-card" title="沟通记录">
    {Boolean(cursor || before !== null || older.isError) && <Space wrap>{cursor && <Button loading={older.isFetching} onClick={() => setBefore(cursor)}>查看更早记录</Button>}{before !== null && <Button onClick={() => setBefore(null)}>返回最新记录</Button>}{older.isError && <Button onClick={() => void older.refetch()}>重新加载记录</Button>}</Space>}
    {older.isFetching && before !== null ? <Spin /> : rows.length ? rows.map(row => <section key={row.id} style={{ padding: '16px 0', borderBottom: '1px solid var(--color-border-2)' }}><Typography.Text type="secondary">{row.author_type === 'admin' ? '工作人员' : '我'} · {row.created_at}</Typography.Text><Typography.Paragraph style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', marginTop: 8, marginBottom: 0 }}>{row.content}</Typography.Paragraph></section>) : <Empty description="暂无回复" />}
    {ticket.status === 'closed' ? <Typography.Paragraph type="secondary" style={{ marginTop: 16 }}>工单已关闭，不能继续回复。如有新问题，请重新提交工单。</Typography.Paragraph> : <div style={{ marginTop: 24 }}>
      <Form form={form} layout="vertical" disabled={command.busy} onSubmit={() => void reply()}><Form.Item field="content" label="继续追问" rules={[textRule(10000)]}><Input.TextArea placeholder="补充问题信息，请勿填写密码或密钥" maxLength={10000} autoSize={{ minRows: 4, maxRows: 10 }} /></Form.Item></Form>
      <Space wrap><Button type="primary" loading={command.busy} onClick={() => void reply()}>提交追问</Button><Button status="danger" disabled={command.busy} onClick={() => setClosing(true)}>关闭工单</Button></Space>
      <Modal title="关闭工单" visible={closing} maskClosable onCancel={() => setClosing(false)} confirmLoading={command.busy} onOk={() => {
        void command.submit('close', ticket.id, {}).then(result => { if (result) { Message.success('工单已关闭'); setClosing(false) } }).catch(error => Message.error(textError(error)))
      }}>关闭后不能继续回复，仅结束本次沟通，不会执行退款或资源操作。</Modal>
    </div>}
  </Card>
}
