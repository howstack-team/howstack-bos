import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useHistory, useLocation, useParams } from 'react-router-dom'
import Button from '@arco-design/mobile-react/esm/button'
import Dialog from '@arco-design/mobile-react/esm/dialog'
import Form, { useForm } from '@arco-design/mobile-react/esm/form'
import Input from '@arco-design/mobile-react/esm/input'
import Textarea from '@arco-design/mobile-react/esm/textarea'
import Picker from '@arco-design/mobile-react/esm/picker'
import { ValidatorType } from '@arco-design/mobile-utils'
import { useTicketCommand, useTicketDetail, useTicketOptions, useTickets } from '../hooks/useTickets'
import { ticketApi, ticketStatuses, type TicketDetail } from '../api/ticketApi'
import { ticketFieldErrors } from '../../../packages/support-tickets'
import { useMemberAuth } from '../auth/AuthContext'
import { paths } from '../routePaths'
import { Busy, EmptyState } from './ui'
import { useFeedback } from './feedback'

const failureText = (error: unknown) => error instanceof Error ? error.message : '工单操作失败，请重试'
function useReadError(error: Error | null) {
  const feedback = useFeedback(), previous = useRef<unknown>(null)
  useEffect(() => { if (error && previous.current !== error) feedback(error.message); previous.current = error }, [error, feedback])
}
function Choice({ value, onChange, options, label, disabled }: { value?: number; onChange?: (value: number) => void; options: { value: number; label: string }[]; label: string; disabled?: boolean }) {
  const [open, setOpen] = useState(false)
  return <><Button inline type="default" size="small" disabled={disabled || !options.length} onClick={() => setOpen(true)}>{options.find(item => item.value === value)?.label ?? `请选择${label}`}</Button>
    <Picker title={label} visible={open} cascade={false} data={[options]} value={value === undefined ? undefined : [value]} maskClosable onHide={() => setOpen(false)} onOk={values => { onChange?.(Number(values[0])); setOpen(false) }} /></>
}
const textRules = (limit: number) => [{ type: ValidatorType.Custom, validator: (value: unknown, callback: (message?: string) => void) => callback(typeof value !== 'string' || !value.trim() ? '请输入内容' : value.trim().length > limit ? `最多 ${limit} 个字符` : undefined) }]
export default function TicketsPage() {
  const { id } = useParams<{ id?: string }>(), location = useLocation()
  if (id) return <Detail key={id} id={Number(id)} />
  return new URLSearchParams(location.search).get('create') === '1' ? <Create /> : <Listing />
}
function Listing() {
  const query = useTickets(), history = useHistory(), location = useLocation(), [statusOpen, setStatusOpen] = useState(false)
  useReadError(query.error)
  return <><section className="m-panel"><div className="m-row"><h2>我的工单</h2><Button inline size="small" onClick={() => history.push(`${paths.tickets}?create=1`)}>提交工单</Button></div>
    <div className="m-row"><Button inline size="small" type="default" onClick={() => setStatusOpen(true)}>{ticketStatuses[query.status as keyof typeof ticketStatuses] ?? '全部状态'}</Button><Button inline size="small" type="default" loading={query.isFetching} onClick={() => void query.refetch()}>刷新</Button></div>
    <Picker title="工单状态" visible={statusOpen} maskClosable cascade={false} data={[[{ value: '', label: '全部状态' }, ...Object.entries(ticketStatuses).map(([value, label]) => ({ value, label }))]]} value={[query.status]} onHide={() => setStatusOpen(false)} onOk={values => { query.change(1, query.pageSize, String(values[0])); setStatusOpen(false) }} />
    </section>
    {query.isPending ? <Busy /> : query.isError ? <section className="m-panel"><Button onClick={() => void query.refetch()}>重新加载</Button></section> : !query.data.data.length ? <section className="m-panel"><EmptyState text="暂无工单" /></section> : query.data.data.map(ticket => <section className="m-panel m-ticket" key={ticket.id}>
      <div className="m-row"><strong>{ticket.subject}</strong><span>{ticketStatuses[ticket.status]}</span></div><p className="m-muted">{ticket.ticket_no}</p><p className="m-muted">{ticket.type_name ?? '-'} · {ticket.updated_at}</p>
      <Button type="default" inline size="small" onClick={() => history.push(`${paths.tickets}/${ticket.id}`, { from: `${location.pathname}${location.search}` })}>查看工单</Button>
    </section>)}
    {!query.isError && !query.isPending && <section className="m-panel"><div className="m-pagination"><Button type="default" inline size="small" disabled={query.page <= 1} onClick={() => query.change(query.page - 1)}>上一页</Button><span>第 {query.page} 页 · 共 {query.data?.total ?? 0} 条</span><Button type="default" inline size="small" disabled={query.page * query.pageSize >= (query.data?.total ?? 0)} onClick={() => query.change(query.page + 1)}>下一页</Button></div></section>}
  </>
}
function Create() {
  const [form] = useForm(), options = useTicketOptions(), command = useTicketCommand(), history = useHistory(), feedback = useFeedback(), [errors, setErrors] = useState<Record<string, string>>({})
  useReadError(options.types.error)
  async function submit(values: Record<string, unknown>) {
    try {
      const result = await command.submit('create', null, { type_id: Number(values.type_id), service_id: values.service_id ? Number(values.service_id) : null, subject: String(values.subject).trim(), content: String(values.content).trim() })
      if (result) { feedback('工单已提交', 'success'); history.replace(`${paths.tickets}/${result.ticket_id}`) }
    } catch (error) { const fields = ticketFieldErrors(error); if (Object.keys(fields).length) setErrors(fields); else feedback(failureText(error)) }
  }
  return <section className="m-panel m-ticket"><div className="m-row"><h2>提交工单</h2><Button type="default" inline size="small" onClick={() => history.push(paths.tickets)}>返回列表</Button></div>
    {options.types.isPending ? <Busy /> : options.types.isError ? <Button onClick={() => void options.types.refetch()}>重新加载类型</Button> : !options.types.data?.length ? <EmptyState text="暂无可用工单类型，请联系运营方" /> : <Form form={form} layout="vertical" onSubmit={values => void submit(values)} onSubmitFailed={() => setErrors({})} onValuesChange={() => setErrors({})}>
      <Form.Item field="type_id" label="工单类型" disabled={command.busy} rules={[{ type: ValidatorType.Number, required: true, message: '请选择工单类型' }]} extra={errors.type_id ? <p className="m-field-error" role="alert">{errors.type_id}</p> : undefined}><Choice label="工单类型" disabled={command.busy} options={options.types.data.map(type => ({ value: type.id, label: type.name }))} /></Form.Item>
      <Form.Item field="service_id" label="关联云服务器（选填）" initialValue={0} disabled={command.busy} extra={errors.service_id ? <p className="m-field-error" role="alert">{errors.service_id}</p> : undefined}><Choice label="关联云服务器" disabled={command.busy} options={[{ value: 0, label: '不关联服务' }, ...options.serviceRows.map(service => ({ value: service.id, label: `${service.display_name} (${service.instance_no})` }))]} /></Form.Item>
      {options.services.isError && <Button type="default" inline size="small" onClick={() => void options.services.refetch()}>重新加载本人服务</Button>}
      {options.services.hasNextPage && <Button type="default" inline size="small" loading={options.services.isFetchingNextPage} onClick={() => void options.services.fetchNextPage()}>加载更多本人服务</Button>}
      <Form.Item field="subject" label="主题" trigger="onInput" disabled={command.busy} rules={textRules(200)} extra={errors.subject ? <p className="m-field-error" role="alert">{errors.subject}</p> : undefined}><Input ariaLabel="主题" placeholder="简要描述问题" maxLength={200} /></Form.Item>
      <Form.Item field="content" label="问题描述" trigger="onInput" disabled={command.busy} rules={textRules(10000)} extra={errors.content ? <p className="m-field-error" role="alert">{errors.content}</p> : undefined}><Textarea nativeProps={{ 'aria-label': '问题描述' }} placeholder="请提供问题现象，请勿填写密码或密钥" maxLength={10000} rows={5} /></Form.Item>
      <Button loading={command.busy} disabled={command.busy} onClick={() => form.submit()}>提交工单</Button>
    </Form>}
  </section>
}
function Detail({ id }: { id: number }) {
  const query = useTicketDetail(id), history = useHistory(), location = useLocation<{ from?: string }>()
  useReadError(query.error)
  return <><section className="m-panel"><div className="m-row"><Button type="default" inline size="small" onClick={() => history.push(location.state?.from?.startsWith(paths.tickets) ? location.state.from : paths.tickets)}>返回列表</Button><Button inline type="default" size="small" loading={query.isFetching} onClick={() => void query.refetch()}>刷新</Button></div></section>
    {query.isPending ? <Busy /> : query.isError ? <section className="m-panel"><Button onClick={() => void query.refetch()}>重新加载</Button></section> : query.data && <>
      <section className="m-panel m-ticket"><h2>{query.data.ticket.subject}</h2><p>{ticketStatuses[query.data.ticket.status]}</p><p className="m-muted">{query.data.ticket.ticket_no}</p><p className="m-muted">{query.data.ticket.type_name ?? '-'} · {query.data.ticket.created_at}</p>{query.data.ticket.service && <p>关联服务：{query.data.ticket.service.display_name} ({query.data.ticket.service.instance_no})</p>}<p className="m-ticket-content">{query.data.ticket.content}</p></section>
      <Conversation data={query.data} />
    </>}
  </>
}
function Conversation({ data }: { data: TicketDetail }) {
  const [form] = useForm(), command = useTicketCommand(), feedback = useFeedback(), { member } = useMemberAuth(), [before, setBefore] = useState<number | null>(null), [closing, setClosing] = useState(false), [fieldError, setFieldError] = useState('')
  const older = useQuery({ queryKey: ['console', 'tickets', member?.id, data.ticket.id, 'messages', before], queryFn: ({ signal }) => ticketApi.messages(data.ticket.id, before!, signal), enabled: before !== null, retry: false })
  useReadError(older.error)
  const rows = before === null ? data.messages : older.data?.data ?? [], next = before === null ? data.next_before_id : older.data?.next_before_id
  return <><section className="m-panel m-ticket"><h2>沟通记录</h2><div className="m-row">{next && <Button type="default" inline size="small" loading={older.isFetching} onClick={() => setBefore(next)}>查看更早记录</Button>}{before !== null && <Button inline size="small" type="default" onClick={() => setBefore(null)}>返回最新记录</Button>}{older.isError && <Button inline size="small" type="default" onClick={() => void older.refetch()}>重新加载记录</Button>}</div>
      {before !== null && older.isFetching ? <Busy /> : rows.length ? rows.map(row => <section className="m-ticket-message" key={row.id}><p className="m-muted">{row.author_type === 'admin' ? '工作人员' : '我'} · {row.created_at}</p><p className="m-ticket-content">{row.content}</p></section>) : <EmptyState text="暂无回复" />}
    </section>
    {data.ticket.status === 'closed' ? <section className="m-panel"><p>工单已关闭，不能继续回复。如有新问题，请重新提交工单。</p></section> : <section className="m-panel m-ticket"><Form form={form} layout="vertical" onSubmit={values => {
      void command.submit('reply', data.ticket.id, { content: String(values.content).trim() }).then(result => { if (result) { feedback('追问已提交', 'success'); form.resetFields(); setBefore(null) } }).catch(error => { const fields = ticketFieldErrors(error); if (fields.content) setFieldError(fields.content); else feedback(failureText(error)) })
    }} onSubmitFailed={() => setFieldError('')} onValuesChange={() => setFieldError('')}>
      <Form.Item field="content" label="继续追问" trigger="onInput" disabled={command.busy} rules={textRules(10000)} extra={fieldError ? <p role="alert" className="m-field-error">{fieldError}</p> : undefined}><Textarea nativeProps={{ 'aria-label': '继续追问' }} placeholder="补充问题信息，请勿填写密码或密钥" maxLength={10000} rows={4} /></Form.Item>
      <Button loading={command.busy} disabled={command.busy} onClick={() => form.submit()}>提交追问</Button>
    </Form><Button type="default" disabled={command.busy} style={{ marginTop: 12 }} onClick={() => setClosing(true)}>关闭工单</Button>
      <Dialog title="关闭工单" visible={closing} maskClosable close={() => setClosing(false)} footer={[{ content: '取消', onClick: () => setClosing(false) }, { content: command.busy ? '正在关闭…' : '确认关闭', disabled: command.busy, onClick: () => {
        void command.submit('close', data.ticket.id, {}).then(result => { if (result) { feedback('工单已关闭', 'success'); setClosing(false) } }).catch(error => feedback(failureText(error))); return true
      } }]}><p>关闭后不能继续回复，仅结束本次沟通，不会执行退款或资源操作。</p></Dialog>
    </section>}
  </>
}
