import { useMemo, useState } from 'react'
import { Alert, Input, Table, Typography } from '@arco-design/web-react'
import { IconSearch } from '@arco-design/web-react/icon'
import { mockTablePageDefinitions } from '../mock/adminMockData.ts'
import type { MockTableRecord } from '../mock/adminMockData.ts'
import { tableCellText } from '../utils/tableCellText.ts'
import { DEFAULT_TABLE_PAGE_SIZE, TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

export default function AuditLogsPage({ type }: { type: 'operation-logs' | 'login-logs' }) {
  const [keyword, setKeyword] = useState('')
  const definition = mockTablePageDefinitions[type]
  const rows = useMemo(() => definition.records.filter((row) => Object.values(row).some((value) =>
    String(value).toLowerCase().includes(keyword.trim().toLowerCase()))), [definition, keyword])
  return <section className="page-card">
    <div className="page-toolbar">
      <Input prefix={<IconSearch />} placeholder={definition.searchPlaceholder} value={keyword} onChange={setKeyword} allowClear style={{ width: 240, maxWidth: '100%' }} />
    </div>
    <Alert type="info" content="当前为界面演示数据，尚未接入真实审计日志。" style={{ marginBottom: 18 }} />
    <div className="table-scroll"><Table<MockTableRecord> rowKey="key" data={rows}
      pagination={{ defaultPageSize: DEFAULT_TABLE_PAGE_SIZE, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS, showTotal: true }}
      scroll={{ x: definition.columns.reduce((sum, column) => sum + column.width, 0) }}
      columns={definition.columns.map((column) => ({ title: column.title, dataIndex: column.dataIndex, width: column.width,
        ellipsis: column.kind === undefined, render: (_: unknown, row: MockTableRecord) => tableCellText(row[column.dataIndex]) }))} /></div>
    <Typography.Text type="secondary">日志查询和导出需要后端审计接口支持。</Typography.Text>
  </section>
}
