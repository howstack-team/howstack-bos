import { deliveryLabel } from '../api/serviceApi'
import { useState } from 'react'
import { useHistory, useParams } from 'react-router-dom'
import Button from '@arco-design/mobile-react/esm/button'
import Dialog from '@arco-design/mobile-react/esm/dialog'
import { useMemberOrders } from '../hooks/useMemberOrders'
import { paths } from '../routePaths'
import { minorToYuanText } from '../utils/money'
import { useFeedback } from './feedback'
import { Busy, EmptyState, Status } from './ui'
const labels: Record<string, string> = { pending_payment: '待付款', paid: '已付款', fulfilling: '履约中', completed: '已完成', cancelled: '已取消', failed: '处理失败' }

export default function MobileMemberOrdersPage() {
  const history = useHistory(), { id: routeId } = useParams<{ id?: string }>()
  const [page, setPage] = useState(1), [confirm, setConfirm] = useState(false), [cancelling, setCancelling] = useState(false), [paying, setPaying] = useState(false)
  const query = useMemberOrders(page, routeId ? Number(routeId) : undefined), data = query.data, feedback = useFeedback()
  if (query.isPending) return <Busy />
  if (query.error) return <Status text={query.error.message} retry={() => void query.refetch()} />
  return <><section className="m-panel"><Button type="default" onClick={() => history.push(paths.purchase)}>购买云服务器</Button></section>
    {data && 'data' in data ? <>{data.data.length ? data.data.map(order => <section className="m-panel" key={order.id}><p className="m-order-number">{order.order_no}</p><div className="m-row"><span>{labels[order.status] ?? order.status}</span><strong>¥{minorToYuanText(order.payable_minor)}</strong></div><p className="m-muted">{order.created_at}</p><Button size="small" type="default" onClick={() => history.push(`${paths.orders}/${order.id}`)}>查看详情</Button></section>) : <EmptyState text="暂无订单" />}
      <div className="m-row"><Button inline type="default" disabled={page <= 1} onClick={() => setPage(value => value - 1)}>上一页</Button><span>第 {page} 页</span><Button inline type="default" disabled={page * 20 >= data.total} onClick={() => setPage(value => value + 1)}>下一页</Button></div></> : data && 'order_no' in data && <section className="m-panel"><h2>订单详情</h2><p className="m-order-number">{data.order_no}</p><div className="m-row"><span>{labels[data.status] ?? data.status}</span><strong>¥{minorToYuanText(data.payable_minor)}</strong></div>{data.items?.map((item, index) => <p key={index}>{item.product_name} × {item.quantity} 台</p>)}<p className="m-muted">{data.created_at}</p>
      {data.units?.map(unit => <div className="m-row" key={unit.id}><span className="m-order-number">{unit.instance_no || `第 ${unit.unit_index} 台`}</span><span>{data.order_type === 'renewal' ? (data.renewal?.status === 'succeeded' ? '续费成功' : labels[data.status] ?? data.status) : deliveryLabel(unit.provisioning_status, unit.status)}</span></div>)}
      {data.status === 'pending_payment' && data.payment_status === 'unpaid' && <><Button disabled={paying} loading={paying} onClick={() => setConfirm(true)}>余额付款</Button><Button type="default" style={{ marginTop: 8 }} disabled={paying} onClick={() => setCancelling(true)}>取消订单</Button><Button type="default" style={{ marginTop: 8 }} onClick={() => history.push({ pathname: paths.recharge, state: { from: `${paths.orders}/${data.id}` } })}>充值余额</Button></>}
      <Dialog visible={cancelling} title="取消未付款订单？" maskClosable={!paying} close={() => { if (!paying) setCancelling(false) }} footer={[{ content: '返回', onClick: () => setCancelling(false) }, { content: paying ? '正在取消…' : '确认取消', onClick: () => {
        if (paying) return; setPaying(true)
        void query.cancel(data.id).then(() => { feedback('订单已取消', 'success'); setCancelling(false) }).catch(failure => feedback(failure instanceof Error ? failure.message : '取消失败')).finally(() => setPaying(false))
        return true
      } }]} />
      <Dialog visible={confirm} title="确认余额付款" maskClosable={!paying} close={() => { if (!paying) setConfirm(false) }} footer={[{ content: '取消', onClick: () => { if (!paying) setConfirm(false) } }, { content: '确认付款', onClick: () => {
        if (paying) return; setPaying(true)
        void query.pay(data.id).then(() => { feedback('付款成功', 'success'); setConfirm(false) }).catch(error => feedback(error instanceof Error ? error.message : '付款失败')).finally(() => setPaying(false))
        return true
      } }]}><p>将从资金账户支付 ¥{minorToYuanText(data.payable_minor)}。</p></Dialog>
    </section>}
  </>
}
