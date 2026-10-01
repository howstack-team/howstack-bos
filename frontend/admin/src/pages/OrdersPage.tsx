import { useEffect, useState } from 'react'
import { Alert, Descriptions, Drawer, Input, Link, Select, Table, Tag } from '@arco-design/web-react'
import { IconSearch } from '@arco-design/web-react/icon'
import type { TableColumnProps } from '@arco-design/web-react'
import { getOrder, listOrders } from '../api/orderApi.ts'
import type { OrderDetail, OrderItem, OrderSummary } from '../api/orderApi.ts'
import TableActions from '../components/TableActions.tsx'
import { tableCellText } from '../utils/tableCellText.ts'
import { DEFAULT_TABLE_PAGE_SIZE, TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

const DESCRIPTION_COLUMNS = { xs: 1, sm: 1, md: 2, lg: 2, xl: 2, xxl: 2, xxxl: 2 }
const statuses: Record<string, { text: string; color: string }> = {
  pending_payment: { text: '待支付', color: 'orange' }, paid: { text: '已支付', color: 'blue' },
  fulfilling: { text: '交付中', color: 'blue' }, completed: { text: '已完成', color: 'green' },
  cancelled: { text: '已取消', color: 'gray' }, failed: { text: '失败', color: 'red' },
  refunding: { text: '退款中', color: 'orange' }, refunded: { text: '已退款', color: 'gray' },
}
const paymentStatuses: Record<string, string> = {
  unpaid: '未支付', partial: '部分支付', paid: '已支付', refunded: '已退款', partial_refund: '部分退款',
}
const orderTypes: Record<string, string> = { purchase: '购买', renewal: '续费', upgrade: '升级', trial: '试用' }

function money(minor: number, currency: string): string {
  try { return new Intl.NumberFormat('zh-CN', { style: 'currency', currency }).format(minor / 100) }
  catch { return `${currency} ${(minor / 100).toFixed(2)}` }
}

/** 财务管理只读订单列表；订单项在详情抽屉展示。 */
export default function OrdersPage() {
  const [input, setInput] = useState('')
  const [keyword, setKeyword] = useState('')
  const [status, setStatus] = useState<string | undefined>()
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_TABLE_PAGE_SIZE)
  const [rows, setRows] = useState<OrderSummary[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [detail, setDetail] = useState<OrderDetail | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState('')
  const [showingDemo, setShowingDemo] = useState(false)

  useEffect(() => {
    const timer = window.setTimeout(() => { setPage(1); setKeyword(input.trim()) }, 300)
    return () => window.clearTimeout(timer)
  }, [input])

  useEffect(() => {
    let active = true
    const timer = window.setTimeout(() => {
      setLoading(true); setError('')
      if (import.meta.env.DEV && showingDemo) {
        void import('../mock/financePreviewData.ts').then(({ previewOrders }) => {
          if (!active) return
          const preview = previewOrders(keyword, status, page, pageSize)
          setRows(preview.data); setTotal(preview.total)
        }).catch(() => { if (active) setError('演示数据加载失败') })
          .finally(() => { if (active) setLoading(false) })
        return
      }
      void listOrders({ keyword, status, page, page_size: pageSize })
        .then(async (result) => {
          if (!active) return
          if (import.meta.env.DEV && !keyword && !status && page === 1 && result.total === 0) {
            const { previewOrders } = await import('../mock/financePreviewData.ts')
            if (!active) return
            const preview = previewOrders(keyword, status, page, pageSize)
            setShowingDemo(true); setRows(preview.data); setTotal(preview.total)
          } else {
            setRows(result.data); setTotal(result.total)
          }
        })
        .catch((reason: unknown) => { if (active) { setRows([]); setTotal(0); setError(reason instanceof Error ? reason.message : '订单加载失败') } })
        .finally(() => { if (active) setLoading(false) })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [keyword, status, page, pageSize, showingDemo])

  useEffect(() => {
    if (selectedId === null) return
    let active = true
    const timer = window.setTimeout(() => {
      setDetailLoading(true); setDetail(null); setDetailError('')
      if (import.meta.env.DEV && showingDemo && selectedId < 0) {
        void import('../mock/financePreviewData.ts').then(({ previewOrder }) => {
          if (active) setDetail(previewOrder(selectedId))
        }).catch(() => { if (active) setDetailError('演示数据加载失败') })
          .finally(() => { if (active) setDetailLoading(false) })
        return
      }
      void getOrder(selectedId)
        .then((value) => { if (active) setDetail(value) })
        .catch((reason: unknown) => { if (active) setDetailError(reason instanceof Error ? reason.message : '订单详情加载失败') })
        .finally(() => { if (active) setDetailLoading(false) })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [selectedId, showingDemo])

  const columns: TableColumnProps<OrderSummary>[] = [
    { title: '序号', width: 76, render: (_, __, index) => (page - 1) * pageSize + index + 1 },
    { title: '订单号', dataIndex: 'order_no', width: 210, ellipsis: true, render: (_, row) => <Link href="#" title={row.order_no} onClick={(event) => { event.preventDefault(); setSelectedId(row.id) }}>{row.order_no}</Link> },
    { title: '下单时间', dataIndex: 'created_at', width: 180, render: tableCellText },
    { title: '会员', width: 180, ellipsis: true, render: (_, row) => tableCellText(`${row.member_nickname_snapshot} (${row.member_mobile_snapshot})`) },
    { title: '订单类型', width: 100, ellipsis: true, render: (_, row) => orderTypes[row.order_type] ?? row.order_type },
    { title: '订单状态', width: 110, render: (_, row) => <Tag color={statuses[row.status]?.color ?? 'gray'}>{statuses[row.status]?.text ?? row.status}</Tag> },
    { title: '支付状态', width: 100, render: (_, row) => paymentStatuses[row.payment_status] ?? row.payment_status },
    { title: '应付金额', width: 130, render: (_, row) => money(row.payable_minor, row.currency) },
    { title: '实付金额', width: 130, render: (_, row) => money(row.paid_minor, row.currency) },
    { title: '操作', width: 90, fixed: 'right', render: (_, row) => <TableActions actions={[{ key: 'detail', label: '详情', onClick: () => setSelectedId(row.id) }]} /> },
  ]
  const itemColumns: TableColumnProps<OrderItem>[] = [
    { title: '序号', dataIndex: 'line_no', width: 70 },
    { title: '产品名称', dataIndex: 'product_name', width: 180, ellipsis: true, render: tableCellText },
    { title: '产品类型', dataIndex: 'product_type', width: 140, ellipsis: true, render: tableCellText },
    { title: '数量', dataIndex: 'quantity', width: 80 },
    { title: '单价', width: 120, render: (_, row) => money(row.unit_price_minor, detail?.currency ?? 'CNY') },
    { title: '小计', width: 120, render: (_, row) => money(row.total_minor, detail?.currency ?? 'CNY') },
  ]
  const descriptionRows = detail ? [
    ['订单号', detail.order_no], ['订单类型', orderTypes[detail.order_type] ?? detail.order_type],
    ['会员手机号', detail.member_mobile_snapshot], ['会员昵称', detail.member_nickname_snapshot],
    ['订单状态', statuses[detail.status]?.text ?? detail.status], ['支付状态', paymentStatuses[detail.payment_status] ?? detail.payment_status],
    ['下单时间', detail.created_at], ['支付时间', detail.paid_at],
    ['完成时间', detail.completed_at], ['取消时间', detail.cancelled_at],
    ['商品金额', money(detail.subtotal_minor, detail.currency)], ['优惠金额', money(detail.discount_minor, detail.currency)],
    ['应付金额', money(detail.payable_minor, detail.currency)], ['实付金额', money(detail.paid_minor, detail.currency)],
    ['已退金额', money(detail.refunded_minor, detail.currency)], ['备注', detail.remark],
  ].map(([label, value], index) => ({ key: String(index), label, value: tableCellText(value) })) : []

  return <section className="page-card">
    <div className="page-toolbar">
      <Input prefix={<IconSearch />} placeholder="搜索订单号、会员手机号或昵称" value={input} onChange={setInput} allowClear style={{ width: 270, maxWidth: '100%' }} />
      <Select placeholder="全部状态" allowClear value={status} onChange={(value) => { setPage(1); setStatus(value) }}
        options={Object.entries(statuses).map(([value, item]) => ({ value, label: item.text }))} style={{ width: 140 }} />
    </div>
    {showingDemo && <Alert type="warning" content="演示数据 · 仅开发环境展示，未写入数据库，不代表真实交易" style={{ marginBottom: 16 }} />}
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="table-scroll"><Table<OrderSummary> rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: 1380 }}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: (size) => { setPage(1); setPageSize(size) } }} /></div>
    <Drawer title="订单详情" visible={selectedId !== null} width="min(760px, 100vw)" onCancel={() => setSelectedId(null)} footer={null}>
      {detailError && <Alert type="error" content={detailError} style={{ marginBottom: 16 }} />}
      {detailLoading && <div style={{ padding: 16 }}>正在加载订单详情…</div>}
      {detail && <>
        <Descriptions title="订单信息" data={descriptionRows} column={DESCRIPTION_COLUMNS} border />
        <div style={{ marginTop: 24 }}><h3>订单项</h3><Table<OrderItem> rowKey="id" columns={itemColumns} data={detail.items} pagination={false} scroll={{ x: 710 }} /></div>
      </>}
    </Drawer>
  </section>
}
