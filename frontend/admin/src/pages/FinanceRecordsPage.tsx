import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { Alert, Button, DatePicker, Descriptions, Drawer, Form, Link, Select, Space, Table } from '@arco-design/web-react'
import { IconPlus, IconRefresh } from '@arco-design/web-react/icon'
import type { TableColumnProps } from '@arco-design/web-react'
import { StatusBadge } from '../components/TableEnum'
import TableQueryForm from '../components/TableQueryForm'
import TableActions from '../components/TableActions'
import type { TableAction } from '../components/TableActions'
import FinanceOperationDrawer from '../components/FinanceOperationDrawer'
import { useAdminAuth } from '../auth/AuthContext'
import { enumText } from '../utils/tableEnum'
import { FINANCE_CATEGORIES, FINANCE_STATUSES } from '../utils/businessEnums'
import useTableQuery from '../hooks/useTableQuery'
import { getFinanceRecord, listFinanceRecords } from '../api/financeApi'
import type { FinanceAction, FinanceRecord, FinanceResource } from '../api/financeApi'
import { tableCellText } from '../utils/tableCellText'
import { TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination'
import { formatMoneyMinor } from '../utils/money'

const definitions: Record<FinanceResource, { title: string; number: string; columns: Array<[string, string]> }> = {
  recharges: { title: '充值记录', number: 'recharge_no', columns: [['充值时间', 'created_at'], ['会员 ID', 'member_id'], ['金额', 'amount_minor'], ['渠道', 'channel'], ['状态', 'status'], ['到账时间', 'paid_at']] },
  payments: { title: '支付记录', number: 'payment_no', columns: [['支付时间', 'created_at'], ['会员 ID', 'member_id'], ['支付用途', 'purpose'], ['业务 ID', 'purpose_id'], ['渠道', 'channel'], ['金额', 'amount_minor'], ['状态', 'status']] },
  transactions: { title: '资金流水', number: 'entry_no', columns: [['发生时间', 'created_at'], ['会员 ID', 'member_id'], ['类型', 'entry_type'], ['来源类型', 'source_type'], ['来源 ID', 'source_id'], ['变动金额', 'amount_minor'], ['变动后余额', 'available_after_minor']] },
  refunds: { title: '退款记录', number: 'refund_no', columns: [['申请时间', 'created_at'], ['会员 ID', 'member_id'], ['来源类型', 'source_type'], ['来源 ID', 'source_id'], ['金额', 'amount_minor'], ['去向', 'destination'], ['状态', 'status']] },
  invoices: { title: '发票记录', number: 'invoice_no', columns: [['申请时间', 'created_at'], ['会员 ID', 'member_id'], ['订单 ID', 'order_id'], ['发票抬头', 'title'], ['金额', 'amount_minor'], ['类型', 'invoice_type'], ['状态', 'status']] },
  withdrawals: { title: '提现记录', number: 'withdrawal_no', columns: [['申请时间', 'created_at'], ['会员 ID', 'member_id'], ['来源类型', 'source_type'], ['来源 ID', 'source_id'], ['金额', 'amount_minor'], ['状态', 'status'], ['打款时间', 'payout_at']] },
}

const createLabels: Partial<Record<FinanceResource, string>> = {
  recharges: '线下充值入账', refunds: '申请退款', invoices: '申请开票', withdrawals: '申请提现',
}
const detailFields: Array<[string, string]> = [
  ['申请原因', 'reason'], ['备注', 'remark'], ['创建人 ID', 'operator_id'],
  ['审核人 ID', 'reviewer_id'], ['审核意见', 'review_reason'], ['审核时间', 'reviewed_at'],
  ['收款凭证', 'external_reference'], ['渠道交易号', 'channel_trade_no'], ['关联支付 ID', 'payment_id'],
  ['退款完成时间', 'completed_at'], ['发票号码', 'external_number'], ['开票时间', 'issued_at'],
  ['红字发票号码', 'red_number'], ['红冲登记时间', 'red_at'], ['打款凭证', 'payout_reference'],
]
const moneyFields = new Set(['amount_minor', 'available_after_minor'])
const longTextFields = new Set(['title', 'source_type', 'entry_type', 'channel', 'destination', 'purpose', 'invoice_type', 'status'])
const descriptionsColumns = { xs: 1, sm: 1, md: 2, lg: 2, xl: 2, xxl: 2, xxxl: 2 }
function columnWidth(field: string): number {
  if (field.endsWith('_at')) return 180
  return field === 'title' ? 220 : 130
}
function display(resource: FinanceResource, field: string, value: string | number | null, currency = 'CNY'): ReactNode {
  if (field === 'status') return <StatusBadge value={value} mapping={FINANCE_STATUSES[resource] ?? {}} />
  if (Object.hasOwn(FINANCE_CATEGORIES, field)) return enumText(FINANCE_CATEGORIES[field], value)
  if (moneyFields.has(field) && typeof value === 'number') return formatMoneyMinor(value, currency)
  return tableCellText(value)
}

/** 社区版财务列表：显式查询、只读账本与受权单据处置。 */
export default function FinanceRecordsPage({ resource }: { resource: FinanceResource }) {
  const definition = definitions[resource]
  const statuses = FINANCE_STATUSES[resource] ?? {}
  const statusLabels = Object.fromEntries(Object.entries(statuses).map(([key, item]) => [key, item.text]))
  const { permissions } = useAdminAuth()
  const can = (action: string) => permissions.includes(`admin.finance.${resource}.${action}`)
  const { queryParams, draftFields, setField, setFields, draftStatus, setDraftStatus, search, reset, discard,
    refreshKey, sortColumn, filterColumn, onTableChange, page, pageSize, setPage, setPageSize } = useTableQuery({
      textFields: ['number', 'member_id', 'created_from', 'created_to'], statusValues: Object.keys(statuses),
      sortFields: definition.columns.map(([, key]) => key).filter(key => key.endsWith('_at') || moneyFields.has(key)),
    })
  const [rows, setRows] = useState<FinanceRecord[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [revision, setRevision] = useState(0)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [detail, setDetail] = useState<FinanceRecord | null>(null)
  const [detailError, setDetailError] = useState('')
  const [operation, setOperation] = useState<{ action: FinanceAction; record: FinanceRecord | null } | null>(null)

  useEffect(() => {
    let active = true
    const timer = window.setTimeout(() => {
      setLoading(true); setError('')
      void listFinanceRecords(resource, { ...queryParams, page, page_size: pageSize })
        .then(result => { if (active) { setRows(result.data); setTotal(result.total) } })
        .catch((reason: unknown) => { if (active) { setRows([]); setTotal(0); setError(reason instanceof Error ? reason.message : '加载失败') } })
        .finally(() => { if (active) setLoading(false) })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [resource, queryParams, page, pageSize, refreshKey, revision])

  useEffect(() => {
    if (selectedId === null) return
    let active = true
    const timer = window.setTimeout(() => {
      setDetail(null); setDetailError('')
      void getFinanceRecord(resource, selectedId)
        .then(result => { if (active) setDetail(result) })
        .catch((reason: unknown) => { if (active) setDetailError(reason instanceof Error ? reason.message : '详情加载失败') })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [resource, selectedId])

  const actionsFor = (row: FinanceRecord): TableAction[] => {
    const actions: TableAction[] = [{ key: 'detail', label: '详情', onClick: () => setSelectedId(row.id) }]
    const add = (action: FinanceAction, label: string, danger = false) => {
      actions.push({ key: action, label, danger, onClick: () => { setSelectedId(null); setOperation({ action, record: row }) } })
    }
    if (!row.operator_id) return actions
    if (row.status === 'pending' && can('review')) { add('approve', '通过'); add('reject', '驳回', true) }
    if (row.status === 'approved') {
      if (resource === 'refunds' && row.source_type === 'order_unit' && row.destination === 'wallet' && can('execute')) add('execute', '执行退款')
      if (resource === 'invoices' && can('issue')) add('issue', '登记开票')
      if (resource === 'withdrawals' && can('payout')) add('payout', '登记打款')
    }
    if (resource === 'invoices' && row.status === 'issued' && can('red')) add('red', '登记红冲', true)
    return actions
  }
  const columns: TableColumnProps<FinanceRecord>[] = [
    { title: '序号', width: 76, render: (_, __, index) => (page - 1) * pageSize + index + 1 },
    { title: '单号', width: 220, ellipsis: true, render: (_, row) => <Link title={String(row[definition.number] ?? '')} onClick={() => setSelectedId(row.id)}>{tableCellText(row[definition.number])}</Link> },
    ...definition.columns.map(([title, field]): TableColumnProps<FinanceRecord> => ({
      ...(field.endsWith('_at') || moneyFields.has(field) ? sortColumn(field) : {}),
      ...(field === 'status' ? filterColumn(field, statusLabels) : {}),
      title, width: columnWidth(field), ellipsis: longTextFields.has(field),
      render: (_, row) => display(resource, field, row[field], typeof row.currency === 'string' ? row.currency : 'CNY'),
    })),
    { title: '操作', width: 190, fixed: 'right', render: (_, row) => <TableActions actions={actionsFor(row)} /> },
  ]
  const knownDetailFields = new Set(definition.columns.map(([, key]) => key))
  const detailRows = detail ? [
    { label: '单号', value: detail[definition.number] },
    ...definition.columns.map(([label, field]) => ({ label, value: display(resource, field, detail[field], typeof detail.currency === 'string' ? detail.currency : 'CNY') })),
    ...detailFields.filter(([, field]) => !knownDetailFields.has(field) && Object.hasOwn(detail, field))
      .map(([label, field]) => ({ label, value: tableCellText(detail[field]) })),
  ] : []

  return <section className="page-card">
    <TableQueryForm fields={[{ key: 'number', label: '单号', maxLength: 48 }, { key: 'member_id', label: '会员 ID', maxLength: 10 }]}
      values={draftFields} onFieldChange={setField} onSearch={search} onReset={reset} onCancelFilters={discard} loading={loading}>
      {Object.keys(statuses).length > 0 && <Form.Item label="状态" style={{ marginBottom: 0, marginRight: 0 }}><Select aria-label="状态" placeholder="全部状态" allowClear style={{ width: 150 }}
        value={draftStatus} onChange={setDraftStatus} options={Object.entries(statusLabels).map(([value, label]) => ({ value, label }))} /></Form.Item>}
      <Form.Item label="时间" style={{ marginBottom: 0, marginRight: 0 }}><DatePicker.RangePicker aria-label="记录时间" allowClear
        value={draftFields.created_from && draftFields.created_to ? [draftFields.created_from, draftFields.created_to] : undefined}
        onChange={values => setFields({ created_from: values?.[0] ?? '', created_to: values?.[1] ?? '' })} /></Form.Item>
    </TableQueryForm>
    <div className="table-toolbar" style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 16 }}><Space>
      <Button icon={<IconRefresh />} loading={loading} onClick={() => setRevision(value => value + 1)}>刷新</Button>
      {createLabels[resource] && can('create') && <Button type="primary" icon={<IconPlus />} onClick={() => setOperation({ action: 'create', record: null })}>{createLabels[resource]}</Button>}
    </Space></div>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="table-scroll"><Table<FinanceRecord> onChange={onTableChange} rowKey="id" columns={columns} data={rows} loading={loading}
      scroll={{ x: Math.max(900, 266 + 220 + definition.columns.reduce((sum, [, field]) => sum + columnWidth(field), 0)) }}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: setPageSize }} /></div>
    <Drawer maskClosable title={`${definition.title}详情`} visible={selectedId !== null} width="min(760px, calc(100vw - 24px))" footer={null} onCancel={() => setSelectedId(null)}>
      {detailError && <Alert type="error" content={detailError} />}
      {detail && <Descriptions data={detailRows} column={descriptionsColumns} border />}
    </Drawer>
    {operation && <FinanceOperationDrawer resource={resource} action={operation.action} record={operation.record} visible
      onCancel={() => setOperation(null)} onSaved={() => { setOperation(null); setRevision(value => value + 1) }} />}
  </section>
}
