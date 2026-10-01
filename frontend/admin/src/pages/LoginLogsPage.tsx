import { useEffect, useState } from 'react'
import { Alert, Input, Select, Table, Tag } from '@arco-design/web-react'
import { IconSearch } from '@arco-design/web-react/icon'
import type { TableColumnProps } from '@arco-design/web-react'
import { tableCellText } from '../utils/tableCellText.ts'
import { DEFAULT_TABLE_PAGE_SIZE, TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'
import { listLoginLogs } from '../api/loginLogsApi.ts'
import type { LoginLogRecord } from '../api/loginLogsApi.ts'

/** 登录成功和失败的真实审计记录。 */
export default function LoginLogsPage() {
  const [input, setInput] = useState('')
  const [keyword, setKeyword] = useState('')
  const [status, setStatus] = useState<0 | 1 | undefined>()
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_TABLE_PAGE_SIZE)
  const [rows, setRows] = useState<LoginLogRecord[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setPage(1)
      setKeyword(input.trim())
    }, 300)
    return () => window.clearTimeout(timer)
  }, [input])

  useEffect(() => {
    let active = true
    const timer = window.setTimeout(() => {
      setLoading(true)
      setError('')
      void listLoginLogs({ keyword, status, page, page_size: pageSize })
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
  }, [keyword, status, page, pageSize])

  const columns: TableColumnProps<LoginLogRecord>[] = [
    { title: '序号', width: 80, render: (_value, _row, index) => (page - 1) * pageSize + index + 1 },
    { title: '登录时间', dataIndex: 'logged_at', width: 180, render: tableCellText },
    { title: '用户昵称', dataIndex: 'nickname', width: 150, ellipsis: true, render: tableCellText },
    { title: '状态', width: 90, render: (_value, row) => <Tag color={row.status === 1 ? 'green' : 'red'}>{row.status === 1 ? '成功' : '失败'}</Tag> },
    { title: '登录IP', dataIndex: 'client_ip', width: 170, ellipsis: true, render: tableCellText },
    { title: '登录地点', dataIndex: 'client_ip_location', width: 180, ellipsis: true, render: tableCellText },
    { title: '浏览器', dataIndex: 'browser', width: 150, ellipsis: true, render: tableCellText },
    { title: '操作系统', dataIndex: 'operating_system', width: 170, ellipsis: true, render: tableCellText },
  ]

  return <section className="page-card">
    <div className="page-toolbar">
      <Input prefix={<IconSearch />} placeholder="搜索账号、昵称、IP 或地点" value={input} onChange={setInput} allowClear style={{ width: 260, maxWidth: '100%' }} />
      <Select placeholder="全部状态" allowClear value={status} onChange={(value: 0 | 1 | undefined) => { setPage(1); setStatus(value) }}
        options={[{ label: '成功', value: 1 }, { label: '失败', value: 0 }]} style={{ width: 130 }} />
    </div>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="table-scroll"><Table<LoginLogRecord> rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: 1170 }}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: (size) => { setPage(1); setPageSize(size) } }} /></div>
  </section>
}
