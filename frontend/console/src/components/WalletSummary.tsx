import { Alert, Button, Empty, Grid, Space, Spin, Statistic, Typography } from '@arco-design/web-react'
import { ApiRequestError } from '../api/httpClient'
import { useWallet } from '../hooks/useWallet'
import { minorToYuanText } from '../utils/money'

/** 展示真实余额；首次失败显示重试，刷新失败保留旧值并明确提示。 */
export default function WalletSummary() {
  const wallet = useWallet()
  if (wallet.isPending) return <div className="wallet-loading" role="status" aria-label="正在查询资金账户"><Spin /></div>
  const forbidden = wallet.error instanceof ApiRequestError && wallet.error.status === 403
  return <Space direction="vertical" size="large" style={{ width: '100%' }}>
    {wallet.isError && <Alert type={forbidden ? 'warning' : 'error'} title={forbidden ? '暂时无法访问资金账户' : wallet.data ? '余额刷新失败，当前显示上次查询结果' : '资金账户加载失败'}
      content={<Space wrap><span>{wallet.error.message}</span>{!forbidden && <Button size="small" onClick={() => { void wallet.refetch() }} loading={wallet.isFetching}>重新查询</Button>}</Space>} />}
    {wallet.data && !forbidden ? <Grid.Row gutter={[24, 24]}>
      <Grid.Col xs={24} sm={12}><Statistic title="可用余额（元）" value={minorToYuanText(wallet.data.available_minor)} prefix="¥" groupSeparator
        extra={<Typography.Text type="secondary">可用于符合条件的订单余额支付</Typography.Text>} /></Grid.Col>
      <Grid.Col xs={24} sm={12}><Statistic title="冻结余额（元）" value={minorToYuanText(wallet.data.frozen_minor)} prefix="¥" groupSeparator
        extra={<Typography.Text type="secondary">冻结期间暂不可使用</Typography.Text>} /></Grid.Col>
    </Grid.Row> : !wallet.isError && <Empty description="暂无资金账户数据" />}
  </Space>
}
