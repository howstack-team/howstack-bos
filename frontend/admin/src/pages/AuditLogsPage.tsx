import useTableQuery from '../hooks/useTableQuery'
import TableQueryForm from '../components/TableQueryForm'
import { useMemo } from 'react'
import { Form, Alert, Input, Table, Typography } from '@arco-design/web-react'
import { mockTablePageDefinitions } from '../mock/adminMockData.ts'
import type { MockTableRecord } from '../mock/adminMockData.ts'
import { tableCellText } from '../utils/tableCellText.ts'
import { TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

export default function AuditLogsPage({ type }: { type: 'operation-logs' | 'login-logs' }) {
  const { input, setInput, keyword, page, pageSize, setPage, setPageSize, search, reset } = useTableQuery()
  const definition = mockTablePageDefinitions[type]
  const rows = useMemo(() => definition.records.filter((row) => Object.values(row).some((value) =>
    String(value).toLowerCase().includes(keyword.trim().toLowerCase()))), [definition, keyword])
  return <section className="page-card">
    <TableQueryForm onSearch={search} onReset={reset}>
      <Form.Item label="关键词" className="table-query-field" style={{ marginBottom: 0, marginRight: 0, minWidth: 0, maxWidth: '100%' }}><Input  placeholder={definition.searchPlaceholder} value={input} onChange={setInput} allowClear style={{ width: 240, maxWidth: '100%' }} /></Form.Item>
      </TableQueryForm>
    <Alert type="info" content="当前为界面演示数据，尚未接入真实审计日志。" style={{ marginBottom: 18 }} />
    <div className="table-scroll"><Table<MockTableRecord> rowKey="key" data={rows}
      pagination={{ current: page, pageSize, onChange: setPage, onPageSizeChange: setPageSize, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS, showTotal: true }}
      scroll={{ x: definition.columns.reduce((sum, column) => sum + column.width, 0) }}
      columns={definition.columns.map((column) => ({ title: column.title, dataIndex: column.dataIndex, width: column.width,
        ellipsis: column.kind === undefined, render: (_: unknown, row: MockTableRecord) => tableCellText(row[column.dataIndex]) }))} /></div>
    <Typography.Text type="secondary">日志查询和导出需要后端审计接口支持。</Typography.Text>
  </section>
}
