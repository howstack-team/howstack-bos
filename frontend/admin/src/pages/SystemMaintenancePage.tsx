import { Alert, Button, Descriptions, Empty, Space, Steps, Tabs, Typography } from '@arco-design/web-react'
import { IconRefresh, IconUpload } from '@arco-design/web-react/icon'
import { useHistory, useLocation } from 'react-router-dom'
import './SystemMaintenancePage.css'

const Step = Steps.Step

function LicensePanel() {
  return <div className="system-maintenance-panel">
    <Alert type="info" content="授权服务尚未接入。以下字段不代表当前实例的实际授权状态。" />
    <div className="system-maintenance-section">
      <Descriptions title="授权信息" column={{ xs: 1, md: 2 }} border data={[
        { label: '授权状态', value: '待接入' },
        { label: '授权主体', value: '-' },
        { label: '实例标识', value: '-' },
        { label: '授权有效期', value: '-' },
        { label: '授权版本', value: '-' },
        { label: '授权能力', value: '-' },
      ]} />
    </div>
    <div className="system-maintenance-section">
      <Typography.Title heading={6}>授权操作</Typography.Title>
      <Typography.Paragraph type="secondary">后续支持导入授权文件、校验签名及查看授权变更记录。授权文件不会仅由前端判定有效。</Typography.Paragraph>
      <Space size="small"><Button type="primary" icon={<IconUpload />} disabled>导入授权文件</Button><Button disabled>刷新授权状态</Button></Space>
    </div>
  </div>
}

function UpdatePanel() {
  return <div className="system-maintenance-panel">
    <Alert type="info" content="版本检查与更新服务尚未接入。当前页面不会下载更新包或执行系统更新。" />
    <div className="system-maintenance-section">
      <Descriptions title="版本信息" column={{ xs: 1, md: 2 }} border data={[
        { label: '当前版本', value: '待接入' },
        { label: '最新版本', value: '待接入' },
        { label: '更新源', value: '待接入' },
        { label: '更新状态', value: '尚未检查' },
        { label: '上次检查时间', value: '-' },
      ]} />
      <Space size="small" className="system-maintenance-actions"><Button icon={<IconRefresh />} disabled>检查更新</Button><Button type="primary" disabled>开始更新</Button></Space>
    </div>
    <div className="system-maintenance-section">
      <Typography.Title heading={6}>更新流程</Typography.Title>
      <Steps size="small" current={0} className="system-update-steps">
        <Step title="检查版本" /><Step title="下载并校验" /><Step title="备份与预检" /><Step title="执行更新" />
      </Steps>
      <Typography.Paragraph type="secondary">更新包由服务端从受信更新源获取；执行前需校验版本兼容性、文件完整性和数据库迁移状态，并完成备份。</Typography.Paragraph>
    </div>
    <div className="system-maintenance-section">
      <Typography.Title heading={6}>更新记录</Typography.Title>
      <Empty description="更新记录尚未接入" />
    </div>
  </div>
}

export default function SystemMaintenancePage() {
  const history = useHistory()
  const location = useLocation()
  const activeTab = new URLSearchParams(location.search).get('tab') === 'updates' ? 'updates' : 'license'

  return <section className="page-card system-maintenance-page">
    <div className="system-maintenance-heading">
      <Typography.Title heading={5}>授权与更新</Typography.Title>
      <Typography.Text type="secondary">集中查看实例授权和版本更新信息。</Typography.Text>
    </div>
    <Tabs activeTab={activeTab} headerPadding={false} onChange={(key) => history.replace({ pathname: '/maintenance', search: key === 'updates' ? '?tab=updates' : '' })}>
      <Tabs.TabPane key="license" title="授权管理"><LicensePanel /></Tabs.TabPane>
      <Tabs.TabPane key="updates" title="版本更新"><UpdatePanel /></Tabs.TabPane>
    </Tabs>
  </section>
}
