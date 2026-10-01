import { useEffect, useState } from 'react'
import { Alert, Descriptions, Drawer, Input, Link, Select, Table, Tag } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { getServiceInstance, listServiceInstances } from '../api/serviceInstanceApi.ts'
import type { ServiceInstance, ServiceProductType } from '../api/serviceInstanceApi.ts'
import TableActions from '../components/TableActions.tsx'
import { tableCellText } from '../utils/tableCellText.ts'
import { DEFAULT_TABLE_PAGE_SIZE, TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

const TITLES: Record<ServiceProductType, string> = {
  cloud_server: '云服务器', physical_server: '物理服务器', server_colocation: '服务器托管',
}
const STATUSES: Record<string, { text: string; color: string }> = {
  provisioning: { text: '开通中', color: 'blue' }, active: { text: '已开通', color: 'green' },
  suspended: { text: '已暂停', color: 'orange' }, expired: { text: '已到期', color: 'gray' },
  terminated: { text: '已终止', color: 'gray' }, failed: { text: '开通失败', color: 'red' },
}
const DESCRIPTION_COLUMNS = { xs: 1, sm: 1, md: 2, lg: 2, xl: 2, xxl: 2, xxxl: 2 }

/** 指定产品类型的已创建服务实例列表。 */
export default function ServiceInstancesPage({ productType }: { productType: ServiceProductType }) {
  const [input, setInput] = useState('')
  const [keyword, setKeyword] = useState('')
  const [status, setStatus] = useState<string | undefined>()
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_TABLE_PAGE_SIZE)
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
      void listServiceInstances({ product_type: productType, keyword, status, page, page_size: pageSize })
        .then((result) => { if (active) { setRows(result.data); setTotal(result.total) } })
        .catch((reason: unknown) => {
          if (active) { setRows([]); setTotal(0); setError(reason instanceof Error ? reason.message : '服务实例加载失败') }
        })
        .finally(() => { if (active) setLoading(false) })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [productType, keyword, status, page, pageSize])

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
    { title: '状态', width: 110, render: (_, row) => <Tag color={STATUSES[row.status]?.color ?? 'gray'}>{STATUSES[row.status]?.text ?? row.status}</Tag> },
    { title: '开通时间', dataIndex: 'activated_at', width: 180, render: tableCellText },
    { title: '到期时间', dataIndex: 'expires_at', width: 180, render: tableCellText },
    { title: '创建时间', dataIndex: 'created_at', width: 180, render: tableCellText },
    { title: '操作', width: 90, fixed: 'right', render: (_, row) =>
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
    <div className="page-toolbar">
      <Input.Search placeholder="搜索实例编号、名称或会员" value={input} onChange={setInput}
        onSearch={(value) => { setPage(1); setKeyword(value.trim()) }} allowClear style={{ width: 300, maxWidth: '100%' }} />
      <Select placeholder="全部状态" allowClear value={status} onChange={(value) => { setPage(1); setStatus(value) }}
        options={Object.entries(STATUSES).map(([value, item]) => ({ value, label: item.text }))} style={{ width: 140 }} />
    </div>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="table-scroll"><Table<ServiceInstance> rowKey="id" columns={columns} data={rows} loading={loading}
      scroll={{ x: 1716 }} pagination={{ current: page, pageSize, total, showTotal: true,
        sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: (size) => { setPage(1); setPageSize(size) } }} /></div>
    <Drawer title={`${TITLES[productType]}详情`} visible={selectedId !== null} width="min(760px, 100vw)" footer={null}
      onCancel={() => { setSelectedId(null); setDetail(null) }}>
      {detailError && <Alert type="error" content={detailError} />}
      {detail && <Descriptions title="实例信息" data={detailRows} column={DESCRIPTION_COLUMNS} border />}
    </Drawer>
  </section>
}
