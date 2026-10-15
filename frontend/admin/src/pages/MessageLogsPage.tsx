import { useEffect, useState } from 'react'
import type { KeyboardEvent } from 'react'
import { Alert, Badge, Button, Collapse, DatePicker, Descriptions, Drawer, Form, Message, Modal, Select, Spin, Table, Typography } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { IconRefresh } from '@arco-design/web-react/icon'
import { useHistory, useLocation } from 'react-router-dom'
import { messageApi, type MessageDelivery, type MessageLog, type MessageLogDetail } from '../api/messageApi'
import { useAdminAuth } from '../auth/AuthContext'
import TableActions from '../components/TableActions'
import TableQueryForm from '../components/TableQueryForm'
import useTableQuery from '../hooks/useTableQuery'
import { MESSAGE_PURPOSE_LABELS } from '../api/messageSceneApi'
import { TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination'

const channels: Record<string, string> = { message: '站内消息', email: '邮件', sms: '短信', wechat: '公众号', dingtalk: '钉钉', wecom: '企业微信', feishu: '飞书' }
const states: Record<string, { text: string; status: 'default' | 'processing' | 'success' | 'warning' | 'error' }> = {
  pending: { text: '待发送', status: 'default' }, processing: { text: '发送中', status: 'processing' },
  accepted: { text: '已受理', status: 'success' }, delivered: { text: '已送达', status: 'success' },
  failed: { text: '失败', status: 'error' }, unknown: { text: '结果未知', status: 'warning' },
}
const errors: Record<string, string> = { recipient_disabled: '接收账号已停用', robot_configuration_changed: '机器人配置已变化', channel_not_integrated: '该渠道尚未接入发送', channel_or_recipient_invalid: '通道配置已变化或收件地址无效',
  worker_lease_expired: '处理进程超时，请核实厂商流水', transport_outcome_unknown: '无法确认厂商是否受理', provider_failed: '厂商明确拒绝',
  provider_unknown: '厂商结果未知', provider_request_mismatch: '厂商请求标识不一致', robot_not_configured: '机器人尚未配置',
  robot_rejected: '机器人拒绝消息', robot_response_invalid: '机器人响应格式异常', robot_outcome_unknown: '机器人结果未知', robot_http_outcome_unknown: '机器人HTTP结果未知' }
const outcomes: typeof states = { ...states, inactive: { text: '未启用发送', status: 'default' }, partial: { text: '部分受理', status: 'warning' }, accepted: { text: '全部受理', status: 'success' } }
const audiences: Record<string, string> = { customer: '客户', admin: '管理员', robot: '机器人' }
const outcomeLabels = Object.fromEntries(Object.entries(outcomes).map(([key, item]) => [key, item.text]))
const businessLabels: Record<string, string> = { order: '订单', recharge: '充值', ticket: '工单', real_name: '实名认证', real_name_authentication: '实名认证', system: '系统' }
function outcome(value: string) { const item = outcomes[value] ?? outcomes.unknown; return <Badge status={item.status} text={item.text} /> }
function state(value: string) { const item = states[value] ?? states.unknown; return <Badge status={item.status} text={item.text} /> }
// 回车先确认日期选择，避免范围尚未选完时提交外层查询表单。
function confirmDate(event: KeyboardEvent<HTMLInputElement>) { if (event.key === 'Enter') event.preventDefault() }

/** 逻辑消息与投递分层展示；日志不展示正文、验证码、收件地址或凭据。 */
export default function MessageLogsPage() {
  const { permissions } = useAdminAuth()
  const history = useHistory(), location = useLocation()
  const { draftFields, setField, setFields, filterColumn, queryParams, search, reset, discard, page, pageSize, setPage, setPageSize, refreshKey, sortColumn, onTableChange } = useTableQuery({ textFields: ['scene', 'channel', 'status', 'created_from', 'created_to'], sortFields: ['created_at'], enumFields: { purpose: Object.keys(MESSAGE_PURPOSE_LABELS), status: Object.keys(outcomes) } })
  const [rows, setRows] = useState<MessageLog[]>([]), [total, setTotal] = useState(0), [loading, setLoading] = useState(false), [error, setError] = useState('')
  const [sceneOptions, setSceneOptions] = useState<{ code: string; name: string }[]>([])
  const [refresh, setRefresh] = useState(0), [detail, setDetail] = useState<MessageLogDetail | null>(null), [detailError, setDetailError] = useState(''), [detailLoading, setDetailLoading] = useState(false)
  const selected = Number(new URLSearchParams(location.search).get('message')) || null
  const select = (id: number | null) => { const params = new URLSearchParams(location.search); if (id) params.set('message', String(id)); else params.delete('message'); history.push({ ...location, search: params.toString() }) }
  // 清理旧版低频条件，避免历史链接带入界面不可见的筛选。
  useEffect(() => {
    const params = new URLSearchParams(location.search)
    const retired = ['keyword', 'delivery_status', 'audience', 'business_type', 'business_id']
    if (!retired.some(key => params.has(key))) return
    retired.forEach(key => params.delete(key))
    params.set('page', '1')
    history.replace({ ...location, search: params.toString() })
  }, [history, location])
  useEffect(() => {
    if (!permissions.includes('admin.message-logs.view')) return undefined
    const controller = new AbortController(); const timer = window.setTimeout(() => { setLoading(true)
    void messageApi.logs({ ...queryParams, page, page_size: pageSize }, controller.signal).then(result => { if (!controller.signal.aborted) { setRows(result.data); setTotal(result.total); setSceneOptions(result.scenes); setError('') } })
      .catch(cause => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : '消息日志加载失败') }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    }, 0)
    return () => { controller.abort(); window.clearTimeout(timer) }
  }, [queryParams, page, pageSize, refreshKey, refresh, permissions])
  useEffect(() => {
    if (!selected || !permissions.includes('admin.message-logs.view')) return undefined
    const controller = new AbortController(); const timer = window.setTimeout(() => { setDetail(null); setDetailError(''); setDetailLoading(true)
    void messageApi.detail(selected, controller.signal).then(value => { if (!controller.signal.aborted) setDetail(value) })
      .catch(cause => { if (!controller.signal.aborted) setDetailError(cause instanceof Error ? cause.message : '详情加载失败') }).finally(() => { if (!controller.signal.aborted) setDetailLoading(false) })
    }, 0)
    return () => { controller.abort(); window.clearTimeout(timer) }
  }, [selected, refresh, permissions])
  function retry(row: MessageDelivery) {
    Modal.confirm({ maskClosable: true, title: '重试失败消息', content: '按原模板和通道配置修订重新发送。配置已变化时会停止发送；结果未知的消息不允许重试。',
      onOk: async () => { try { await messageApi.retry(row.id); setRefresh(value => value + 1); Message.success('已加入发送队列') }
        catch (cause) { Message.error(cause instanceof Error ? cause.message : '重试失败'); throw cause } } })
  }
  const columns: TableColumnProps<MessageLog>[] = [
    { title: '消息场景', dataIndex: 'name', width: 160, ellipsis: true },
    { title: '消息类型', ...filterColumn('purpose', MESSAGE_PURPOSE_LABELS), width: 110, render: value => MESSAGE_PURPOSE_LABELS[value] ?? value },
    { title: '关联业务', width: 130, ellipsis: true, render: (_, row) => `${businessLabels[row.business_type] ?? row.business_type} #${row.business_id}` },
    { title: '接收对象', width: 140, ellipsis: true, render: (_, row) => [...new Set(row.groups.map(g => audiences[g.audience] ?? g.audience))].join('、') || undefined },
    { title: '发送渠道', width: 160, ellipsis: true, render: (_, row) => [...new Set(row.groups.map(g => channels[g.channel] ?? g.channel))].join('、') || undefined },
    { title: '总体结果', ...filterColumn('status', outcomeLabels), width: 120, render: outcome },
    { title: '记录时间', dataIndex: 'created_at', width: 190, ...sortColumn('created_at') },
    { title: '操作', width: 90, fixed: 'right', render: (_, row) => <TableActions actions={[{ key: 'view', label: '详情', onClick: () => select(row.id) }]} /> },
  ]
  if (!permissions.includes('admin.message-logs.view')) return <section className="page-card"><Alert type="warning" content="你没有查看消息日志的权限。" /></section>
  return <section className="page-card">
    <TableQueryForm appliedCount={Object.values(queryParams).filter(Boolean).length} onSearch={search} onReset={reset} onCancelFilters={discard} loading={loading}>
      <Form.Item label="场景"><Select aria-label="消息场景" style={{ width: 150 }} placeholder="全部场景" allowClear value={draftFields.scene || undefined} onChange={value => setField('scene', value ?? '')} options={sceneOptions.map(item => ({ value: item.code, label: item.name }))} /></Form.Item>
      <Form.Item label="渠道"><Select aria-label="发送渠道" style={{ width: 120 }} placeholder="全部渠道" allowClear value={draftFields.channel || undefined} onChange={value => setField('channel', value ?? '')} options={Object.entries(channels).map(([value, label]) => ({ value, label }))} /></Form.Item>
      <Form.Item label="结果" tooltip="按整条消息的全部渠道汇总发送结果。"><Select aria-label="发送结果" style={{ width: 120 }} placeholder="全部结果" allowClear value={draftFields.status || undefined} onChange={value => setField('status', value ?? '')} options={Object.entries(outcomeLabels).map(([value, label]) => ({ value, label }))} /></Form.Item>
      <Form.Item label="时间"><DatePicker.RangePicker style={{ width: 220 }} allowClear inputProps={[{ onKeyDown: confirmDate }, { onKeyDown: confirmDate }]} placeholder={['开始日期', '结束日期']} value={[draftFields.created_from ?? '', draftFields.created_to ?? '']} onChange={value => setFields({ created_from: value?.[0] ?? '', created_to: value?.[1] ?? '' })} /></Form.Item>
    </TableQueryForm>
    <div className="table-toolbar" style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 16 }}><Button icon={<IconRefresh />} loading={loading} onClick={() => setRefresh(value => value + 1)}>刷新</Button></div>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="table-scroll"><Table rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: 1100 }} onChange={onTableChange}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS, onChange: setPage, onPageSizeChange: setPageSize }} /></div>
    <Drawer key={selected ?? 'closed'} maskClosable title="消息日志详情" visible={selected !== null} onCancel={() => select(null)} width="min(960px, calc(100vw - 24px))" footer={null}>
      <Spin loading={detailLoading} style={{ width: '100%' }}>{detailError && <Alert type="error" content={detailError} />}{detail?.id === selected && <>
        <Descriptions column={1} border data={[{ label: '消息场景', value: detail.name }, { label: '消息类型', value: MESSAGE_PURPOSE_LABELS[detail.purpose] }, { label: '总体结果', value: outcomes[detail.status]?.text ?? detail.status }, { label: '关联业务', value: `${businessLabels[detail.business_type] ?? detail.business_type} #${detail.business_id}` }, { label: '记录时间', value: detail.created_at }]} />
        <Alert type="info" content="已受理表示厂商接受请求，不代表最终送达。结果未知时请先核实厂商记录；站内已读单独记录。" style={{ margin: '16px 0' }} />
        <Typography.Title heading={6}>渠道投递</Typography.Title>
        <Table<MessageDelivery> rowKey="id" data={detail.deliveries} scroll={{ x: 1410 }} pagination={false} columns={[
          { title: '接收对象', width: 140, render: (_, row) => row.audience === 'robot' ? '机器人群' : `${audiences[row.audience] ?? row.audience} #${row.account_id}` },
          { title: '渠道', dataIndex: 'channel', width: 100, render: value => channels[value] ?? value },
          { title: '发送通道', width: 140, ellipsis: true, render: (_, row) => row.channel_name || (row.channel === 'message' ? '站内消息' : ['dingtalk', 'wecom', 'feishu'].includes(row.channel) ? `${channels[row.channel]}机器人` : '未记录') },
          { title: '发送状态', dataIndex: 'status', width: 120, render: state },
          { title: '失败原因', dataIndex: 'error_code', width: 250, ellipsis: true, render: value => errors[value] ?? (value || undefined) },
          { title: '最后更新', dataIndex: 'updated_at', width: 180, ellipsis: true },
          { title: '次数', dataIndex: 'attempts', width: 70 },
          { title: '接收组', dataIndex: 'group_name', width: 150, ellipsis: true, render: value => value || '历史接收组' },
          { title: '已读时间', width: 170, render: (_, row) => row.channel === 'message' ? row.read_at : undefined },
          { title: '操作', width: 90, fixed: 'right', render: (_, row) => row.status === 'failed' && row.attempts < 10 && permissions.includes('admin.message-logs.retry')
            ? <TableActions actions={[{ key: 'retry', label: '重试', onClick: () => retry(row) }]} /> : null },
        ]} />
        <Collapse defaultActiveKey={[]} style={{ marginTop: 16 }}><Collapse.Item name="attempts" header={`尝试记录（${detail.attempts.length}）`}>
        <Table rowKey={row => `${row.delivery_id}-${row.attempt_no}`} data={detail.attempts} scroll={{ x: 700 }} pagination={false} columns={[
          { title: '任务', dataIndex: 'delivery_id', width: 70 }, { title: '次数', dataIndex: 'attempt_no', width: 70 },
          { title: '结果', dataIndex: 'status', width: 110, render: value => states[value]?.text ?? value },
          { title: '说明', dataIndex: 'error_code', width: 250, render: value => errors[value] ?? (value || undefined) },
          { title: '耗时', dataIndex: 'duration_ms', width: 90, render: value => `${value} ms` }, { title: '开始时间', dataIndex: 'created_at', width: 170 },
        ]} />
        </Collapse.Item></Collapse>
      </>}</Spin>
    </Drawer>
  </section>
}
