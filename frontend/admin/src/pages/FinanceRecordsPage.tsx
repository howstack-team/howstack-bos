import { useEffect, useState } from 'react'
import { Alert, Descriptions, Drawer, Link, Table } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { getFinanceRecord, listFinanceRecords } from '../api/financeApi.ts'
import type { FinanceRecord, FinanceResource } from '../api/financeApi.ts'
import { tableCellText } from '../utils/tableCellText.ts'
import { DEFAULT_TABLE_PAGE_SIZE, TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

const definitions: Record<FinanceResource, { title: string; number: string; columns: Array<[string, string]> }> = {
  recharges: { title: '充值记录', number: 'recharge_no', columns: [['充值时间', 'created_at'], ['会员 ID', 'member_id'], ['金额', 'amount_minor'], ['渠道', 'channel'], ['状态', 'status'], ['到账时间', 'paid_at']] },
  payments: { title: '支付记录', number: 'payment_no', columns: [['支付时间', 'created_at'], ['会员 ID', 'member_id'], ['支付用途', 'purpose'], ['业务 ID', 'purpose_id'], ['渠道', 'channel'], ['金额', 'amount_minor'], ['状态', 'status']] },
  transactions: { title: '资金流水', number: 'entry_no', columns: [['发生时间', 'created_at'], ['会员 ID', 'member_id'], ['类型', 'entry_type'], ['来源类型', 'source_type'], ['来源 ID', 'source_id'], ['变动金额', 'amount_minor'], ['变动后余额', 'available_after_minor']] },
  refunds: { title: '退款记录', number: 'refund_no', columns: [['申请时间', 'created_at'], ['会员 ID', 'member_id'], ['来源类型', 'source_type'], ['来源 ID', 'source_id'], ['金额', 'amount_minor'], ['去向', 'destination'], ['状态', 'status']] },
  invoices: { title: '发票记录', number: 'invoice_no', columns: [['申请时间', 'created_at'], ['会员 ID', 'member_id'], ['订单 ID', 'order_id'], ['发票抬头', 'title'], ['金额', 'amount_minor'], ['类型', 'invoice_type'], ['状态', 'status']] },
  withdrawals: { title: '提现记录', number: 'withdrawal_no', columns: [['申请时间', 'created_at'], ['会员 ID', 'member_id'], ['来源类型', 'source_type'], ['来源 ID', 'source_id'], ['金额', 'amount_minor'], ['状态', 'status'], ['打款时间', 'payout_at']] },
}

const moneyFields = new Set(['amount_minor', 'available_after_minor'])
const longTextFields = new Set(['title', 'source_type', 'entry_type', 'channel', 'destination', 'purpose', 'invoice_type', 'status'])
const descriptionsColumns = { xs: 1, sm: 1, md: 2, lg: 2, xl: 2, xxl: 2, xxxl: 2 }

function columnWidth(field: string): number {
  if (field.endsWith('_at')) return 180
  return field === 'title' ? 220 : 130
}

function display(field: string, value: string | number | null): string {
  if (moneyFields.has(field) && typeof value === 'number') return `¥${(value / 100).toFixed(2)}`
  return value === null || value === '' ? '-' : String(value)
}

/** 财务单据的只读列表，业务操作在真实交易流程完成后再开放。 */
export default function FinanceRecordsPage({ resource }: { resource: FinanceResource }) {
  const definition = definitions[resource]
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_TABLE_PAGE_SIZE)
  const [rows, setRows] = useState<FinanceRecord[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [detail, setDetail] = useState<FinanceRecord | null>(null)
  const [detailError, setDetailError] = useState('')
  const [demoResource, setDemoResource] = useState<FinanceResource | null>(null)
  const showingDemo = import.meta.env.DEV && demoResource === resource

  useEffect(() => {
    let active = true
    const timer = window.setTimeout(() => {
      setLoading(true)
      setError('')
      if (showingDemo) {
        void import('../mock/financePreviewData.ts').then(({ previewFinanceRecords }) => {
          if (!active) return
          const result = previewFinanceRecords(resource, page, pageSize)
          setRows(result.data)
          setTotal(result.total)
        }).catch(() => { if (active) setError('演示数据加载失败') })
          .finally(() => { if (active) setLoading(false) })
        return
      }
      void listFinanceRecords(resource, { page, page_size: pageSize })
        .then(async (result) => {
          if (!active) return
          if (import.meta.env.DEV && page === 1 && result.total === 0) {
            const { previewFinanceRecords } = await import('../mock/financePreviewData.ts')
            if (!active) return
            const preview = previewFinanceRecords(resource, page, pageSize)
            setDemoResource(resource)
            setRows(preview.data)
            setTotal(preview.total)
          } else {
            setRows(result.data)
            setTotal(result.total)
          }
        })
        .catch((reason: unknown) => { if (active) { setRows([]); setTotal(0); setError(reason instanceof Error ? reason.message : '加载失败') } })
        .finally(() => { if (active) setLoading(false) })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [resource, page, pageSize, showingDemo])

  useEffect(() => {
    if (selectedId === null) return
    let active = true
    const timer = window.setTimeout(() => {
      setDetail(null)
      setDetailError('')
      if (showingDemo && selectedId < 0) {
        void import('../mock/financePreviewData.ts').then(({ previewFinanceRecord }) => {
          if (active) setDetail(previewFinanceRecord(resource, selectedId))
        }).catch(() => { if (active) setDetailError('演示数据加载失败') })
        return
      }
      void getFinanceRecord(resource, selectedId)
        .then((result) => { if (active) setDetail(result) })
        .catch((reason: unknown) => { if (active) setDetailError(reason instanceof Error ? reason.message : '详情加载失败') })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [resource, selectedId, showingDemo])

  const columns: TableColumnProps<FinanceRecord>[] = [
    { title: '序号', width: 76, render: (_, __, index) => (page - 1) * pageSize + index + 1 },
    { title: '单号', width: 220, ellipsis: true, render: (_, row) => <Link title={String(row[definition.number] ?? '')} onClick={() => setSelectedId(row.id)}>{tableCellText(row[definition.number])}</Link> },
    ...definition.columns.map(([title, field]): TableColumnProps<FinanceRecord> => ({
      title, width: columnWidth(field), ellipsis: longTextFields.has(field),
      render: (_, row) => display(field, row[field]),
    })),
  ]

  const detailRows = detail ? [
    { key: 'number', label: '单号', value: detail[definition.number] },
    ...definition.columns.map(([label, field]) => ({ key: field, label, value: display(field, detail[field]) })),
  ] : []

  return <section className="page-card">
    {showingDemo && <Alert type="warning" content="演示数据 · 仅开发环境展示，未写入数据库，不代表真实交易" style={{ marginBottom: 16 }} />}
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="table-scroll"><Table<FinanceRecord> rowKey="id" columns={columns} data={rows} loading={loading}
      scroll={{ x: Math.max(900, 76 + 220 + definition.columns.reduce((sum, [, field]) => sum + columnWidth(field), 0)) }}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: (size) => { setPage(1); setPageSize(size) } }} /></div>
    <Drawer title={`${definition.title}详情`} visible={selectedId !== null} width="min(760px, 100vw)" footer={null}
      onCancel={() => setSelectedId(null)}>
      {detailError && <Alert type="error" content={detailError} />}
      {detail && <Descriptions title="记录信息" data={detailRows} column={descriptionsColumns} border />}
    </Drawer>
  </section>
}
