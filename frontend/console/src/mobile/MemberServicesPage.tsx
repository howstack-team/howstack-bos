import VncConsolePanel from './VncConsolePanel'
import { powerLabels } from '../../../packages/service-operations'
import { useState } from 'react'
import { useHistory } from 'react-router-dom'
import Button from '@arco-design/mobile-react/esm/button'
import Dialog from '@arco-design/mobile-react/esm/dialog'
import InstanceOperationsDialog from './InstanceOperationsDialog'
import ServiceRenewalDialog from './ServiceRenewalDialog'
import { deliveryLabel, getInitialPassword, lifecycleLabel } from '../api/serviceApi'
import { useMemberServices } from '../hooks/useMemberServices'
import { paths } from '../routePaths'
import { useFeedback } from './feedback'
import { Busy, EmptyState, Status } from './ui'
export default function MobileMemberServicesPage() {
  const [vnc, setVnc] = useState<number>()
  const [operation, setOperation] = useState<number>()
  const [renewal, setRenewal] = useState<number>()
  const [page, setPage] = useState(1), [secret, setSecret] = useState(''), [loading, setLoading] = useState<number>()
  const query = useMemberServices(page), history = useHistory(), feedback = useFeedback()
  const password = async (id: number) => { setLoading(id); try { setSecret(await getInitialPassword(id)) } catch (error) { feedback(error instanceof Error ? error.message : '初始密码读取失败') } finally { setLoading(undefined) } }
  if (query.isPending) return <Busy />
  if (query.error) return <Status text={query.error.message} retry={() => void query.refetch()} />
  return <><section className="m-panel"><Button onClick={() => history.push(paths.purchase)}>购买云服务器</Button></section>
    {!query.data?.data.length && <EmptyState text="暂无云服务器" />}
    {query.data?.data.map(row => <section className="m-panel" key={row.id}><div className="m-row"><h2>{row.display_name}</h2><span>{deliveryLabel(row.provisioning_status, row.status)}</span></div>
      <p className="m-order-number">{row.instance_no}</p><p>电源状态：{powerLabels[row.power_status ?? 'unknown'] ?? '未知状态'}</p><p>IP 地址：{row.addresses.join('、') || '等待分配'}</p><p className="m-muted">到期时间：{row.expires_at || '开通后起算'}</p>
      <p>{row.lifecycle_status && lifecycleLabel(row.lifecycle_action, row.lifecycle_status)}</p><div className="m-row">{row.provisioning_status === 'succeeded' && <Button inline size="small" onClick={() => setOperation(row.id)}>管理</Button>}{row.can_renew && <Button inline size="small" onClick={() => setRenewal(row.id)}>续费</Button>}{row.renewal_order_id && row.renewal_payment_pending && <Button inline size="small" onClick={() => history.push(`${paths.orders}/${row.renewal_order_id}`)}>支付续费</Button>}<Button inline size="small" type="default" onClick={() => row.order_id && history.push(`${paths.orders}/${row.order_id}`)}>查看订单</Button>{row.initial_password_available && <Button inline size="small" type="default" loading={loading === row.id} onClick={() => void password(row.id)}>初始密码</Button>}</div></section>)}
    {(query.data?.total ?? 0) > 20 && <div className="m-row"><Button inline type="default" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>上一页</Button><span>第 {page} 页</span><Button inline type="default" disabled={page * 20 >= (query.data?.total ?? 0)} onClick={() => setPage(p => p + 1)}>下一页</Button></div>}
    {vnc !== undefined && <VncConsolePanel id={vnc} close={() => setVnc(undefined)} />}
    {operation !== undefined && <InstanceOperationsDialog id={operation} openVnc={() => { setVnc(operation); setOperation(undefined) }} close={() => setOperation(undefined)} changed={() => void query.refetch()} />}
    {renewal !== undefined && <ServiceRenewalDialog id={renewal} close={() => setRenewal(undefined)} />}
    <Dialog visible={Boolean(secret)} title="实例初始密码" maskClosable close={() => setSecret('')} footer={[{ content: '关闭', onClick: () => setSecret('') }]}><p>开通后 48 小时内可查看，请及时保存。</p><code style={{ overflowWrap: 'anywhere' }}>{secret}</code></Dialog>
  </>
}
