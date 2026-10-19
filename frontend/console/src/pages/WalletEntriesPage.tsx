import { useEffect, useRef } from 'react'
import { Button, Card, Empty, Message, Space, Spin, Table, Typography } from '@arco-design/web-react'
import { IconRefresh } from '@arco-design/web-react/icon'
import { useWalletEntries } from '../hooks/useWalletEntries'
import { walletBusinessLabels, walletEntryAmount, walletEntryLabels, walletEntryPageSizes, type WalletEntry } from '../api/walletEntriesApi'
import { minorToYuanText } from '../utils/money'
import PageHeading from '../components/PageHeading'

/** 本人只读流水；不提供金额修改、删除或资金执行按钮。 */
export default function WalletEntriesPage() {
  const query = useWalletEntries(), reported = useRef<unknown>(null)
  useEffect(() => {
    if (query.error && query.error !== reported.current) Message.error(query.error.message)
    reported.current = query.error
  }, [query.error])
  return <div className="page-stack">
    <PageHeading title="资金明细" group="财务管理" description="查询可用余额的变动记录，金额单位为元。"
      action={<Button icon={<IconRefresh />} loading={query.isFetching} onClick={() => void query.refetch()}>刷新明细</Button>} />
    <Card className="console-page-card">
      <Typography.Paragraph type="secondary">冻结资金打款不会再次扣减可用余额，因此该笔可用余额变动为 0。</Typography.Paragraph>
      {query.isError ? <Space direction="vertical"><Typography.Text>资金明细暂时无法加载</Typography.Text><Button onClick={() => void query.refetch()}>重新加载</Button></Space> :
        <Spin loading={query.isPending} style={{ display: 'block', width: '100%' }}><Table<WalletEntry> rowKey="entry_no" data={query.data?.data ?? []} scroll={{ x: 1260 }} noDataElement={<Empty description="暂无资金明细" />}
          pagination={{ current: query.page, pageSize: query.pageSize, total: query.data?.total ?? 0, showTotal: true, sizeCanChange: true,
            sizeOptions: [...walletEntryPageSizes], onChange: (page, size) => query.paginate(page, size) }}
          columns={[
            { title: '发生时间', dataIndex: 'created_at', width: 180 },
            { title: '流水号', dataIndex: 'entry_no', width: 260, ellipsis: true },
            { title: '变动类型', width: 150, render: (_, row) => walletEntryLabels[row.entry_type] ?? '其他变动' },
            { title: '可用余额变动', width: 155, render: (_, row) => <span style={{ whiteSpace: 'nowrap' }}>¥{walletEntryAmount(row.amount_minor)}</span> },
            { title: '变动后可用余额', width: 155, render: (_, row) => `¥${minorToYuanText(row.available_after_minor)}` },
            { title: '业务类型', width: 100, render: (_, row) => walletBusinessLabels[row.business_type] },
            { title: '业务单号', dataIndex: 'business_no', width: 260, ellipsis: true, render: value => value ?? '-' },
          ]} /></Spin>}
    </Card>
  </div>
}
