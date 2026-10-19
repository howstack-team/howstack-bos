import VncConsoleModal from '../components/VncConsoleModal'
import { powerLabels } from '../../../packages/service-operations'
import { useState } from 'react'
import { Alert, Button, Card, Link, Message, Modal, Space, Table, Tag } from '@arco-design/web-react'
import { useHistory } from 'react-router-dom'
import InstanceOperationsModal from '../components/InstanceOperationsModal'
import ServiceRenewalModal from '../components/ServiceRenewalModal'
import { deliveryLabel, getInitialPassword, lifecycleLabel } from '../api/serviceApi'
import { useMemberServices } from '../hooks/useMemberServices'
import { paths } from '../routePaths'
/** 服务状态来自真实实例，付款成功不直接显示开通成功。 */
export default function MemberServicesPage() {
  const [vnc, setVnc] = useState<number>()
  const [operation, setOperation] = useState<number>()
  const [renewal, setRenewal] = useState<number>()
  const [page, setPage] = useState(1), [secret, setSecret] = useState(''), [loadingPassword, setLoadingPassword] = useState<number>()
  const query = useMemberServices(page), history = useHistory()
  const password = async (id: number) => {
    setLoadingPassword(id)
    try { setSecret(await getInitialPassword(id)) } catch (error) { Message.error(error instanceof Error ? error.message : '初始密码读取失败') } finally { setLoadingPassword(undefined) }
  }
  return <Card title="我的云服务器" extra={<Space><Button onClick={() => void query.refetch()} loading={query.isFetching}>刷新</Button><Button type="primary" onClick={() => history.push(paths.purchase)}>购买云服务器</Button></Space>}>
    {query.error && <Alert type="error" content={query.error.message} />}
    <Table rowKey="id" loading={query.isPending} data={query.data?.data ?? []} scroll={{ x: 850 }} pagination={{ current: page, pageSize: 20, total: query.data?.total ?? 0, onChange: setPage }} columns={[
      { title: '实例名称', dataIndex: 'display_name', width: 160 }, { title: '实例编号', dataIndex: 'instance_no', width: 240, ellipsis: true },
      { title: '状态', width: 170, render: (_, row) => <Tag color={row.status === 'active' ? 'green' : row.provisioning_status === 'failed' ? 'red' : 'arcoblue'}>{deliveryLabel(row.provisioning_status, row.status)}</Tag> },
    { title: '电源状态', width: 130, render: (_, row) => powerLabels[row.power_status ?? 'unknown'] ?? '未知状态' },
      { title: '续费与到期处理', width: 190, render: (_, row) => lifecycleLabel(row.lifecycle_action, row.lifecycle_status) },
      { title: 'IP 地址', render: (_, row) => row.addresses.join('、') || '—' }, { title: '到期时间', dataIndex: 'expires_at', width: 180, render: value => value || '—' },
      { title: '操作', width: 160, render: (_, row) => <Space>{row.provisioning_status === 'succeeded' && <Link onClick={() => setOperation(row.id)}>管理</Link>}{row.can_renew && <Link onClick={() => setRenewal(row.id)}>续费</Link>}{row.renewal_order_id && row.renewal_payment_pending && <Link onClick={() => history.push(`${paths.orders}/${row.renewal_order_id}`)}>支付续费</Link>}<Link onClick={() => row.order_id && history.push(`${paths.orders}/${row.order_id}`)}>订单</Link>{row.initial_password_available && <Link disabled={loadingPassword === row.id} onClick={() => void password(row.id)}>初始密码</Link>}</Space> },
    ]} />
    {vnc !== undefined && <VncConsoleModal id={vnc} close={() => setVnc(undefined)} />}
    {operation !== undefined && <InstanceOperationsModal id={operation} openVnc={() => { setVnc(operation); setOperation(undefined) }} close={() => setOperation(undefined)} changed={() => void query.refetch()} />}
    {renewal !== undefined && <ServiceRenewalModal id={renewal} close={() => setRenewal(undefined)} />}
    <Modal title="实例初始密码" visible={Boolean(secret)} onCancel={() => setSecret('')} footer={<Button onClick={() => setSecret('')}>关闭</Button>} maskClosable unmountOnExit>
      <p>开通后 48 小时内可查看，请及时保存。</p><code style={{ overflowWrap: 'anywhere' }}>{secret}</code>
    </Modal>
  </Card>
}
