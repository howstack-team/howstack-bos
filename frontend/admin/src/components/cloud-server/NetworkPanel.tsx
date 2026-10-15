import { Empty, Form, Radio, Select } from '@arco-design/web-react'
import { useHistory, useLocation } from 'react-router-dom'
import ZoneScope from './ZoneScope'
import ComputeResourcesPage from '../../pages/ComputeResourcesPage'
import { resourceZones, useCloudWorkspace } from './workspace'

/** 所有网络配置归属于线路，防护按当前线路管理。 */
export default function NetworkPanel() {
  const state = useCloudWorkspace()
  const location = useLocation()
  const history = useHistory()
  const params = new URLSearchParams(location.search)
  const kind = params.get('resource') === 'protections' ? 'protections' : 'lines'
  const lines = state.resources.lines.filter(row => state.zoneId && resourceZones(row).includes(state.zoneId))
  const lineId = lines.some(row => row.id === Number(params.get('line'))) ? Number(params.get('line')) : lines[0]?.id
  return <>
    <ZoneScope label="网络可用区">
      <Radio.Group aria-label="网络分类" type="button" value={kind} options={[{ value: 'lines', label: '线路、带宽与 IP' }, { value: 'protections', label: '防护配置' }]} onChange={value => state.navigate('network', value)} />
    </ZoneScope>
    {kind === 'protections' && <Form layout="inline" className="cloud-line-selection"><Form.Item label="所属线路"><Select aria-label="防护所属线路" placeholder="请先配置线路" value={lineId} options={lines.map(row => ({ value: row.id, label: row.name }))} style={{ width: 240 }} onChange={value => { const next = new URLSearchParams(location.search); next.set('line', String(value)); history.replace({ ...location, search: next.toString() }) }} /></Form.Item></Form>}
    {!state.zoneId ? <Empty description="请先在地域和可用区中添加可用区" /> : kind === 'protections' && !lineId ? <Empty description="请先添加线路，再配置防护" />
      : <ComputeResourcesPage key={`${kind}:${state.zoneId}:${lineId}`} kinds={[kind]} workspace embedded scopeZoneId={kind === 'lines' ? state.zoneId : undefined} scopeLineId={kind === 'protections' ? lineId : undefined} />}
  </>
}
