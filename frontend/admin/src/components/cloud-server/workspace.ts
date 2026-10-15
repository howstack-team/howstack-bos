import { createContext, useContext } from 'react'
import { defaultCloudDurations, type CloudDraft, type CloudOffer, type CloudDeliveryRule, type ComputeInventoryPool, type ComputeResource, type ProviderConnectionOption, type ResourceData, type ResourceKind } from '../../api/cloudProductApi'

/** 社区版云服务器五个配置页面。 */
export const CLOUD_AREAS = [
  { key: 'deployment', title: '地域和可用区' },
  { key: 'specification', title: '规格与套餐' },
  { key: 'network', title: '线路和网络' },
  { key: 'storage', title: '镜像和存储' },
  { key: 'purchase', title: '购买与计费' },
] as const
export type CloudArea = typeof CLOUD_AREAS[number]['key']

/** 共享资源的可用区关联，兼容历史标量字段。 */
export function resourceZones(row: ComputeResource): number[] {
  return row.zone_ids ?? (row.zone_id ? [row.zone_id] : [])
}

/** 将旧周期独立复制给各可用区；新增区用原价周期，移除区清理对应草稿。 */
export function withZoneDurations(draft: CloudDraft): CloudDraft {
  if (draft.schema_version !== '2.0.0') return draft
  const { durations: legacy, ...rest } = draft
  return { ...rest, zone_durations: draft.resource_ids.zones.map(zone_id => ({
    zone_id,
    durations: (draft.zone_durations?.find(row => row.zone_id === zone_id)?.durations
      ?? (draft.zone_durations === undefined ? legacy : undefined)
      ?? defaultCloudDurations()).map(row => ({ ...row })),
  })) }
}

/** 在现有配置中调整可用资源，父级移除时仅清理确实失效的关联。 */
export function selectResource(draft: CloudDraft, resources: ResourceData, kind: ResourceKind, id: number, included: boolean): CloudDraft {
  const selected = { ...draft.resource_ids, [kind]: included ? [...new Set([...draft.resource_ids[kind], id])] : draft.resource_ids[kind].filter(value => value !== id) }
  if (kind === 'zones' && included) {
    const region = resources.zones.find(row => row.id === id)?.region_id
    if (region) selected.regions = [...new Set([...selected.regions, region])]
  }
  if (kind === 'regions') selected.zones = selected.zones.filter(zone => selected.regions.includes(resources.zones.find(row => row.id === zone)?.region_id ?? 0))
  if (kind === 'regions' || kind === 'zones') {
    for (const child of ['lines', 'flavors', 'images', 'disk_types'] as const) {
      selected[child] = selected[child].filter(value => resources[child].some(row => row.id === value && resourceZones(row).some(zone => selected.zones.includes(zone))))
    }
  }
  if (['regions', 'zones', 'lines'].includes(kind)) {
    selected.protections = selected.protections.filter(value => resources.protections.some(row => row.id === value && (row.line_ids ?? [row.line_id ?? 0]).some(line => selected.lines.includes(line))))
  }
  return { ...draft, resource_ids: selected,
    zone_provider_bindings: draft.zone_provider_bindings?.filter(binding => selected.zones.includes(binding.zone_id)) }
}

/** 工作台的受控草稿；资源表单即时保存，购买范围与规则通过页面保存入口统一提交。 */
export interface CloudWorkspaceState {
  offer: CloudOffer | null
  draft: CloudDraft
  resources: ResourceData
  inventory: ComputeInventoryPool[]
  inventorySaved: (pool: ComputeInventoryPool) => void
  connections: ProviderConnectionOption[]
  deliveryRules: CloudDeliveryRule[]
  refreshResources: () => Promise<void>
  editable: boolean
  zoneId: number | undefined
  setZone: (id: number) => void
  changeDraft: (draft: CloudDraft) => void
  select: (kind: ResourceKind, id: number, included: boolean) => void
  resourceSaved: (kind: ResourceKind, row: ComputeResource, created: boolean) => Promise<void>
  canNavigate: (area: CloudArea) => boolean
  navigate: (area: CloudArea, resource?: ResourceKind, zone?: number) => void
}
export const CloudWorkspaceContext = createContext<CloudWorkspaceState | null>(null)

/** 仅在固定云服务器工作台内使用。 */
export function useCloudWorkspace(): CloudWorkspaceState {
  const state = useContext(CloudWorkspaceContext)
  if (!state) throw new Error('云服务器工作台未初始化')
  return state
}
