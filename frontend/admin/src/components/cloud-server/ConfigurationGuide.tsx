import { Button, Typography } from '@arco-design/web-react'
import { IconArrowRight } from '@arco-design/web-react/icon'
import { resourceZones, useCloudWorkspace, type CloudArea } from './workspace'

/** 可执行的缺项提示；保存校验仍由后端及供应插件负责。 */
export default function ConfigurationGuide() {
  const state = useCloudWorkspace()
  const draft = state.draft
  let missing: { text: string; area: CloudArea; zone?: number } | undefined
  const zones = state.resources.zones.filter(row => draft.resource_ids.zones.includes(row.id) && row.status === 1)
  if (!zones.length) missing = { text: '请选择参与购买的可用区。', area: 'deployment' }
  for (const zone of zones) {
    if (missing) break
    for (const [kind, label, area] of [['flavors', '规格', 'specification'], ['lines', '线路', 'network'], ['images', '镜像', 'storage'], ['disk_types', '存储类型', 'storage']] as const) {
      if (!state.resources[kind].some(row => row.status === 1 && draft.resource_ids[kind].includes(row.id) && resourceZones(row).includes(zone.id))) {
        missing = { text: `${zone.name}尚未配置参与购买的${label}。`, area, zone: zone.id }; break
      }
    }
    const binding = draft.zone_provider_bindings?.find(row => row.zone_id === zone.id)
    const legacy = state.deliveryRules.find(row => row.id === draft.delivery_rule_id)
    const providerId = binding?.connection_id ?? legacy?.connection_id ?? draft.provider_connection_id
    if (!missing && !providerId) missing = { text: `${zone.name}尚未选择提供者。`, area: 'deployment', zone: zone.id }
    if (!missing && draft.schema_version === '2.0.0' && !draft.zone_durations?.find(row => row.zone_id === zone.id)?.durations.length) missing = { text: `${zone.name}尚未配置付费周期。`, area: 'purchase', zone: zone.id }
  }
  if (!missing) return null
  return <div className={`cloud-configuration-guide ${missing ? 'cloud-guide-pending' : ''}`}>
    <Typography.Text>{missing ? missing.text : '基础配置已填写，保存时将校验组合及提供者资源对应。'}</Typography.Text>
    {missing && <Button type="text" icon={<IconArrowRight />} disabled={!state.canNavigate(missing.area)} onClick={() => state.navigate(missing.area, undefined, missing.zone)}>去配置</Button>}
  </div>
}
