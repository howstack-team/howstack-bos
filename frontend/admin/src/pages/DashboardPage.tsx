import type { MouseEvent, ReactNode } from 'react'
import { Alert, Avatar, Button, Card, Empty, Link, Statistic, Table, Typography } from '@arco-design/web-react'
import { IconArrowRight, IconCalendar, IconCloud, IconCustomerService, IconDesktop, IconFile, IconIdcard, IconRefresh, IconRight, IconSafe, IconSettings, IconStamp, IconStorage, IconSwap, IconUser, IconUserGroup } from '@arco-design/web-react/icon'
import { useHistory } from 'react-router-dom'
import { useAdminAuth } from '../auth/AuthContext'
import { useWorkplaceData } from '../hooks/useWorkplaceData'
import type { WorkplaceKey } from '../api/workplaceApi'
import type { OrderSummary } from '../api/orderApi'
import WorkplaceShortcuts from '../components/WorkplaceShortcuts'
import { StatusBadge } from '../components/TableEnum'
import { ORDER_STATUSES, ORDER_TYPES } from '../utils/businessEnums'
import { formatMoneyMinor } from '../utils/money'
import { tableCellText } from '../utils/tableCellText'
import './DashboardPage.css'

interface WorkplaceEntry { key: WorkplaceKey; name: string; path: string; permission: string; icon: ReactNode }
const summary: WorkplaceEntry[] = [
  { key: 'members', name: '会员总数', path: '/members', permission: 'admin.members.view', icon: <IconUserGroup /> },
  { key: 'orders', name: '订单总数', path: '/orders', permission: 'admin.page.order-list.view', icon: <IconFile /> },
  { key: 'cloud', name: '已开通云服务器', path: '/services/cloud-servers?status=active', permission: 'admin.services.cloud-servers.view', icon: <IconCloud /> },
  { key: 'tickets', name: '待处理工单', path: '/support/tickets?status=open', permission: 'admin.tickets.list.view', icon: <IconCustomerService /> },
]
const todos: WorkplaceEntry[] = [
  { key: 'tickets', name: '待处理工单', path: '/support/tickets?status=open', permission: 'admin.tickets.list.view', icon: <IconCustomerService /> },
  { key: 'fulfilling', name: '交付中订单', path: '/orders?status=fulfilling', permission: 'admin.page.order-list.view', icon: <IconStorage /> },
  { key: 'identity', name: '实名认证审核', path: '/real-name-authentications?status=pending', permission: 'admin.real-name-authentications.view', icon: <IconIdcard /> },
  { key: 'refunds', name: '退款审核', path: '/refunds?status=pending_review', permission: 'admin.finance.refunds.view', icon: <IconSwap /> },
  { key: 'invoices', name: '发票审核', path: '/invoices?status=pending_review', permission: 'admin.finance.invoices.view', icon: <IconFile /> },
  { key: 'withdrawals', name: '提现审核', path: '/withdrawals?status=pending_review', permission: 'admin.finance.withdrawals.view', icon: <IconStamp /> },
]
const services: WorkplaceEntry[] = [
  { key: 'cloud', name: '云服务器', path: '/services/cloud-servers?status=active', permission: 'admin.services.cloud-servers.view', icon: <IconCloud /> },
  { key: 'physical', name: '物理服务器', path: '/services/physical-servers?status=active', permission: 'admin.services.physical-servers.view', icon: <IconDesktop /> },
  { key: 'colocation', name: '服务器托管', path: '/services/server-colocations?status=active', permission: 'admin.services.server-colocations.view', icon: <IconStorage /> },
]
const shortcuts = [
  { name: '订单管理', path: '/orders', permission: 'admin.page.order-list.view', icon: <IconFile /> },
  { name: '会员管理', path: '/members', permission: 'admin.members.view', icon: <IconUser /> },
  { name: '工单管理', path: '/support/tickets', permission: 'admin.tickets.list.view', icon: <IconCustomerService /> },
  { name: '充值记录', path: '/recharges', permission: 'admin.finance.recharges.view', icon: <IconStamp /> },
  { name: '退款记录', path: '/refunds', permission: 'admin.finance.refunds.view', icon: <IconSwap /> },
  { name: '实名认证', path: '/real-name-authentications', permission: 'admin.real-name-authentications.view', icon: <IconIdcard /> },
]
const management = [
  { name: '资源供应商', path: '/providers/connections', permission: 'admin.provider-connections.view', icon: <IconStorage /> },
  { name: '系统设置', path: '/settings', permission: 'admin.page.settings.view', icon: <IconSettings /> },
  { name: '角色权限', path: '/roles', permission: 'admin.roles.view', icon: <IconSafe /> },
  { name: '操作日志', path: '/audit/operations', permission: 'admin.page.operation-logs.view', icon: <IconFile /> },
]
function title(text: string) { return <Typography.Title heading={6} style={{ margin: 0 }}>{text}</Typography.Title> }

/** 日常运营工作台，按权限复用真实业务列表统计及最近订单，不提供演示交易或经营图表。 */
export default function DashboardPage() {
  const { user, permissions } = useAdminAuth()
  const history = useHistory()
  const { records, loading, reload } = useWorkplaceData(user?.id, permissions)
  const permitted = <T extends { permission: string },>(items: readonly T[]) => items.filter(item => permissions.includes(item.permission))
  const metrics = permitted(summary), tasks = permitted(todos), products = permitted(services)
  const quickLinks = permitted([...shortcuts, ...management])
  const nickname = user?.nickname || user?.username || '管理员'
  const date = new Intl.DateTimeFormat('zh-CN', { year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' }).format(new Date())
  const errors = Object.values(records).filter(record => record.error)
  const orderState = records.orders
  function navigate(event: MouseEvent<HTMLAnchorElement>, path: string) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
    event.preventDefault(); history.push(path)
  }
  function pageLink(text: string, path: string, icon?: ReactNode) {
    return <Link href={history.createHref({ pathname: path })} hoverable={false} icon={icon} onClick={event => navigate(event, path)}>{text}</Link>
  }
  function count(entry: WorkplaceEntry, size = 26, color?: string) {
    const state = records[entry.key]
    return <Statistic value={state?.data?.total ?? '--'} loading={!state} styleValue={{ fontSize: size, color }} groupSeparator />
  }
  return <div className="workplace-page">
    <section aria-label="欢迎卡片">
      <Card className="workplace-card" bodyStyle={{ padding: 24 }}>
        <div className="workplace-welcome">
          <div className="workplace-user"><Avatar size={52} style={{ backgroundColor: 'rgb(var(--primary-6))', flexShrink: 0 }}>{nickname.slice(0, 1)}</Avatar>
            <div><Typography.Title heading={4} style={{ margin: '0 0 8px' }}>欢迎回来，{nickname}</Typography.Title><Typography.Text type="secondary">从待办开始，让每一项业务有序推进。</Typography.Text></div></div>
          <div className="workplace-date"><IconCalendar aria-hidden="true" /><span>{date}</span></div>
        </div>
        <div className="workplace-welcome-footer"><span>当前账号：{user?.username ?? '-'}</span><span>最近登录：{user?.last_login_at || '暂无登录记录'}</span>
          {permissions.includes('admin.profile.view') && pageLink('个人设置', '/profile', <IconArrowRight />)}</div>
      </Card>
    </section>
    <section aria-label="业务摘要">
      <div className="workplace-section-heading"><div>{title('业务摘要')}<Typography.Text type="secondary">统计基于当前账号可查看的业务范围</Typography.Text></div>
        <Button icon={<IconRefresh />} loading={loading} onClick={reload}>刷新数据</Button></div>
      {errors.length > 0 && <Alert className="workplace-load-error" type="warning" title="部分业务数据加载失败" content="已加载的数据仍可查看，请刷新重试。" />}
      {metrics.length ? <div className="workplace-metrics">{metrics.map(entry => <Card className="workplace-card" key={entry.key}>
        <div className="workplace-metric-header"><span>{entry.name}</span><span className="workplace-metric-icon" aria-hidden="true">{entry.icon}</span></div>
        {count(entry)}<div className="workplace-metric-footer"><span>{records[entry.key]?.error ? '加载失败' : '当前可查看数量'}</span>{pageLink('查看', entry.path, <IconArrowRight />)}</div>
      </Card>)}</div> : <Card className="workplace-card"><Empty description="暂无可查看的业务摘要" /></Card>}
    </section>
    <div className="workplace-columns">
      <div className="workplace-main">
        <section aria-label="待办事项"><Card className="workplace-card" title={title('待办事项')}>
          {tasks.length ? <div className="workplace-tasks">{tasks.map(entry => <a className="workplace-task" key={entry.key}
            href={history.createHref({ pathname: entry.path })} aria-label={`查看${entry.name}`} onClick={event => navigate(event, entry.path)}>
            <span className="workplace-task-icon" aria-hidden="true">{entry.icon}</span>
            <div className="workplace-task-info"><span>{entry.name}</span>{records[entry.key]?.error && <span className="workplace-row-note">加载失败</span>}</div>
            <div className="workplace-row-count">{count(entry, 22, records[entry.key]?.data?.total ? 'rgb(var(--primary-6))' : 'var(--color-text-3)')}<span>项</span></div>
            <IconRight className="workplace-row-arrow" aria-hidden="true" />
          </a>)}</div> : <Empty description="暂无可查看的待办事项" />}
        </Card></section>
        {permissions.includes('admin.page.order-list.view') && <section aria-label="最近订单"><Card className="workplace-card" title={title('最近订单')} extra={pageLink('全部订单', '/orders', <IconArrowRight />)}>
          {orderState?.error ? <Empty description="最近订单加载失败，请刷新重试" /> : <Table<OrderSummary> rowKey="id" loading={!orderState} data={orderState?.data?.orders ?? []}
            pagination={false} scroll={{ x: 740 }} columns={[
              { title: '订单号', dataIndex: 'order_no', width: 165, ellipsis: true, render: tableCellText },
              { title: '会员', dataIndex: 'member_nickname_snapshot', width: 110, ellipsis: true, render: tableCellText },
              { title: '类型', dataIndex: 'order_type', width: 70, render: value => ORDER_TYPES[value]?.text ?? tableCellText(value) },
              { title: '应付金额', dataIndex: 'payable_minor', width: 130, render: (_, order) => formatMoneyMinor(order.payable_minor, order.currency) },
              { title: '状态', dataIndex: 'status', width: 100, render: value => <StatusBadge value={value} mapping={ORDER_STATUSES} /> },
              { title: '下单时间', dataIndex: 'created_at', width: 165, ellipsis: true, render: tableCellText },
            ]} />}
        </Card></section>}
      </div>
      <div className="workplace-aside">
        <WorkplaceShortcuts entries={quickLinks} />
        <section aria-label="产品服务"><Card className="workplace-card" title={title('产品服务')} extra={<Typography.Text type="secondary" style={{ fontSize: 12 }}>已开通实例</Typography.Text>}>
          {products.length ? <div className="workplace-products">{products.map(entry => <a className="workplace-product" key={entry.key}
            href={history.createHref({ pathname: entry.path })} aria-label={`查看${entry.name}`} onClick={event => navigate(event, entry.path)}>
            <span className="workplace-product-icon" aria-hidden="true">{entry.icon}</span>
            <div className="workplace-product-info"><span>{entry.name}</span>{records[entry.key]?.error && <span className="workplace-row-note">加载失败</span>}</div>
            <div className="workplace-row-count">{count(entry, 22)}<span>台</span></div>
            <IconRight className="workplace-row-arrow" aria-hidden="true" />
          </a>)}</div> : <Empty description="暂无可查看的产品服务" />}
        </Card></section>
      </div>
    </div>
  </div>
}
