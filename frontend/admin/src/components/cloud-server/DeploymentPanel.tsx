import { Button, Drawer, Empty, Menu, Typography } from '@arco-design/web-react'
import { IconSettings } from '@arco-design/web-react/icon'
import { useHistory, useLocation } from 'react-router-dom'
import ComputeResourcesPage from '../../pages/ComputeResourcesPage'
import DeploymentProvider from './DeploymentProvider'
import { useCloudWorkspace } from './workspace'

/** 地域导航与可用区列表并排；每次只打开一个配置抽屉。 */
export default function DeploymentPanel() {
  const state = useCloudWorkspace()
  const history = useHistory()
  const location = useLocation()
  const params = new URLSearchParams(location.search)
  const manage = params.get('manage')
  const managing = manage === 'regions' || manage === 'zones' ? manage : null
  const regionId = Number(params.get('region')) || state.resources.zones.find(row => row.id === state.zoneId)?.region_id
  const region = state.resources.regions.find(row => row.id === regionId) ?? state.resources.regions[0]
  const configuring = state.resources.zones.find(row => row.id === Number(params.get('zone-config')))
  const updateLocation = (next: URLSearchParams) => history.push({ ...location, search: next.toString() })
  const openManagement = (value?: string) => {
    const next = new URLSearchParams(location.search)
    if (value) next.set('manage', value); else next.delete('manage')
    next.delete('edit'); next.delete('zone-config')
    updateLocation(next)
  }
  const selectRegion = (id: number) => {
    const next = new URLSearchParams(location.search)
    next.set('region', String(id))
    const firstZone = state.resources.zones.find(row => row.region_id === id)
    if (firstZone) next.set('zone', String(firstZone.id)); else next.delete('zone')
    next.delete('edit'); next.delete('zone-config'); next.delete('page')
    updateLocation(next)
  }
  const configureZone = (id?: number) => {
    const next = new URLSearchParams(location.search)
    if (id) { next.set('zone-config', String(id)); next.set('zone', String(id)) }
    else next.delete('zone-config')
    next.delete('edit'); next.delete('manage')
    updateLocation(next)
  }
  return <>
    <div className="cloud-deployment-layout">
      <aside className="cloud-zone-navigation" aria-label="地域与可用区导航">
        <div className="cloud-zone-heading">
          <Typography.Text bold>地域</Typography.Text>
          <Button type="text" size="mini" icon={<IconSettings />} aria-label="地域与可用区管理" onClick={() => openManagement('regions')}>管理</Button>
        </div>
        {state.resources.regions.length ? <Menu mode="vertical" style={{ width: '100%', padding: 0 }} selectedKeys={region ? [String(region.id)] : []} onClickMenuItem={key => selectRegion(Number(key))}>
          {state.resources.regions.map(row => <Menu.Item key={String(row.id)} style={row.id === region?.id ? { background: 'var(--color-primary-light-1)' } : undefined}>
            <span className="cloud-region-item"><span className="cloud-region-label" title={row.name}>{row.name}</span><span className="cloud-region-count">{state.resources.zones.filter(zone => zone.region_id === row.id).length}</span></span>
          </Menu.Item>)}
        </Menu> : <Empty description="尚未配置地域" />}
      </aside>
      <div className="cloud-deployment-content">
        {!region ? <Empty description="请先添加地域，再配置可用区" /> : <>
          <div className="cloud-region-summary"><Typography.Text bold>{region.name}</Typography.Text><Typography.Text type="secondary">可用区</Typography.Text></div>
          <ComputeResourcesPage key={region.id} kinds={['zones']} embedded workspace scopeRegionId={region.id} editorEnabled={!managing && !configuring} onConfigureZone={row => configureZone(row.id)} />
        </>}
      </div>
    </div>
    {configuring && !managing && <Drawer visible title={`提供者配置 · ${configuring.name}`} width="min(640px, calc(100vw - 24px))" onCancel={() => configureZone()} footer={<Button type="primary" onClick={() => configureZone()}>完成</Button>}>
      <Typography.Paragraph type="secondary">为此可用区选择提供者并配置资源对应，完成后点击页面下方的“保存更改”。</Typography.Paragraph>
      <DeploymentProvider key={configuring.id} zone={configuring} editorMode="modal" />
    </Drawer>}
    {managing && <Drawer visible title={managing === 'regions' ? '地域管理' : '可用区管理'} width="min(860px, calc(100vw - 24px))" footer={null} onCancel={() => openManagement()}>
      <ComputeResourcesPage key={managing} kinds={[managing]} embedded workspace editorMode="modal" />
    </Drawer>}
  </>
}
