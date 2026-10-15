import { useState } from 'react'
import { Alert, Button, Card, Descriptions, Empty, Message, Modal, Space, Spin, Table, Link } from '@arco-design/web-react'
import { useHistory, useParams } from 'react-router-dom'
import { useMemberOrders } from '../hooks/useMemberOrders'
import { paths } from '../routePaths'
import { minorToYuanText as formatMinor } from '../utils/money'

const orderStatus: Record<string, string> = { pending_payment: '待付款', fulfilling: '处理中', completed: '已完成', cancelled: '已取消', failed: '处理失败' }
/** 本人订单列表及详情；支付前使用原生 Modal 确认。 */
export default function MemberOrdersPage() {
  const history = useHistory(), params = useParams<{ id?: string }>(), id = params.id ? Number(params.id) : undefined
  const [page, setPage] = useState(1), [paying, setPaying] = useState(false)
  const query = useMemberOrders(page, id), data = query.data
  const pay = (orderId: number, amount: number) => Modal.confirm({ maskClosable: true, title: '确认余额付款', content: `将从资金账户支付 ¥${formatMinor(amount)}。`, onOk: async () => {
    setPaying(true); try { await query.pay(orderId); Message.success('付款成功') } catch (error) { Message.error(error instanceof Error ? error.message : '付款失败'); throw error } finally { setPaying(false) }
  } })
  return <Card title={id ? '订单详情' : '我的订单'} extra={<Button onClick={() => history.push(paths.purchase)}>购买云服务器</Button>}>
    {query.error && <Alert type="error" content={query.error.message} action={<Button onClick={() => void query.refetch()}>重试</Button>} />}
    <Spin loading={query.isPending || paying}>{data && 'data' in data ? <Table rowKey="id" data={data.data} scroll={{ x: 680 }} pagination={{ current: page, pageSize: 20, total: data.total, onChange: setPage }} columns={[
      { title: '订单号', dataIndex: 'order_no', width: 280, ellipsis: true }, { title: '状态', render: (_, row) => orderStatus[row.status] ?? row.status },
      { title: '金额', render: (_, row) => `¥${formatMinor(row.payable_minor)}` }, { title: '创建时间', dataIndex: 'created_at', width: 180 },
      { title: '操作', render: (_, row) => <Link onClick={() => history.push(`${paths.orders}/${row.id}`)}>查看详情</Link> },
    ]} /> : data && 'order_no' in data ? <><Descriptions column={1} data={[{ label: '订单号', value: data.order_no }, { label: '状态', value: orderStatus[data.status] ?? data.status }, { label: '应付金额', value: `¥${formatMinor(data.payable_minor)}` }, { label: '创建时间', value: data.created_at }, ...(data.items ?? []).map(item => ({ label: item.product_name, value: `${item.quantity} 台` }))]} /><Space wrap style={{ marginTop: 20 }}><Button onClick={() => history.push(paths.orders)}>返回订单列表</Button>{data.status === 'pending_payment' && data.payment_status === 'unpaid' && <><Button onClick={() => history.push({ pathname: paths.recharge, state: { from: `${paths.orders}/${data.id}` } })}>充值余额</Button><Button type="primary" onClick={() => pay(data.id, data.payable_minor)}>余额付款</Button></>}</Space></> : !query.isPending && !query.error && <Empty description="暂无订单" />}</Spin>
  </Card>
}
