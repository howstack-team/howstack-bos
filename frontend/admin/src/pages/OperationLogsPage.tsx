import { StatusBadge } from '../components/TableEnum'
import { AUDIT_STATUSES } from '../utils/businessEnums'
import { enumLabels } from '../utils/tableEnum'
import useTableQuery from '../hooks/useTableQuery'
import TableQueryForm from '../components/TableQueryForm'
import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { Form, Alert, Descriptions, Drawer, Link, Select, Spin, Table } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { getOperationLog, listOperationLogs } from '../api/operationLogsApi.ts'
import type { OperationLogDetail, OperationLogSummary } from '../api/operationLogsApi.ts'
import TableActions from '../components/TableActions.tsx'
import { tableCellText } from '../utils/tableCellText.ts'
import { TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'
import './OperationLogsPage.css'

const DESCRIPTION_COLUMNS = { xs: 1, sm: 1, md: 2, lg: 2, xl: 2, xxl: 2, xxxl: 2 }

interface DetailRow { key: string; label: string; value: ReactNode }

const PARAMETER_LABELS: Record<string, string> = {
  target_id: '目标 ID', username: '账号', nickname: '昵称', name: '名称', code: '标识',
  department_id: '部门 ID', parent_id: '上级部门 ID', status: '设置状态',
  role_ids: '角色 ID', department_ids: '数据部门 ID', data_scope: '数据范围', permissions: '权限码',
  category: '设置分类', site_title: '网站标题', site_subtitle: '网站副标题', site_keywords: '网站关键词',
  site_description: '网站描述', site_url: '网站地址', copyright: '页脚版权信息',
  icp_record: 'ICP备案号', public_security_record: '公安备案号', business_license: '经营许可证',
  session_expire: '登录有效期（分钟）', login_attempts: '每 IP 登录尝试次数', login_window: '登录限制窗口（秒）',
}
const DATA_SCOPE_LABELS: Record<string, string> = {
  all: '全部数据', department_and_children: '本部门及下级', department: '本部门', self: '本人数据', custom: '自定义部门',
}

function parameterRows(raw: string | null, module: string): DetailRow[] {
  if (!raw) return []
  try {
    const params: unknown = JSON.parse(raw)
    if (typeof params !== 'object' || params === null || Array.isArray(params)) return []
    return Object.entries(params).map(([key, value]) => ({
      key,
      label: key === 'name' ? (module === '角色权限' ? '角色名称' : '部门名称')
        : key === 'code' && module === '角色权限' ? '角色标识' : PARAMETER_LABELS[key] ?? key,
      value: key === 'status' && (value === 0 || value === 1)
        ? (value === 1 ? '启用' : '禁用')
        : key === 'data_scope' && typeof value === 'string' ? DATA_SCOPE_LABELS[value] ?? value
        : Array.isArray(value) ? (value.length ? value.join('、') : '无') : String(value ?? '无'),
    }))
  } catch {
    return []
  }
}

/** 操作日志的真实分页列表及脱敏详情抽屉。 */
export default function OperationLogsPage() {
  const searchFields = [
    { key: 'operator_username', label: '操作账号', maxLength: 64 },
    { key: 'module', label: '模块', maxLength: 64 },
    { key: 'operation', label: '操作内容', maxLength: 128 },
    { key: 'client_ip', label: 'IP', maxLength: 64 },
    { key: 'operator_nickname', label: '操作人', maxLength: 64 },
  ]
  const { queryParams, sortColumn, filterColumn, onTableChange, fields, draftFields, setField, discard, page, pageSize, setPage, setPageSize, search, reset, refreshKey, status: appliedStatus, draftStatus, setDraftStatus } = useTableQuery({ sortFields: ['operated_at', 'duration_ms'], textFields: searchFields.map(field => field.key), statusValues: [0, 1] })
  const status = appliedStatus === undefined ? undefined : Number(appliedStatus) as 0 | 1
  const [rows, setRows] = useState<OperationLogSummary[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [detail, setDetail] = useState<OperationLogDetail | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState('')

  useEffect(() => {
    let active = true
    const timer = window.setTimeout(() => {
      setLoading(true)
      setError('')
      void listOperationLogs({ ...queryParams, status, page, page_size: pageSize })
        .then((result) => { if (active) { setRows(result.data); setTotal(result.total) } })
        .catch((reason: unknown) => {
          if (!active) return
          setRows([])
          setTotal(0)
          setError(reason instanceof Error ? reason.message : '操作日志加载失败')
        })
        .finally(() => { if (active) setLoading(false) })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [queryParams, fields, status, page, pageSize, refreshKey])

  useEffect(() => {
    if (selectedId === null) return
    let active = true
    const timer = window.setTimeout(() => {
      setDetailLoading(true)
      setDetailError('')
      void getOperationLog(selectedId)
        .then((result) => { if (active) setDetail(result) })
        .catch((reason: unknown) => { if (active) setDetailError(reason instanceof Error ? reason.message : '日志详情加载失败') })
        .finally(() => { if (active) setDetailLoading(false) })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [selectedId])

  function openDetail(id: number) { setDetail(null); setDetailError(''); setSelectedId(id) }
  function closeDetail() { setSelectedId(null); setDetail(null) }

  const columns: TableColumnProps<OperationLogSummary>[] = [
    { title: '序号', width: 76, render: (_value, _row, index) => (page - 1) * pageSize + index + 1 },
    { ...sortColumn('operated_at'), title: '操作时间', dataIndex: 'operated_at', width: 200, render: (_value, row) =>
      row.operated_at ? <Link href="#" style={{ whiteSpace: 'nowrap' }} onClick={(event) => { event.preventDefault(); openDetail(row.id) }}>{row.operated_at}</Link> : '-' },
    { title: '操作人', dataIndex: 'operator_nickname', width: 130, ellipsis: true, render: tableCellText },
    { title: '操作内容', dataIndex: 'operation', width: 150, ellipsis: true, render: tableCellText },
    { title: '所属模块', dataIndex: 'module', width: 120, ellipsis: true, render: tableCellText },
    { ...filterColumn('status', enumLabels(AUDIT_STATUSES)), title: '状态', width: 90, render: (_value, row) => <StatusBadge value={row.status} mapping={AUDIT_STATUSES} /> },
    { title: '操作IP', dataIndex: 'client_ip', width: 155, ellipsis: true, render: tableCellText },
    { title: '操作地点', dataIndex: 'client_ip_location', width: 160, ellipsis: true, render: tableCellText },
    { ...sortColumn('duration_ms'), title: '耗时', width: 100, render: (_value, row) => row.duration_ms == null ? '-' : row.duration_ms + ' ms' },
    { title: '操作', width: 104, fixed: 'right', render: (_value, row) => <TableActions actions={[{ key: 'detail', label: '详情', onClick: () => openDetail(row.id) }]} /> },
  ]

  const infoRows: DetailRow[] = detail ? [
    { key: 'operated_at', label: '操作时间', value: detail.operated_at },
    { key: 'operator', label: '操作人', value: detail.operator_nickname },
    { key: 'operator_username', label: '操作账号', value: detail.operator_username },
    { key: 'operation', label: '操作内容', value: detail.operation },
    { key: 'module', label: '所属模块', value: detail.module },
    { key: 'status', label: '状态', value: <StatusBadge value={detail.status} mapping={AUDIT_STATUSES} /> },
    { key: 'http_status', label: 'HTTP 状态', value: detail.http_status },
    { key: 'client_ip', label: '操作 IP', value: detail.client_ip },
    { key: 'location', label: '操作地点', value: detail.client_ip_location },
    { key: 'duration', label: '耗时', value: detail.duration_ms + ' ms' },
    { key: 'browser', label: '浏览器', value: detail.browser },
    { key: 'os', label: '操作系统', value: detail.operating_system },
    { key: 'method', label: '请求方法', value: detail.method },
    { key: 'url', label: '请求 URL', value: detail.request_url },
    { key: 'request_id', label: '请求 ID', value: detail.request_id },
  ] : []
  const params = detail ? parameterRows(detail.request_params, detail.module) : []
  const descriptionData = (rows: DetailRow[]) => rows.map((row) => ({
    key: row.key,
    label: row.label,
    value: <div className="operation-log-detail-value">{row.value}</div>,
  }))

  return <section className="page-card">
    <TableQueryForm fields={searchFields} values={draftFields} onFieldChange={setField}
      appliedCount={Object.keys(queryParams).filter(key => !['sort_by', 'sort_order'].includes(key)).length} onCancelFilters={discard}
      onSearch={search} onReset={reset} loading={loading}>

      <Form.Item label="状态" className="table-query-field" style={{ marginBottom: 0, marginRight: 0, minWidth: 0, maxWidth: '100%' }}><Select placeholder="全部状态" allowClear value={draftStatus === undefined ? undefined : Number(draftStatus)} onChange={setDraftStatus}
        options={Object.entries(AUDIT_STATUSES).map(([value, item]) => ({ label: item.text, value: Number(value) }))} style={{ width: 130 }} /></Form.Item>
      </TableQueryForm>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="table-scroll"><Table<OperationLogSummary> onChange={onTableChange} rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: 1261 }}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: setPageSize }} /></div>
    <Drawer maskClosable title="操作日志详情" visible={selectedId !== null} width="min(760px, calc(100vw - 24px))" onCancel={closeDetail} footer={null}>
      {detailLoading && <Spin />}
      {detailError && <Alert type="error" content={detailError} />}
      {detail && <>
        <Descriptions title="操作信息" column={DESCRIPTION_COLUMNS} border labelStyle={{ width: 132 }} data={descriptionData(infoRows)} />
        <Descriptions className="operation-log-parameters" title="操作参数" column={DESCRIPTION_COLUMNS} border labelStyle={{ width: 132 }}
          data={descriptionData(params.length ? params : [{ key: 'empty', label: '参数', value: '无' }])} />
      </>}
    </Drawer>
  </section>
}
