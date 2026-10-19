import { ApiRequestError, request } from './httpClient'

/** 云服务器资源及价格的后台契约；所有金额单位为分。 */
export const RESOURCE_LABELS = { regions: '地域', zones: '可用区', lines: '线路', flavor_groups: '规格组', flavors: '规格', image_types: '镜像分类', images: '镜像', disk_types: '存储类型', protections: '防护' } as const
export type ResourceKind = keyof typeof RESOURCE_LABELS
export const CYCLE_LABELS = { hour: '小时', day: '天', month: '月', year: '年' } as const
export type BillingCycle = keyof typeof CYCLE_LABELS
export type Prices = Partial<Record<BillingCycle, number>>
export interface LineBandwidth {
  min: number; max: number; step: number; default: number; included: number; price_minor: number
  pricing_mode: 'flat' | 'graduated' | 'volume'; tiers: { up_to_mbps: number | null; price_minor: number }[]
}
export interface LineNetwork {
  billing_mode: 'fixed'; egress: LineBandwidth; ingress: LineBandwidth
  ip: { mode: 'range' | 'options'; min: number; max: number; step: number; default: number; included: number; price_minor: number; options: number[] }
}
export interface DurationOption { months: number; label: string; discount_kind: 'rate' | 'billed_months'; rate_bps?: number; billed_months?: number }
/** 每个可用区独立维护周期名称和优惠，不共享可变对象。 */
export interface ZoneDurations { zone_id: number; durations: DurationOption[] }
/** 新可用区初始周期；所有周期默认原价。 */
export function defaultCloudDurations(): DurationOption[] {
  return [{ months: 1, label: '月付' }, { months: 3, label: '季付' }, { months: 6, label: '半年付' }, { months: 12, label: '年付' }].map(row => ({ ...row, discount_kind: 'rate', rate_bps: 10000 }))
}
export function newLineNetwork(): LineNetwork {
  const band = (): LineBandwidth => ({ min: 1, max: 100, step: 1, default: 1, included: 1, price_minor: 0, pricing_mode: 'flat', tiers: [] })
  return { billing_mode: 'fixed', egress: band(), ingress: band(), ip: { mode: 'range', min: 1, max: 1, step: 1, default: 1, included: 1, price_minor: 0, options: [1] } }
}
export interface ComputeResource {
  country_code?: string; test_ip?: string
  network_configuration?: LineNetwork | null; default_username?: string; default_port?: number; icon_url?: string; capacity_gbps?: number
  id: number; code: string; name: string; description: string; status: number; sort: number
  region_id?: number; zone_id?: number; line_id?: number; group_id?: number; type_id?: number
  zone_ids?: number[]; line_ids?: number[]
  cpu?: number; memory_mib?: number; architecture?: string; min_disk_gib?: number; login_methods?: string[]
  min_cpu?: number; min_memory_mib?: number; cpu_model?: string; base_clock_mhz?: number | null; boost_clock_mhz?: number | null
  read_mbps?: number | null; write_mbps?: number | null; read_iops?: number | null; write_iops?: number | null
  min_gib?: number; max_gib?: number; step_gib?: number; max_count?: number; prices?: Prices; zone_prices?: Record<number, Prices>
  bandwidth_min?: number; bandwidth_max?: number; bandwidth_step?: number; ip_min?: number; ip_max?: number
}
export type ResourceData = Record<ResourceKind, ComputeResource[]>
export interface ProviderAdapterDefinition {
  id: string; plugin_id: string; name: string; version: string; sdk_version: string
  configuration_fields: { key: string; label: string; type: 'text' | 'password' | 'url' | 'integer' | 'boolean' | 'select'; required: boolean; max_length?: number; placeholder?: string; minimum?: number; maximum?: number; default?: string | number | boolean; description?: string; unit?: string; options?: { label: string; value: string }[] }[]
  catalogs: { resource_kind: ResourceKind; kind: string; label: string }[]
}
export interface ProviderConnection {
  id: number; name: string; adapter_id: string; api_url: string; username: string; has_password: boolean
  adapter_name: string; adapter_version: string | null; adapter_available: boolean; adapter_accepting: boolean; requires_retest: boolean
  adapter_catalogs: ProviderAdapterDefinition['catalogs']; configuration: Record<string, string | number | boolean>; configured_secrets: Record<string, boolean>
  status: number; revision: number; tested_at: string | null; created_at: string
  capabilities: { product_types: string[]; production_ready: boolean; catalog: boolean }
}
export type ProviderConnectionOption = Pick<ProviderConnection, 'id' | 'name' | 'status' | 'tested_at' | 'capabilities' | 'adapter_available' | 'adapter_accepting' | 'requires_retest'>
export interface ConnectionInput { name: string; adapter_id: string; configuration: Record<string, string | number | boolean>; status: number; revision: number; clear_secrets?: string[] }
export interface CatalogEntry { external_id: string; name: string; metadata: Record<string, string | number | boolean> }
export interface ResourceMapping { id: number; resource_kind: ResourceKind; local_id: number; external_id: string; scope_zone_id: number }
export interface BandwidthTier { up_to_mbps: number | null; prices: Prices }
export type LegacyCloudNetwork = { bandwidth_min: number; bandwidth_max: number; bandwidth_step: number; ip_min: number; ip_max: number; bandwidth_prices: Prices; ip_prices: Prices; bandwidth_billing_mode?: 'flat' | 'graduated' | 'volume'; bandwidth_tiers?: BandwidthTier[] }
export interface CloudDraft {
  /** 可用区独立供应配置；缺省时保留历史统一供应关系。 */
  zone_provider_bindings?: { zone_id: number; connection_id: number; parameter_mapping: ProviderParameterRule[] }[]
  schema_version?: '2.0.0'; billing_mode?: 'prepaid'; durations?: DurationOption[]
  /** 显式可用区周期；durations 仅用于缺省此字段的历史配置。 */
  zone_durations?: ZoneDurations[]
  storage_policy?: { default_type_id: number; default_size_gib: number; max_data_disks: number }
  trial_scope?: { zone_ids: number[]; flavor_ids: number[]; line_ids: number[]; image_ids: number[]; disk_type_ids?: number[]; protection_ids?: number[]; max_data_disk_gib?: number; max_system_disk_gib: number; max_data_disks: number; max_egress_mbps: number; max_ingress_mbps: number; max_ip_count: number; concurrent_limit: number; cooldown_hours: number }
  resource_ids: Record<ResourceKind, number[]>; cycles: BillingCycle[]; quantity_limit: number; setup_minor: number
  /** 每台系统盘赠送容量，历史草稿缺省为 0。 */
  system_disk_included_gib?: number
  provider_connection_id: number; price_overrides: Partial<Record<'flavors' | 'disk_types' | 'protections', Record<number, Prices>>>
  delivery_rule_id: number; network_policy_id?: number
  /** 仅历史配置读取；当前配置的网络规则归线路。 */
  network?: LegacyCloudNetwork
  trial_policy: { enabled: boolean; hours: number; limit_per_member: number }
  refund_policy: { enabled: boolean; window_hours: number; prorated: boolean; keep_setup_fee: boolean }
  renewal_policy: { pricing: 'current'; auto_renew_enabled: boolean }
}
export interface CloudOffer { configuration_ready?: boolean; configuration_notice?: string | null; has_unpublished_changes?: boolean; id: number; product_id: number; name: string; code: string; description: string; status: number; revision: number; published_version_id: number | null; draft: CloudDraft; created_at: string; updated_at: string }
export interface ProviderParameterRule { source: string; target: string; transform: 'copy' | 'string' | 'multiply' | 'divide' | 'lookup'; factor: number; lookup: Record<string, string | number> | string; required: boolean }
export interface CloudDeliveryRule { parameter_mapping?: ProviderParameterRule[]; id: number; name: string; description: string; connection_id: number; line_ids: number[]; resource_ids: Partial<Record<ResourceKind, number[]>>; priority: number; status: number; revision: number; updated_at: string }
export type MappingConnection = Pick<ProviderConnection, 'id' | 'name' | 'adapter_catalogs' | 'adapter_available' | 'adapter_accepting' | 'requires_retest'>
export type MappingResource = Pick<ComputeResource, 'id' | 'name' | 'zone_ids' | 'group_id'>
/** 标准交付请求预览只展示参数；不会创建外部实例或传递密码。 */
export type ProvisioningResource = Pick<ComputeResource, 'id' | 'name'> & Partial<ComputeResource> & { external_id: string | null }
export interface DeliveryPreview {
  provider_parameters?: Record<string, unknown>; connection_id: number; adapter_id: string; production_ready: boolean; preview: true
  configuration: { region: ProvisioningResource; zone: ProvisioningResource; line: ProvisioningResource; flavor_group: ProvisioningResource; flavor: ProvisioningResource; image: ProvisioningResource
    system_disk: { type: ProvisioningResource; size_gib: number }; data_disks: { type: ProvisioningResource; size_gib: number }[]
    duration?: { unit: 'month'; count: number }; network: { bandwidth_mbps?: number; egress_mbps?: number; ingress_mbps?: number; ipv4_count: number }; protection: ProvisioningResource | null; login_method: string; billing_cycle: BillingCycle; quantity: number }
}
export interface ComputeInventoryPool { id: number; zone_id: number; flavor_id: number; capacity: number | null; reserved: number; occupied: number; available: number | null; revision: number }
export interface CloudWorkspace {
  product: { id: number; name: string }
  resources: Partial<Record<ResourceKind, { total: number; enabled: number }>>
  delivery_rules: number | null
}
export type CloudOfferSummary = Omit<CloudOffer, 'draft'>
export interface OfferInput { name: string; code: string; description: string; revision: number; draft: CloudDraft }
export interface OfferVersion { id: number; version: number; type_version: string; created_at: string; snapshot_hash: string }
export interface ComputeSelection {
  region_id?: number; zone_id?: number; line_id?: number; flavor_id?: number; image_id?: number; protection_id?: number
  duration_months?: number; billing_mode?: 'prepaid'; flavor_group_id?: number; image_type_id?: number
  billing_cycle?: BillingCycle; quantity?: number; login_method?: string
  system_disk?: { type_id?: number; size_gib?: number }; data_disks?: { type_id?: number; size_gib?: number }[]
  network?: { bandwidth_mbps?: number; ipv4_count?: number; egress_mbps?: number; ingress_mbps?: number; billing_mode?: 'fixed' }
}
export interface PreviewOptions { schema_version?: '2.0.0'; line_network?: LineNetwork | null; durations?: DurationOption[]; storage_policy?: CloudDraft['storage_policy']; options: ResourceData; configuration: ComputeSelection; invalid_fields: Record<string, string>; cycles: BillingCycle[]; network: LegacyCloudNetwork; quantity_limit: number; system_disk_included_gib: number }
export interface PricePreview {
  configuration: ComputeSelection
  currency: string; quantity: number; unit_amount_minor: number; amount_minor: number; subtotal_minor?: number; discount_minor?: number
  unit_breakdown: { key: string; label: string; quantity: number; unit_price_minor: number; amount_minor: number; pricing_mode?: string; recurring?: boolean; subtotal_minor?: number; discount_minor?: number }[]
}
export function newCloudDraft(): CloudDraft {
  return { schema_version: '2.0.0', billing_mode: 'prepaid', zone_durations: [], storage_policy: { default_type_id: 0, default_size_gib: 40, max_data_disks: 8 }, trial_scope: { zone_ids: [], flavor_ids: [], line_ids: [], image_ids: [], disk_type_ids: [], protection_ids: [], max_data_disk_gib: 40, max_system_disk_gib: 40, max_data_disks: 0, max_egress_mbps: 5, max_ingress_mbps: 100, max_ip_count: 1, concurrent_limit: 1, cooldown_hours: 0 }, resource_ids: Object.fromEntries(Object.keys(RESOURCE_LABELS).map((kind) => [kind, []])) as Record<ResourceKind, number[]>,
    cycles: ['month'], quantity_limit: 10, setup_minor: 0, system_disk_included_gib: 0, provider_connection_id: 0, delivery_rule_id: 0, price_overrides: {},
    trial_policy: { enabled: false, hours: 24, limit_per_member: 1 }, refund_policy: { enabled: false, window_hours: 168, prorated: true, keep_setup_fee: true }, renewal_policy: { pricing: 'current', auto_renew_enabled: false } }
}
/** 将服务端字段与组合错误转换为可读提示。 */
export function cloudError(error: unknown): string {
  if (error instanceof ApiRequestError && error.data && typeof error.data === 'object' && 'errors' in error.data) {
    const errors = (error.data as { errors: unknown }).errors
    if (errors && typeof errors === 'object') {
      const messages = Object.values(errors).flatMap((value: unknown) => typeof value === 'string' ? [value] : Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : [])
      if (messages.length) return [...new Set(messages)].join('；')
    }
  }
  return error instanceof Error ? error.message : '操作失败，请稍后重试'
}
export const cloudProductApi = {
  section: (section: 'deployment' | 'specification' | 'network' | 'storage' | 'purchase') => request<{ configuration: CloudOffer | null; choices: CloudOfferSummary[]; default_configuration_id: number | null; references: { resources: ResourceData; connections: ProviderConnectionOption[]; delivery_rules: CloudDeliveryRule[] }; inventory: ComputeInventoryPool[] }>({ url: `/cloud-servers/sections/${section}` }),
  configuration: () => request<{ configuration: CloudOffer | null; choices: CloudOfferSummary[]; default_configuration_id: number | null }>({ url: '/cloud-servers/configuration' }),
  saveConfiguration: (id: number | null, expectedId: number | null, data: OfferInput) => request<CloudOffer>({ url: '/cloud-servers/configuration/live', method: 'PUT', data: { ...data, configuration_id: id, expected_configuration_id: expectedId } }),
  inventory: () => request<ComputeInventoryPool[]>({ url: '/cloud-servers/inventory' }),
  saveInventory: (data: { zone_id: number; flavor_id: number; capacity: number | null; revision: number }) => request<ComputeInventoryPool>({ url: '/cloud-servers/inventory', method: 'PUT', data }),
  resources: () => request<ResourceData>({ url: '/compute-resources' }),
  saveResource: (kind: ResourceKind, id: number | null, data: Record<string, unknown>) => request<ComputeResource>({ url: `/compute-resources/${kind}${id ? `/${id}` : ''}`, method: id ? 'PUT' : 'POST', data }),
  connections: () => request<ProviderConnection[]>({ url: '/provider-connections' }),
  adapters: () => request<ProviderAdapterDefinition[]>({ url: '/provider-connections/adapters' }),
  saveConnection: (id: number | null, data: ConnectionInput) => request<ProviderConnection>({ url: `/provider-connections${id ? `/${id}` : ''}`, method: id ? 'PUT' : 'POST', data }),
  connectionStatus: (row: Pick<ProviderConnection, 'id' | 'revision'>, status: number) => request<ProviderConnection>({ url: `/provider-connections/${row.id}/status`, method: 'PATCH', data: { revision: row.revision, status } }),
  testConnection: (id: number) => request<ProviderConnection>({ url: `/provider-connections/${id}/test`, method: 'POST' }),
  catalog: (id: number, kind: string, page: number, perPage: number) => request<{ data: CatalogEntry[]; total: number }>({ url: `/provider-connections/${id}/catalog`, method: 'POST', data: { kind, page, per_page: perPage } }),
  mappings: (id: number) => request<ResourceMapping[]>({ url: `/provider-connections/${id}/mappings` }),
  mappingReferences: () => request<Partial<Record<ResourceKind, MappingResource[]>>>({ url: '/provider-connections/references' }),
  map: (id: number, kind: ResourceKind, localId: number, externalId: string, scopeZoneId = 0) => request({ url: `/provider-connections/${id}/mappings`, method: 'POST', data: { resource_kind: kind, local_id: localId, external_id: externalId, scope_zone_id: scopeZoneId } }),
  offer: (id: number) => request<CloudOffer>({ url: `/cloud-products/${id}` }),
  status: (row: Pick<CloudOffer, 'id' | 'revision'>, status: number) => request<CloudOffer>({ url: `/cloud-products/${row.id}/status`, method: 'PATCH', data: { revision: row.revision, status } }),
  versions: (id: number) => request<OfferVersion[]>({ url: `/cloud-products/${id}/versions` }),
  options: (id: number, selection: ComputeSelection, versionId?: number) => request<PreviewOptions>({ url: `/cloud-products/${id}/preview`, method: 'POST', data: { mode: 'options', selection, version_id: versionId } }),
  price: (id: number, selection: ComputeSelection, versionId?: number) => request<PricePreview>({ url: `/cloud-products/${id}/preview`, method: 'POST', data: { mode: 'price', selection, version_id: versionId } }),
  deliveryPreview: (id: number, selection: ComputeSelection, versionId?: number) => request<DeliveryPreview>({ url: `/cloud-products/${id}/preview`, method: 'POST', data: { mode: 'delivery', selection, version_id: versionId } }),
}
