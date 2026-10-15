import { Card, Empty, Button } from '@arco-design/web-react'
import { useHistory, useLocation } from 'react-router-dom'
import PageHeading from '../components/PageHeading'
import { consoleRoutes, paths } from '../routes'

/** 尚未开放的业务入口只展示建设状态，不生成交易或服务数据。 */
export default function PendingFeaturePage() {
  const { pathname } = useLocation()
  const history = useHistory()
  const route = consoleRoutes.find(item => item.path === pathname.replace(/\/+$/, ''))
  return <div className="page-stack">
    <PageHeading title={route?.name ?? '功能建设中'} group={route?.group}
      description={`集中管理您的${route?.name ?? '业务'}。`} />
    <Card className="console-page-card" bordered={false}>
      <div className="console-feature-pending"><Empty description="功能正在建设中，开放后即可在这里使用。" /></div>
      {pathname === paths.cloudServers && <Button type="primary" onClick={() => history.push(paths.purchase)}>购买云服务器</Button>}
    </Card>
  </div>
}
