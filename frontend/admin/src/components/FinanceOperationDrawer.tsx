import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Descriptions, Drawer, Form, Input, Message, Select, Space } from '@arco-design/web-react'
import type { FinanceAction, FinanceInput, FinanceOption, FinanceRecord, FinanceResource } from '../api/financeApi'
import { financeMembers, financeOptions, writeFinanceRecord } from '../api/financeApi'
import PriceInput from './PriceInput'
import { ApiRequestError } from '../api/httpClient'
import { formatMoneyMinor } from '../utils/money'

const createLabels: Partial<Record<FinanceResource, string>> = {
  recharges: '线下充值入账', refunds: '申请退款', invoices: '申请开票', withdrawals: '申请充值退还',
}
const actionLabels: Record<FinanceAction, string> = {
  create: '新建', approve: '审核通过', reject: '审核驳回', execute: '执行余额退款',
  issue: '登记开票', red: '登记红冲', payout: '登记打款',
}
const hints: Partial<Record<FinanceResource, string>> = {
  recharges: '请核实线下款项已到账，并填写唯一收款凭证编号。保存后计入会员余额。',
  refunds: '仅受理已确认未创建资源的未交付实例，按单台全额退回原余额资金来源。结果未知或曾交付的实例不能退款；已开票部分须先红冲。',
  invoices: '仅对已支付订单尚未开票的净实付金额申请开票。退款预留金额不能开票。',
  withdrawals: '仅人工退还指定充值来源中未消费的资金。申请后冻结额度，由其他管理员审核后线下打款并登记真实凭证。驳回自动解冻，不支持会员自助提现。',
}
type Fields = Omit<FinanceInput, 'request_key'>
interface Props {
  resource: FinanceResource
  action: FinanceAction
  record: FinanceRecord | null
  visible: boolean
  onCancel: () => void
  onSaved: () => void
  initial?: { member_id: number; source_id: number }
}

/** 单一操作抽屉；详情切换操作前关闭，避免多层抽屉。 */
export default function FinanceOperationDrawer({ resource, action, record, visible, onCancel, onSaved, initial }: Props) {
  const [form] = Form.useForm<Fields>()
  const [memberId, setMemberId] = useState<number>()
  const [memberSearch, setMemberSearch] = useState('')
  const [members, setMembers] = useState<FinanceOption[]>([])
  const [membersLoading, setMembersLoading] = useState(false)
  const [options, setOptions] = useState<FinanceOption[]>([])
  const [optionsLoading, setOptionsLoading] = useState(false)
  const [sourceId, setSourceId] = useState<number>()
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const attempt = useRef<{ signature: string; key: string } | null>(null)
  const create = action === 'create'
  const title = create ? createLabels[resource] ?? '新建' : actionLabels[action]
  const maximum = options.find(option => option.id === sourceId)?.available_minor
  const needsReference = ['issue', 'red', 'payout'].includes(action) || create && resource === 'recharges'
  const referenceLabel = resource === 'recharges' ? '收款凭证编号' : action === 'issue' ? '发票号码' : action === 'red' ? '红字发票号码' : '打款凭证编号'

  useEffect(() => {
    if (!visible) return
    form.resetFields()
    form.setFieldsValue({ invoice_type: 'electronic' })
    attempt.current = null
    const timer = window.setTimeout(() => {
      setMemberId(initial?.member_id); setSourceId(initial?.source_id); setOptions([]); setMemberSearch(initial ? String(initial.member_id) : ''); setError('')
      if (initial) form.setFieldsValue({ member_id: initial.member_id, source_id: initial.source_id })
    }, 0)
    return () => window.clearTimeout(timer)
  }, [visible, action, resource, form, initial])

  useEffect(() => {
    if (!visible || !create) return
    let active = true
    const timer = window.setTimeout(() => {
      setMembersLoading(true)
      void financeMembers(resource, memberSearch).then(data => { if (active) setMembers(data) })
        .catch(reason => { if (active) { setMembers([]); setError(reason instanceof Error ? reason.message : '会员加载失败') } })
        .finally(() => { if (active) setMembersLoading(false) })
    }, 300)
    return () => { active = false; window.clearTimeout(timer) }
  }, [visible, create, resource, memberSearch])

  useEffect(() => {
    if (!visible || !create || resource === 'recharges' || !memberId) return
    let active = true
    const timer = window.setTimeout(() => {
      setOptionsLoading(true); setError('')
      void financeOptions(resource, memberId).then(data => { if (active) { setOptions(data); if (resource === 'refunds' && initial?.source_id) form.setFieldValue('amount_minor', data.find(item => item.id === initial.source_id)?.available_minor) } })
        .catch(reason => { if (active) { setOptions([]); setError(reason instanceof Error ? reason.message : '业务来源加载失败') } })
        .finally(() => { if (active) setOptionsLoading(false) })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [visible, create, resource, memberId, form, initial])

  const save = async () => {
    if (saving) return
    let fields: Fields
    try { fields = await form.validate() } catch { return }
    const signature = JSON.stringify([resource, action, record?.id, fields])
    if (attempt.current?.signature !== signature) attempt.current = { signature, key: crypto.randomUUID() }
    setSaving(true); setError('')
    try {
      await writeFinanceRecord(resource, action, record?.id ?? null, { ...fields, request_key: attempt.current.key })
      Message.success('操作已保存')
      onSaved()
    } catch (reason) {
      const data = reason instanceof ApiRequestError && reason.status === 422 ? reason.data : null
      const fields = data && typeof data === 'object' && 'errors' in data ? data.errors : null
      if (fields && typeof fields === 'object') {
        for (const [field, messages] of Object.entries(fields)) {
          if (Array.isArray(messages) && typeof messages[0] === 'string') form.setFields({ [field]: { error: { message: messages[0] } } })
        }
      } else Message.error(reason instanceof Error ? reason.message : '操作失败，请稍后重试')
    } finally { setSaving(false) }
  }

  return <Drawer title={title} visible={visible} width="min(600px, calc(100vw - 24px))" maskClosable={!saving}
    onCancel={() => { if (!saving) onCancel() }}
    footer={<Space><Button disabled={saving} onClick={onCancel}>取消</Button><Button type="primary" status={action === 'reject' || action === 'red' ? 'danger' : undefined} loading={saving} onClick={() => void save()}>确认{title}</Button></Space>}>
    {error && <Alert type="error" content={error} style={{ marginBottom: 20 }} />}
    {create && <Alert type="info" content={hints[resource]} style={{ marginBottom: 20 }} />}
    {!create && record && <Descriptions column={1} data={[
      { label: '会员 ID', value: record.member_id },
      { label: '金额', value: typeof record.amount_minor === 'number' ? formatMoneyMinor(record.amount_minor) : null },
      { label: '单号', value: record.refund_no ?? record.invoice_no ?? record.withdrawal_no },
    ]} style={{ marginBottom: 20 }} />}
    {!create && ['issue', 'red', 'payout'].includes(action) && <Alert type="info" content={`请在外部完成${action === 'payout' ? '打款' : action === 'red' ? '红冲' : '开票'}后登记真实凭证，本操作只记录处理结果。`} style={{ marginBottom: 20 }} />}
    {!create && action === 'execute' && <Alert type="warning" content="确认后将退回会员余额并更新对应实例的已退款金额。" style={{ marginBottom: 20 }} />}
    <Form form={form} layout="vertical" onSubmit={() => void save()}>
      {create && <>
        <Form.Item field="member_id" label="会员" rules={[{ required: true, message: '请选择会员' }]}>
          <Select placeholder="搜索会员昵称、手机号或 ID" showSearch filterOption={false} loading={membersLoading}
            onSearch={setMemberSearch} options={members.map(option => ({ value: option.id, label: option.label }))}
            onChange={(value: number) => { setMemberId(value); setSourceId(undefined); setOptions([]); form.setFieldsValue({ source_id: undefined, amount_minor: undefined }) }} />
        </Form.Item>
        {resource !== 'recharges' && <Form.Item field="source_id" label={resource === 'refunds' ? '退款实例' : resource === 'invoices' ? '订单' : '充值来源'}
          rules={[{ required: true, message: '请选择业务来源' }]}
          extra={memberId && !optionsLoading && options.length === 0 ? '暂无符合条件的业务来源，请检查付款、开票及处理状态。' : undefined}>
          <Select placeholder={memberId ? '请选择业务来源' : '请先选择会员'} disabled={!memberId} loading={optionsLoading}
            showSearch options={options.map(option => ({ value: option.id, label: `${option.label} · 可用 ${formatMoneyMinor(option.available_minor ?? 0)}` }))}
            onChange={(value: number) => { setSourceId(value); form.setFieldValue('amount_minor', resource === 'refunds' ? options.find(item => item.id === value)?.available_minor : undefined) }} />
        </Form.Item>}
        <Form.Item field="amount_minor" label="金额" rules={[{ required: true, message: '请输入金额' },
          { validator: (value: number, callback) => { if (value !== undefined && (value < 1 || maximum !== undefined && value > maximum)) callback('金额须大于零且不超过可用额度'); else callback() } }]}
          extra={maximum === undefined ? undefined : `当前可处理金额：${formatMoneyMinor(maximum)}`}>
          <PriceInput disabled={resource === 'refunds'} placeholder="请输入金额" maxMinor={maximum ?? 100000000} />
        </Form.Item>
        {resource === 'invoices' && <>
          <Form.Item field="title" label="发票抬头" rules={[{ required: true, message: '请输入发票抬头' }]}><Input placeholder="请输入发票抬头" maxLength={160} /></Form.Item>
          <Form.Item field="invoice_type" label="发票类型" rules={[{ required: true, message: '请选择发票类型' }]}><Select placeholder="请选择发票类型" options={[{ value: 'electronic', label: '电子发票' }, { value: 'paper', label: '纸质发票' }]} /></Form.Item>
        </>}
        {['refunds', 'withdrawals'].includes(resource) && <Form.Item field="reason" label="申请原因" rules={[{ required: true, message: '请输入申请原因' }]}><Input.TextArea placeholder="请输入申请原因" maxLength={255} showWordLimit autoSize={{ minRows: 2, maxRows: 4 }} /></Form.Item>}
      </>}
      {needsReference && <Form.Item field="external_reference" label={referenceLabel} rules={[{ required: true, message: `请输入${referenceLabel}` }]}><Input placeholder={`请输入真实${referenceLabel}`} maxLength={80} /></Form.Item>}
      {create && <Form.Item field="remark" label="备注"><Input.TextArea placeholder="请输入备注（选填）" maxLength={255} autoSize={{ minRows: 2, maxRows: 4 }} /></Form.Item>}
      {action === 'payout' && <Form.Item field="payout_note" label="打款核对说明" rules={[{ required: true, message: '请填写实际渠道、时间及核对说明' }]}><Input.TextArea placeholder="填写实际打款渠道、时间及核对依据，不要填写收款账号" maxLength={500} autoSize={{ minRows: 2, maxRows: 4 }} /></Form.Item>}
      {['approve', 'reject'].includes(action) && <Form.Item field="review_reason" label="审核意见" rules={action === 'reject' || ['refunds', 'withdrawals'].includes(resource) ? [{ required: true, message: '请输入驳回原因' }] : []}><Input.TextArea placeholder={action === 'reject' ? '请输入驳回原因' : '请输入审核意见'} maxLength={255} autoSize={{ minRows: 2, maxRows: 4 }} /></Form.Item>}
    </Form>
  </Drawer>
}
