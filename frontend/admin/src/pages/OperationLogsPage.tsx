import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { Alert, Descriptions, Drawer, Input, Link, Select, Spin, Table, Tag } from '@arco-design/web-react'
import { IconSearch } from '@arco-design/web-react/icon'
import type { TableColumnProps } from '@arco-design/web-react'
import { getOperationLog, listOperationLogs } from '../api/operationLogsApi.ts'
import type { OperationLogDetail, OperationLogSummary } from '../api/operationLogsApi.ts'
import TableActions from '../components/TableActions.tsx'
import { tableCellText } from '../utils/tableCellText.ts'
import { DEFAULT_TABLE_PAGE_SIZE, TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'
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
  const [input, setInput] = useState('')
  const [keyword, setKeyword] = useState('')
  const [status, setStatus] = useState<0 | 1 | undefined>()
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_TABLE_PAGE_SIZE)
  const [rows, setRows] = useState<OperationLogSummary[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [detail, setDetail] = useState<OperationLogDetail | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState('')

  useEffect(() => {
    const timer = window.setTimeout(() => { setPage(1); setKeyword(input.trim()) }, 300)
    return () => window.clearTimeout(timer)
  }, [input])

  useEffect(() => {
    let active = true
    const timer = window.setTimeout(() => {
      setLoading(true)
      setError('')
      void listOperationLogs({ keyword, status, page, page_size: pageSize })
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
  }, [keyword, status, page, pageSize])

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
    { title: '操作时间', dataIndex: 'operated_at', width: 200, render: (_value, row) =>
      row.operated_at ? <Link href="#" style={{ whiteSpace: 'nowrap' }} onClick={(event) => { event.preventDefault(); openDetail(row.id) }}>{row.operated_at}</Link> : '-' },
    { title: '操作人', dataIndex: 'operator_nickname', width: 130, ellipsis: true, render: tableCellText },
    { title: '操作内容', dataIndex: 'operation', width: 150, ellipsis: true, render: tableCellText },
    { title: '所属模块', dataIndex: 'module', width: 120, ellipsis: true, render: tableCellText },
    { title: '状态', width: 90, render: (_value, row) => <Tag color={row.status === 1 ? 'green' : 'red'}>{row.status === 1 ? '成功' : '失败'}</Tag> },
    { title: '操作IP', dataIndex: 'client_ip', width: 155, ellipsis: true, render: tableCellText },
    { title: '操作地点', dataIndex: 'client_ip_location', width: 160, ellipsis: true, render: tableCellText },
    { title: '耗时', width: 100, render: (_value, row) => row.duration_ms == null ? '-' : row.duration_ms + ' ms' },
    { title: '操作', width: 80, fixed: 'right', render: (_value, row) => <TableActions actions={[{ key: 'detail', label: '详情', onClick: () => openDetail(row.id) }]} /> },
  ]

  const infoRows: DetailRow[] = detail ? [
    { key: 'operated_at', label: '操作时间', value: detail.operated_at },
    { key: 'operator', label: '操作人', value: detail.operator_nickname },
    { key: 'operator_username', label: '操作账号', value: detail.operator_username },
    { key: 'operation', label: '操作内容', value: detail.operation },
    { key: 'module', label: '所属模块', value: detail.module },
    { key: 'status', label: '状态', value: <Tag color={detail.status === 1 ? 'green' : 'red'}>{detail.status === 1 ? '成功' : '失败'}</Tag> },
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
    <div className="page-toolbar">
      <Input prefix={<IconSearch />} placeholder="搜索操作人、模块、内容或 IP" value={input} onChange={setInput} allowClear style={{ width: 290, maxWidth: '100%' }} />
      <Select placeholder="全部状态" allowClear value={status} onChange={(value: 0 | 1 | undefined) => { setPage(1); setStatus(value) }}
        options={[{ label: '成功', value: 1 }, { label: '失败', value: 0 }]} style={{ width: 130 }} />
    </div>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="table-scroll"><Table<OperationLogSummary> rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: 1261 }}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: (size) => { setPage(1); setPageSize(size) } }} /></div>
    <Drawer title="操作日志详情" visible={selectedId !== null} width="min(760px, 100vw)" onCancel={closeDetail} footer={null}>
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
