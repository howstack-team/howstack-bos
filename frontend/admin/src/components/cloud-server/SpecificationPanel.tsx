import { Empty, Radio } from '@arco-design/web-react'
import { useLocation } from 'react-router-dom'
import ZoneScope from './ZoneScope'
import ComputeResourcesPage from '../../pages/ComputeResourcesPage'
import { useCloudWorkspace } from './workspace'

/** 规格、套餐及硬件分组按可用区管理，地域和提供者在独立页面配置。 */
export default function SpecificationPanel() {
  const state = useCloudWorkspace()
  const params = new URLSearchParams(useLocation().search)
  const kind = params.get('resource') === 'flavor_groups' ? 'flavor_groups' : 'flavors'
  const zone = state.resources.zones.find(row => row.id === state.zoneId)
  return <>
    <ZoneScope label="规格可用区" enabled={kind === 'flavors'}>
    <Radio.Group aria-label="规格分类" type="button" className="cloud-resource-switch" value={kind}
      options={[{ value: 'flavors', label: '规格与套餐' }, { value: 'flavor_groups', label: '硬件分组' }]}
      onChange={value => state.navigate('specification', value)} />
    </ZoneScope>
    {!zone && kind === 'flavors' ? <Empty description="请先配置地域和可用区" />
      : <ComputeResourcesPage key={kind} kinds={[kind]} embedded workspace scopeZoneId={kind === 'flavors' ? zone?.id : undefined} />}
  </>
}
