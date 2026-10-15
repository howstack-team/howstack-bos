import { Table } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import type { InstallationJob } from '../api/officialPlatformApi'
import { DEFAULT_TABLE_PAGE_SIZE, TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination'

import { StatusBadge } from './TableEnum'
import { INSTALLATION_STATUSES } from '../utils/businessEnums'
const columns: TableColumnProps<InstallationJob>[] = [
  { title: '时间', dataIndex: 'created_at', width: 180 },
  { title: '应用 / 系统', dataIndex: 'target_code', width: 180, ellipsis: true },
  { title: '目标版本', dataIndex: 'target_version', width: 120 },
  { title: '状态', width: 120, render: (_, row) => <StatusBadge value={row.status} mapping={INSTALLATION_STATUSES} /> },
  { title: '执行说明', dataIndex: 'message', width: 320, ellipsis: true },
]
export default function InstallationJobsTable({ jobs }: { jobs: InstallationJob[] }) {
  return <Table rowKey="request_id" columns={columns} data={jobs} scroll={{ x: 920 }} pagination={{ defaultPageSize: DEFAULT_TABLE_PAGE_SIZE, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS }} />
}
