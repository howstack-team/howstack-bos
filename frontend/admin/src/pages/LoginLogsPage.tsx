import { StatusBadge } from '../components/TableEnum'
import { AUDIT_STATUSES } from '../utils/businessEnums'
import { enumLabels } from '../utils/tableEnum'
import useTableQuery from '../hooks/useTableQuery'
import TableQueryForm from '../components/TableQueryForm'
import { useEffect, useState } from 'react'
import { Form, Alert, Select, Table } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { tableCellText } from '../utils/tableCellText.ts'
import { TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'
import { listLoginLogs } from '../api/loginLogsApi.ts'
import type { LoginLogRecord } from '../api/loginLogsApi.ts'

/** 登录成功和失败的真实审计记录。 */
export default function LoginLogsPage() {
  const searchFields = [
    { key: 'username', label: '账号', maxLength: 64 },
    { key: 'nickname', label: '昵称', maxLength: 64 },
    { key: 'client_ip', label: 'IP', maxLength: 64 },
    { key: 'client_ip_location', label: '地点', maxLength: 128 },
  ]
  const { queryParams, sortColumn, filterColumn, onTableChange, fields, draftFields, setField, discard, page, pageSize, setPage, setPageSize, search, reset, refreshKey, status: appliedStatus, draftStatus, setDraftStatus } = useTableQuery({ sortFields: ['logged_at'], textFields: searchFields.map(field => field.key), statusValues: [0, 1] })
  const status = appliedStatus === undefined ? undefined : Number(appliedStatus) as 0 | 1
  const [rows, setRows] = useState<LoginLogRecord[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    const timer = window.setTimeout(() => {
      setLoading(true)
      setError('')
      void listLoginLogs({ ...queryParams, status, page, page_size: pageSize })
        .then((result) => {
          if (!active) return
          setRows(result.data)
          setTotal(result.total)
        })
        .catch((reason: unknown) => {
          if (!active) return
          setRows([])
          setTotal(0)
          setError(reason instanceof Error ? reason.message : '登录日志加载失败')
        })
        .finally(() => { if (active) setLoading(false) })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [queryParams, fields, status, page, pageSize, refreshKey])

  const columns: TableColumnProps<LoginLogRecord>[] = [
    { title: '序号', width: 80, render: (_value, _row, index) => (page - 1) * pageSize + index + 1 },
    { ...sortColumn('logged_at'), title: '登录时间', dataIndex: 'logged_at', width: 180, render: tableCellText },
    { title: '用户昵称', dataIndex: 'nickname', width: 150, ellipsis: true, render: tableCellText },
    { ...filterColumn('status', enumLabels(AUDIT_STATUSES)), title: '状态', width: 90, render: (_value, row) => <StatusBadge value={row.status} mapping={AUDIT_STATUSES} /> },
    { title: '登录IP', dataIndex: 'client_ip', width: 170, ellipsis: true, render: tableCellText },
    { title: '登录地点', dataIndex: 'client_ip_location', width: 180, ellipsis: true, render: tableCellText },
    { title: '浏览器', dataIndex: 'browser', width: 150, ellipsis: true, render: tableCellText },
    { title: '操作系统', dataIndex: 'operating_system', width: 170, ellipsis: true, render: tableCellText },
  ]

  return <section className="page-card">
    <TableQueryForm fields={searchFields} values={draftFields} onFieldChange={setField}
      appliedCount={Object.keys(queryParams).filter(key => !['sort_by', 'sort_order'].includes(key)).length} onCancelFilters={discard}
      onSearch={search} onReset={reset} loading={loading}>

      <Form.Item label="状态" className="table-query-field" style={{ marginBottom: 0, marginRight: 0, minWidth: 0, maxWidth: '100%' }}><Select placeholder="全部状态" allowClear value={draftStatus === undefined ? undefined : Number(draftStatus)} onChange={setDraftStatus}
        options={Object.entries(AUDIT_STATUSES).map(([value, item]) => ({ label: item.text, value: Number(value) }))} style={{ width: 130 }} /></Form.Item>
      </TableQueryForm>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="table-scroll"><Table<LoginLogRecord> onChange={onTableChange} rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: 1170 }}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: setPageSize }} /></div>
  </section>
}
