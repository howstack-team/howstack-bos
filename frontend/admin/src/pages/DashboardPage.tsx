import { Button, Card, Grid, Progress, Table, Tag, Typography } from '@arco-design/web-react'
import { IconApps, IconFile, IconSafe, IconSettings, IconStorage, IconUser, IconUserGroup } from '@arco-design/web-react/icon'
import { useHistory } from 'react-router-dom'
import { useAdminAuth } from '../auth/AuthContext.ts'
import { tableCellText } from '../utils/tableCellText.ts'

const { Row, Col } = Grid
const stats = [
  { label: '今日销售额', value: '¥36,820', icon: <IconStorage />, change: '+8.6%' },
  { label: '今日订单', value: '126 单', icon: <IconApps />, change: '+12.4%' },
  { label: '新增客户', value: '38 位', icon: <IconUser />, change: '+5.2%' },
  { label: '待交付服务', value: '16 项', icon: <IconUserGroup />, change: '待处理' },
]
const orders = [
  { key: '1', number: 'HLYQ202609140018', customer: '宁波星河网络', product: '通用型云服务器 S6', amount: '¥816.00', status: '已完成' },
  { key: '2', number: 'HLYQ202609140017', customer: '杭州云际科技', product: '高防裸金属 B2', amount: '¥1,299.00', status: '交付中' },
  { key: '3', number: 'HLYQ202609140016', customer: '上海矩阵互联', product: '企业型 SSL 证书', amount: '¥1,680.00', status: '待审核' },
]
const trend = [
  { date: '09-08', value: 18600 }, { date: '09-09', value: 23600 },
  { date: '09-10', value: 21800 }, { date: '09-11', value: 28600 },
  { date: '09-12', value: 32600 }, { date: '09-13', value: 29800 },
  { date: '09-14', value: 36800 },
]
const sales = [
  { product: '云服务器', amount: 368 }, { product: '裸金属', amount: 186 },
  { product: '服务器托管', amount: 142 }, { product: 'SSL 证书', amount: 98 },
  { product: '对象存储', amount: 76 },
]

/** 经营数据尚无对应 API；页面明确展示演示数据。 */
export default function DashboardPage() {
  const navigate = useHistory().push
  const { permissions } = useAdminAuth()
  const actions = [
    { label: '系统用户', path: '/administrators', permission: 'admin.administrators.view', icon: <IconUser /> },
    { label: '部门管理', path: '/departments', permission: 'admin.departments.view', icon: <IconUserGroup /> },
    { label: '角色权限', path: '/roles', permission: 'admin.roles.view', icon: <IconSafe /> },
    { label: '系统设置', path: '/settings', permission: 'admin.page.settings.view', icon: <IconSettings /> },
    { label: '操作日志', path: '/audit/operations', permission: 'admin.page.operation-logs.view', icon: <IconFile /> },
  ].filter((action) => permissions.includes(action.permission))
  return <div className="dashboard-page">
    <Card className="dashboard-welcome" bordered={false}>
      <Typography.Title heading={4}>欢迎使用皓量云擎</Typography.Title>
      <Typography.Text type="secondary">业务运营工作台 · 以下经营指标为界面演示数据</Typography.Text>
    </Card>
    <Row gutter={16} className="dashboard-stats">
      {stats.map((item) => <Col key={item.label} xs={24} sm={12} lg={6}>
        <Card className="dashboard-stat" bordered={false}>
          <div className="dashboard-stat-head"><span>{item.label}</span><span className="dashboard-stat-icon">{item.icon}</span></div>
          <strong>{item.value}</strong><small>较昨日 {item.change}</small>
        </Card>
      </Col>)}
    </Row>
    <Row gutter={16}>
      <Col xs={24} lg={16}>
        <Card title="近 7 日销售趋势" className="dashboard-card" bordered={false} extra={<Tag color="arcoblue">演示数据</Tag>}>
          <div className="trend-bars" role="img" aria-label="近七日演示销售额逐日变化">
            {trend.map((item) => <div className="trend-item" key={item.date}>
              <strong>¥{Math.round(item.value / 1000)}k</strong>
              <div className="trend-bar-track"><div style={{ height: `${Math.round(item.value / 36800 * 100)}%` }} /></div>
              <span>{item.date}</span>
            </div>)}
          </div>
        </Card>
      </Col>
      <Col xs={24} lg={8}>
        <Card title="产品销售排行" className="dashboard-card" bordered={false} extra={<Tag color="arcoblue">演示数据</Tag>}>
          <div className="sales-ranking">
            {sales.map((item, index) => <div className="sales-row" key={item.product}>
              <div><span>{index + 1}. {item.product}</span><strong>{item.amount} 单</strong></div>
              <div className="sales-track"><div style={{ width: `${Math.round(item.amount / 368 * 100)}%` }} /></div>
            </div>)}
          </div>
        </Card>
      </Col>
    </Row>
    <Row gutter={16}>
      <Col xs={24} lg={16}>
        <Card title="最近订单" className="dashboard-card" bordered={false} extra={<Tag color="arcoblue">演示数据</Tag>}>
          <div className="table-scroll"><Table rowKey="key" columns={[
            { title: '订单号', dataIndex: 'number', width: 185, ellipsis: true, render: tableCellText },
            { title: '客户', dataIndex: 'customer', width: 140, ellipsis: true, render: tableCellText },
            { title: '产品', dataIndex: 'product', width: 180, ellipsis: true, render: tableCellText },
            { title: '金额', dataIndex: 'amount', width: 110, render: tableCellText },
            { title: '状态', dataIndex: 'status', width: 100, render: (value) => value ? <Tag color={value === '已完成' ? 'green' : 'blue'}>{value}</Tag> : '-' },
          ]} data={orders} pagination={false} scroll={{ x: 715 }} /></div>
        </Card>
      </Col>
      <Col xs={24} lg={8}>
        <Card title="快捷操作" className="dashboard-card" bordered={false}>
          <div className="quick-actions">{actions.map((action) => <Button key={action.path} className="quick-action" type="text" icon={action.icon} onClick={() => navigate(action.path)}>{action.label}</Button>)}</div>
          <Typography.Title heading={6}>服务概况 <Tag color="arcoblue">演示数据</Tag></Typography.Title>
          {[['产品交付成功率', 98], ['上游接口可用率', 96], ['工单按时响应率', 92]].map(([label, percent]) => <div className="health-line" key={label}>
            <div><span>{label}</span><strong>{percent}%</strong></div><Progress percent={Number(percent)} size="small" showText={false} />
          </div>)}
        </Card>
      </Col>
    </Row>
  </div>
}
