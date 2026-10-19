import { Button, Card, Descriptions, Space, Typography } from '@arco-design/web-react'
import { IconRefresh } from '@arco-design/web-react/icon'
import PageHeading from '../components/PageHeading'
import WalletSummary from '../components/WalletSummary'
import { useHistory } from 'react-router-dom'
import { paths } from '../routePaths'
import { useWallet } from '../hooks/useWallet'

/** 本人资金账户；余额展示保持接口的人民币整数分语义。 */
export default function WalletPage() {
  const wallet = useWallet(), history = useHistory()
  return <div className="page-stack">
    <PageHeading title="资金账户" group="财务管理" description="查询您的可用余额和冻结余额。"
      action={<Button icon={<IconRefresh />} loading={wallet.isFetching} onClick={() => { void wallet.refetch() }}>刷新余额</Button>} />
    <Card className="console-page-card" title="账户余额" bordered={false}><WalletSummary /><Space wrap style={{ marginTop: 16 }}><Button type="primary" onClick={() => history.push(paths.recharge)}>在线充值</Button><Button onClick={() => history.push(paths.transactions)}>资金明细</Button></Space></Card>
    <Card className="console-page-card" title="账户说明" bordered={false}>
      <Descriptions column={1} data={[
        { label: '结算币种', value: '人民币（CNY）' },
        { label: '可用余额', value: '可用于满足付款条件的订单，付款金额以订单确认为准。' },
        { label: '冻结余额', value: '冻结期间暂不可使用，具体处理情况请联系服务方。' },
      ]} />
      <Typography.Paragraph type="secondary" style={{ marginBottom: 0 }}>充值到账后可通过余额付款，退款办理请联系平台。</Typography.Paragraph>
    </Card>
  </div>
}
