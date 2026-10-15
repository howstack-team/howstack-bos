import { StatusBadge } from '../components/TableEnum'
import { SERVICE_STATUSES as STATUSES, PRODUCT_TYPE_LABELS } from '../utils/businessEnums'
import { enumLabels } from '../utils/tableEnum'
import useTableQuery from '../hooks/useTableQuery'
import TableQueryForm from '../components/TableQueryForm'
import { useEffect, useState } from 'react'
import { Form, Alert, Descriptions, Drawer, Link, Select, Table } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { getServiceInstance, listServiceInstances } from '../api/serviceInstanceApi.ts'
import type { ServiceInstance, ServiceProductType } from '../api/serviceInstanceApi.ts'
import TableActions from '../components/TableActions.tsx'
import { tableCellText } from '../utils/tableCellText.ts'
import { TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

const TITLES = PRODUCT_TYPE_LABELS

const DESCRIPTION_COLUMNS = { xs: 1, sm: 1, md: 2, lg: 2, xl: 2, xxl: 2, xxxl: 2 }

/** 指定产品类型的已创建服务实例列表。 */
export default function ServiceInstancesPage({ productType }: { productType: ServiceProductType }) {
  const searchFields = [
    { key: 'instance_no', label: '实例编号', maxLength: 64 },
    { key: 'display_name', label: '实例名称', maxLength: 128 },
    { key: 'member_mobile', label: '会员手机号', maxLength: 20 },
    { key: 'member_nickname', label: '会员昵称', maxLength: 64 },
  ]
  const { queryParams, sortColumn, filterColumn, onTableChange, draftFields, setField, discard, page, pageSize, setPage, setPageSize, search, reset, refreshKey, status, draftStatus, setDraftStatus } = useTableQuery({ sortFields: ['activated_at', 'expires_at', 'created_at'], textFields: searchFields.map(field => field.key), statusValues: Object.keys(STATUSES) })
  const [rows, setRows] = useState<ServiceInstance[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [detail, setDetail] = useState<ServiceInstance | null>(null)
  const [detailError, setDetailError] = useState('')

  useEffect(() => {
    let active = true
    const timer = window.setTimeout(() => {
      setLoading(true)
      setError('')
      void listServiceInstances({ product_type: productType, ...queryParams, status, page, page_size: pageSize })
        .then((result) => { if (active) { setRows(result.data); setTotal(result.total) } })
        .catch((reason: unknown) => {
          if (active) { setRows([]); setTotal(0); setError(reason instanceof Error ? reason.message : '服务实例加载失败') }
        })
        .finally(() => { if (active) setLoading(false) })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [productType,queryParams, status, page, pageSize, refreshKey])

  useEffect(() => {
    if (selectedId === null) return
    let active = true
    const timer = window.setTimeout(() => {
      setDetail(null)
      setDetailError('')
      void getServiceInstance(selectedId)
        .then((result) => { if (active) setDetail(result) })
        .catch((reason: unknown) => { if (active) setDetailError(reason instanceof Error ? reason.message : '详情加载失败') })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [selectedId])

  const columns: TableColumnProps<ServiceInstance>[] = [
    { title: '序号', width: 76, render: (_, __, index) => (page - 1) * pageSize + index + 1 },
    { title: '实例编号', dataIndex: 'instance_no', width: 210, ellipsis: true, render: (_, row) =>
      <Link title={row.instance_no} onClick={() => setSelectedId(row.id)}>{row.instance_no}</Link> },
    { title: '实例名称', dataIndex: 'display_name', width: 180, ellipsis: true, render: tableCellText },
    { title: '会员昵称', dataIndex: 'member_nickname', width: 150, ellipsis: true, render: tableCellText },
    { title: '会员手机号', dataIndex: 'member_mobile', width: 160, ellipsis: true, render: tableCellText },
    { title: '来源订单', dataIndex: 'order_no', width: 200, ellipsis: true, render: tableCellText },
    { ...filterColumn('status', enumLabels(STATUSES)), title: '状态', width: 110, render: (_, row) => <StatusBadge value={row.status} mapping={STATUSES} /> },
    { ...sortColumn('activated_at'), title: '开通时间', dataIndex: 'activated_at', width: 180, render: tableCellText },
    { ...sortColumn('expires_at'), title: '到期时间', dataIndex: 'expires_at', width: 180, render: tableCellText },
    { ...sortColumn('created_at'), title: '创建时间', dataIndex: 'created_at', width: 180, render: tableCellText },
    { title: '操作', width: 114, fixed: 'right', render: (_, row) =>
      <TableActions actions={[{ key: 'detail', label: '详情', onClick: () => setSelectedId(row.id) }]} /> },
  ]

  const detailRows = detail ? [
    ['实例编号', detail.instance_no], ['实例名称', detail.display_name],
    ['产品类型', TITLES[detail.product_type]], ['状态', STATUSES[detail.status]?.text ?? detail.status],
    ['会员昵称', detail.member_nickname], ['会员手机号', detail.member_mobile],
    ['来源订单', detail.order_no], ['开通时间', detail.activated_at],
    ['到期时间', detail.expires_at], ['创建时间', detail.created_at],
  ].map(([label, value], index) => ({ key: String(index), label, value: tableCellText(value) })) : []

  return <section className="page-card">
    <TableQueryForm fields={searchFields} values={draftFields} onFieldChange={setField}
      appliedCount={Object.keys(queryParams).filter(key => !['sort_by', 'sort_order'].includes(key)).length} onCancelFilters={discard}
      onSearch={search} onReset={reset} loading={loading}>

      <Form.Item label="状态" className="table-query-field" style={{ marginBottom: 0, marginRight: 0, minWidth: 0, maxWidth: '100%' }}><Select placeholder="全部状态" allowClear value={draftStatus} onChange={setDraftStatus}
        options={Object.entries(STATUSES).map(([value, item]) => ({ value, label: item.text }))} style={{ width: 140 }} /></Form.Item>
      </TableQueryForm>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="table-scroll"><Table<ServiceInstance> onChange={onTableChange} rowKey="id" columns={columns} data={rows} loading={loading}
      scroll={{ x: 1716 }} pagination={{ current: page, pageSize, total, showTotal: true,
        sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: setPageSize }} /></div>
    <Drawer maskClosable title={`${TITLES[productType]}详情`} visible={selectedId !== null} width="min(760px, calc(100vw - 24px))" footer={null}
      onCancel={() => { setSelectedId(null); setDetail(null) }}>
      {detailError && <Alert type="error" content={detailError} />}
      {detail && <Descriptions title="实例信息" data={detailRows} column={DESCRIPTION_COLUMNS} border />}
    </Drawer>
  </section>
}
