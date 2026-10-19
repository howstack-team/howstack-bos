import { communityRelease } from '../../../packages/community-release'
import { useLocation } from 'react-router-dom'
import { StatusBadge } from '../components/TableEnum'
import { ORDER_STATUSES as statuses, ORDER_TYPES, PAYMENT_STATUSES, PRODUCT_TYPES } from '../utils/businessEnums'
import { enumLabels, enumText } from '../utils/tableEnum'
import useTableQuery from '../hooks/useTableQuery'
import TableQueryForm from '../components/TableQueryForm'
import { useEffect, useState } from 'react'
import { Form, Alert, Button, Descriptions, Drawer, Input, Link, Message, Select, Space, Table } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { getOrder, listOrders, orderManagementApi } from '../api/orderApi.ts'
import type { OrderDetail, OrderItem, OrderSummary } from '../api/orderApi.ts'
import { IconPlus, IconRefresh } from '@arco-design/web-react/icon'
import { useAdminAuth } from '../auth/AuthContext'
import OrderCreateDrawer from '../components/OrderCreateDrawer'
import FormDialog from '../components/FormDialog'
import FinanceOperationDrawer from '../components/FinanceOperationDrawer'
import type { TableAction } from '../components/TableActions'
import TableActions from '../components/TableActions.tsx'
import { tableCellText } from '../utils/tableCellText.ts'
import { TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'
import { formatMoneyMinor as money } from '../utils/money'

const DESCRIPTION_COLUMNS = { xs: 1, sm: 1, md: 2, lg: 2, xl: 2, xxl: 2, xxxl: 2 }
const paymentStatuses = enumLabels(PAYMENT_STATUSES)
const orderTypes = enumLabels(ORDER_TYPES)

/** 订单管理：真实订单查询、创建、余额付款、取消与财务办理入口。 */
export default function OrdersPage() {
  const { permissions } = useAdminAuth()
  const can = (action: string) => permissions.includes(`admin.page.order-list.${action}`)
  const [creating, setCreating] = useState(false)
  const [operation, setOperation] = useState<{ action: 'pay' | 'cancel' | 'update'; row: OrderSummary } | null>(null)
  const [finance, setFinance] = useState<{ resource: 'refunds' | 'invoices'; initial: { member_id: number; source_id: number } } | null>(null)
  const [remark, setRemark] = useState('')
  const [saving, setSaving] = useState(false)
  const [operationError, setOperationError] = useState('')
  const [reload, setReload] = useState(0)
  const searchFields = [
    { key: 'order_no', label: '订单号', maxLength: 64 },
    { key: 'member_mobile', label: '会员手机号', maxLength: 20 },
    { key: 'member_nickname', label: '会员昵称', maxLength: 64 },
  ]
  const { queryParams, sortColumn, filterColumn, onTableChange, draftFields, setField, discard, page, pageSize, setPage, setPageSize, search, reset, refreshKey, status, draftStatus, setDraftStatus } = useTableQuery({ sortFields: ['created_at', 'payable_minor', 'paid_minor'], enumFields: { order_type: Object.keys(orderTypes), payment_status: Object.keys(paymentStatuses) }, textFields: searchFields.map(field => field.key), statusValues: Object.keys(statuses) })
  const [rows, setRows] = useState<OrderSummary[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const location = useLocation()
  const [selectedId, setSelectedId] = useState<number | null>(() => { const value = new URLSearchParams(location.search).get('detail'); return value && /^[1-9][0-9]*$/.test(value) && Number.isSafeInteger(Number(value)) ? Number(value) : null })
  const [detail, setDetail] = useState<OrderDetail | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState('')

  useEffect(() => {
    let active = true
    const timer = window.setTimeout(() => {
      setLoading(true); setError('')
      void listOrders({ ...queryParams, status, page, page_size: pageSize })
        .then((result) => { if (active) { setRows(result.data); setTotal(result.total) } })
        .catch((reason: unknown) => { if (active) { setRows([]); setTotal(0); setError(reason instanceof Error ? reason.message : '订单加载失败') } })
        .finally(() => { if (active) setLoading(false) })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [queryParams, status, page, pageSize, refreshKey, reload])

  useEffect(() => {
    if (selectedId === null) return
    let active = true
    const timer = window.setTimeout(() => {
      setDetailLoading(true); setDetail(null); setDetailError('')
      void getOrder(selectedId)
        .then((value) => { if (active) setDetail(value) })
        .catch((reason: unknown) => { if (active) setDetailError(reason instanceof Error ? reason.message : '订单详情加载失败') })
        .finally(() => { if (active) setDetailLoading(false) })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [selectedId, reload])

  const begin = (action: 'pay' | 'cancel' | 'update', row: OrderSummary) => {
    setOperationError(''); setRemark(row.remark); setOperation({ action, row })
  }
  const saveOperation = async () => {
    if (!operation || saving) return
    setSaving(true); setOperationError('')
    try {
      await orderManagementApi.act(operation.row.id, operation.action, operation.action === 'update' ? { remark, expected_remark: operation.row.remark } : {})
      Message.success(operation.action === 'pay' ? '余额付款成功，实例尚未开通' : '操作已保存')
      setOperation(null); setReload(value => value + 1)
    } catch (reason) { setOperationError(reason instanceof Error ? reason.message : '操作失败') }
    finally { setSaving(false) }
  }
  const actions = (row: OrderSummary): TableAction[] => {
    const result: TableAction[] = [{ key: 'detail', label: '详情', onClick: () => setSelectedId(row.id) }]
    if (row.status === 'pending_payment' && row.payment_status === 'unpaid' && row.paid_minor === 0) {
      if (can('pay')) result.push({ key: 'pay', label: '余额付款', onClick: () => begin('pay', row) })
      if (can('cancel')) result.push({ key: 'cancel', label: '取消', danger: true, onClick: () => begin('cancel', row) })
    }
    if (can('update')) result.push({ key: 'update', label: '备注', onClick: () => begin('update', row) })
    return result
  }
  const columns: TableColumnProps<OrderSummary>[] = [
    { title: '序号', width: 76, render: (_, __, index) => (page - 1) * pageSize + index + 1 },
    { title: '订单号', dataIndex: 'order_no', width: 210, ellipsis: true, render: (_, row) => <Link href="#" title={row.order_no} onClick={(event) => { event.preventDefault(); setSelectedId(row.id) }}>{row.order_no}</Link> },
    { ...sortColumn('created_at'), title: '下单时间', dataIndex: 'created_at', width: 180, render: tableCellText },
    { title: '会员', width: 180, ellipsis: true, render: (_, row) => tableCellText(`${row.member_nickname_snapshot} (${row.member_mobile_snapshot})`) },
    { ...filterColumn('order_type', orderTypes), title: '订单类型', width: 100, ellipsis: true, render: (_, row) => enumText(ORDER_TYPES, row.order_type) },
    { ...filterColumn('status', enumLabels(statuses)), title: '订单状态', width: 110, render: (_, row) => <StatusBadge value={row.status} mapping={statuses} /> },
    { ...filterColumn('payment_status', paymentStatuses), title: '支付状态', width: 100, render: (_, row) => enumText(PAYMENT_STATUSES, row.payment_status) },
    { ...sortColumn('payable_minor'), title: '应付金额', width: 130, render: (_, row) => money(row.payable_minor, row.currency) },
    { ...sortColumn('paid_minor'), title: '实付金额', width: 130, render: (_, row) => money(row.paid_minor, row.currency) },
    { title: '操作', width: 240, fixed: 'right', render: (_, row) => <TableActions actions={actions(row)} /> },
  ]
  const itemColumns: TableColumnProps<OrderItem>[] = [
    { title: '序号', dataIndex: 'line_no', width: 70 },
    { title: '产品名称', dataIndex: 'product_name', width: 180, ellipsis: true, render: tableCellText },
    { title: '产品类型', dataIndex: 'product_type', width: 140, ellipsis: true, render: (value) => enumText(PRODUCT_TYPES, value) },
    { title: '数量', dataIndex: 'quantity', width: 80 },
    { title: '单价', width: 120, render: (_, row) => money(row.unit_price_minor, detail?.currency ?? 'CNY') },
    { title: '小计', width: 120, render: (_, row) => money(row.total_minor, detail?.currency ?? 'CNY') },
  ]
  const unitColumns: TableColumnProps<NonNullable<OrderDetail['units']>[number]>[] = [
    { title: '实例 ID', dataIndex: 'id', width: 80 },
    { title: '产品', width: 160, render: (_, unit) => tableCellText(detail?.items.find(item => item.id === unit.order_item_id)?.product_name) },
    { title: '服务 ID', dataIndex: 'service_id', width: 90, render: tableCellText },
    { title: '实付分摊', width: 130, render: (_, unit) => money(unit.allocated_minor, detail?.currency ?? 'CNY') },
    { title: '退款预留', width: 130, render: (_, unit) => money(unit.reserved_refund_minor, detail?.currency ?? 'CNY') },
    { title: '已退款', width: 130, render: (_, unit) => money(unit.refunded_minor, detail?.currency ?? 'CNY') },
    { title: '操作', width: 100, fixed: 'right', render: (_, unit) => permissions.includes('admin.finance.refunds.create') && permissions.includes('admin.finance.refunds.view') && detail && ['paid', 'partial_refund'].includes(detail.payment_status) && unit.allocated_minor > unit.refunded_minor + unit.reserved_refund_minor ? <TableActions actions={[{ key: 'refund', label: '申请退款', onClick: () => { setSelectedId(null); setFinance({ resource: 'refunds', initial: { member_id: detail.member_id, source_id: unit.id } }) } }]} /> : null },
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
    <TableQueryForm fields={searchFields} values={draftFields} onFieldChange={setField}
      appliedCount={Object.keys(queryParams).filter(key => !['sort_by', 'sort_order'].includes(key)).length} onCancelFilters={discard}
      onSearch={search} onReset={reset} loading={loading}>

      <Form.Item label="状态" className="table-query-field" style={{ marginBottom: 0, marginRight: 0, minWidth: 0, maxWidth: '100%' }}><Select placeholder="全部状态" allowClear value={draftStatus} onChange={setDraftStatus}
        options={Object.entries(statuses).map(([value, item]) => ({ value, label: item.text }))} style={{ width: 140 }} /></Form.Item>
      </TableQueryForm>
    <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 16 }}><Space>
      <Button icon={<IconRefresh />} onClick={() => setReload(value => value + 1)} disabled={loading}>刷新</Button>
      {can('create') && <Button type="primary" icon={<IconPlus />} onClick={() => setCreating(true)}>创建订单</Button>}
    </Space></div>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="table-scroll"><Table<OrderSummary> onChange={onTableChange} rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: 1380 }}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: setPageSize }} /></div>
    <Drawer maskClosable title="订单详情" visible={selectedId !== null} width="min(760px, calc(100vw - 24px))" onCancel={() => setSelectedId(null)} footer={null}>
      {detailError && <Alert type="error" content={detailError} style={{ marginBottom: 16 }} />}
      {detailLoading && <div style={{ padding: 16 }}>正在加载订单详情…</div>}
      {detail && <>
        {communityRelease.newInvoices && ['paid', 'partial_refund'].includes(detail.payment_status) && detail.paid_minor > detail.refunded_minor && permissions.includes('admin.finance.invoices.create') && permissions.includes('admin.finance.invoices.view') && <div style={{ marginBottom: 16 }}><Button icon={<IconPlus />} onClick={() => { setSelectedId(null); setFinance({ resource: 'invoices', initial: { member_id: detail.member_id, source_id: detail.id } }) }}>申请开票</Button></div>}
        <Descriptions title="订单信息" data={descriptionRows} column={DESCRIPTION_COLUMNS} border />
        <div style={{ marginTop: 24 }}><h3>订单项</h3><Table<OrderItem> rowKey="id" columns={itemColumns} data={detail.items} pagination={false} scroll={{ x: 710 }} /></div>
        {detail.units && <div style={{ marginTop: 24 }}><h3>实例金额明细</h3><Table rowKey="id" columns={unitColumns} data={detail.units} pagination={false} scroll={{ x: 820 }} /></div>}
      </>}
    </Drawer>
    <OrderCreateDrawer visible={creating} onCancel={() => setCreating(false)} onSaved={id => { setCreating(false); setReload(value => value + 1); setSelectedId(id) }} />
    <FormDialog title={operation?.action === 'pay' ? '确认余额付款' : operation?.action === 'cancel' ? '取消订单' : '编辑订单备注'} visible={operation !== null} mode="modal" width={480}
      onCancel={() => { if (!saving) setOperation(null) }} onOk={() => void saveOperation()} confirmLoading={saving}>
      {operationError && <Alert type="error" content={operationError} style={{ marginBottom: 16 }} />}
      {operation?.action === 'update' ? <Input.TextArea placeholder="请输入订单备注（选填）" value={remark} onChange={setRemark} maxLength={255} showWordLimit autoSize={{ minRows: 3, maxRows: 5 }} /> : <>
        <p>订单：{operation?.row.order_no}</p>
        <p>{operation?.action === 'pay' ? `将从该会员余额扣除 ${money(operation.row.payable_minor, operation.row.currency)}。库存不足时整笔付款回滚。付款完成不代表实例已开通。` : '确认取消此未支付订单？取消后不能继续付款。'}</p>
      </>}
    </FormDialog>
    {finance && <FinanceOperationDrawer resource={finance.resource} action="create" record={null} initial={finance.initial} visible onCancel={() => setFinance(null)} onSaved={() => { setFinance(null); setReload(value => value + 1) }} />}
  </section>
}
