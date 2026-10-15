import { useCallback, useEffect, useRef, useState } from 'react'
import { useHistory, useLocation } from 'react-router-dom'
import { cloudError, cloudProductApi, newCloudDraft, RESOURCE_LABELS, type CloudDraft, type CloudOffer, type CloudOfferSummary, type ComputeInventoryPool, type ComputeResource, type ResourceData, type ResourceKind } from '../../api/cloudProductApi'
import { useAdminAuth } from '../../auth/AuthContext'
import { cloudSectionPath, cloudSectionPermission } from './navigation'
import { resourceZones, selectResource, withZoneDurations, type CloudArea, type CloudWorkspaceState } from './workspace'

const emptyResources = (): ResourceData => Object.fromEntries(Object.keys(RESOURCE_LABELS).map(key => [key, []])) as ResourceData
type References = Awaited<ReturnType<typeof cloudProductApi.references>>

/** 一份草稿跨分区保存，切换页面校验访问权限并保留输入；默认配置以服务端指针为准。 */
export default function useWorkspaceConfiguration(area?: CloudArea) {
  const { permissions, user } = useAdminAuth()
  const history = useHistory()
  const location = useLocation()
  const params = new URLSearchParams(location.search)
  const requestedId = params.get('configuration')
  const chosenId = requestedId && /^[1-9]\d*$/.test(requestedId) ? Number(requestedId) : undefined
  const canView = permissions.includes('admin.cloud-products.view')
  const canResources = permissions.includes('admin.compute-resources.view')
  const [offer, setOffer] = useState<CloudOffer | null>(null)
  const [draft, setDraft] = useState<CloudDraft>(newCloudDraft)
  const [references, setReferences] = useState<References>({ resources: emptyResources(), connections: [], delivery_rules: [] })
  const [choices, setChoices] = useState<CloudOfferSummary[]>([])
  const [defaultId, setDefaultId] = useState<number | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [dirty, setDirty] = useState(false)
  const [saving, setSaving] = useState(false)
  const [resourceChanged, setResourceChanged] = useState(false)
  const [inventory, setInventory] = useState<ComputeInventoryPool[]>([])
  const request = useRef(0)
  const ready = useRef(false)
  const loadedContext = useRef<string>()
  const contextKey = `${user?.id ?? ''}:${chosenId ?? 'default'}:${permissions.join(',')}`
  const reload = useCallback(async () => {
    const sequence = ++request.current
    ready.current = false
    setLoading(true); setError('')
    try {
      if (!area) throw new Error('当前账号没有云服务器配置页面查看权限')
      const { references: refs, inventory: pools, ...config } = await cloudProductApi.section(area)
      const preserve = loadedContext.current === contextKey
      const selected = preserve ? null : chosenId && canView ? await cloudProductApi.offer(chosenId) : config.configuration
      if (sequence !== request.current) return
      setReferences(refs); setInventory(pools)
      if (preserve) { ready.current = true; return }
      setChoices(config.choices); setDefaultId(config.default_configuration_id); setOffer(selected)
      const initial = selected?.draft ?? newCloudDraft()
      if (!selected && config.choices.length === 0) {
        for (const kind of Object.keys(RESOURCE_LABELS) as ResourceKind[]) initial.resource_ids[kind] = refs.resources[kind].filter(row => row.status === 1).map(row => row.id)
        initial.resource_ids.network_policies = []
      }
      setDraft(withZoneDurations(initial)); setDirty(false); setResourceChanged(false); loadedContext.current = contextKey; ready.current = true
    } catch (e) { if (sequence === request.current) setError(cloudError(e)) }
    finally { if (sequence === request.current) setLoading(false) }
  }, [area, contextKey, canView, chosenId, setReferences, setOffer, setDraft, setDirty, setResourceChanged, setInventory])
  const invalidate = useCallback(() => { request.current++; ready.current = false }, [])
  useEffect(() => { let active = true; void Promise.resolve().then(() => { if (active) void reload() }); return () => { active = false; invalidate() } }, [reload, invalidate])
  useEffect(() => {
    if (!dirty) return
    const warn = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])
  const editable = !loading && !saving && !error && canView && (offer ? permissions.includes('admin.cloud-products.update') : choices.length === 0 && permissions.includes('admin.cloud-products.create')) && draft.schema_version === '2.0.0'
  const changeDraft = (next: CloudDraft) => { if (editable) { setDraft(withZoneDurations(next)); setDirty(true) } }
  const zoneParam = Number(params.get('zone'))
  const zoneId = references.resources.zones.some(row => row.id === zoneParam) ? zoneParam : references.resources.zones[0]?.id
  const setZone = (id: number) => {
    const next = new URLSearchParams(location.search); next.set('zone', String(id)); next.delete('edit'); next.delete('region'); next.delete('zone-config'); next.delete('page')
    history.push({ ...location, search: next.toString() })
  }
  const canNavigate = (area: CloudArea) => permissions.includes(cloudSectionPermission(area)) && permissions.includes(area === 'purchase' ? 'admin.cloud-products.view' : 'admin.compute-resources.view')
  const navigate = (area: CloudArea, resource?: ResourceKind, zone?: number) => {
    const next = new URLSearchParams(location.search)
    if (!canNavigate(area)) return
    next.delete('tab')
    if (zone) next.set('zone', String(zone))
    if (resource) next.set('resource', resource); else next.delete('resource')
    for (const key of ['edit', 'mapping', 'section', 'manage', 'zone-config', 'region']) next.delete(key)
    history.push({ ...location, pathname: cloudSectionPath(area), search: next.toString() })
  }
  const resourceSaved = async (kind: ResourceKind, row: ComputeResource, created: boolean) => {
    const resources = { ...references.resources, [kind]: created ? [...references.resources[kind], row] : references.resources[kind].map(value => value.id === row.id ? row : value) }
    setReferences(current => ({ ...current, resources }))
    setResourceChanged(true)
    if (!editable) return
    setDraft(current => {
      let next = current
      if (created && row.status === 1 && !['flavor_groups', 'image_types', 'network_policies'].includes(kind)) {
        const applicable = ['regions', 'zones'].includes(kind) || resourceZones(row).some(zone => current.resource_ids.zones.includes(zone)) || kind === 'protections' && (row.line_ids ?? []).some(line => current.resource_ids.lines.includes(line))
        if (applicable) next = selectResource(current, resources, kind, row.id, true)
      } else if (row.status !== 1) next = selectResource(current, resources, kind, row.id, false)
      // 旧配置的覆盖价只在用户实际编辑该资源价格后移除，历史版本与其他配置不变。
      if (['flavors', 'disk_types', 'protections'].includes(kind)) {
        const priceKind = kind as 'flavors' | 'disk_types' | 'protections'
        const overrides = { ...next.price_overrides[priceKind] }
        delete overrides[row.id]
        next = { ...next, price_overrides: { ...next.price_overrides, [priceKind]: overrides } }
      }
      return withZoneDurations(next)
    })
    setDirty(true)
  }
  const save = async (): Promise<CloudOffer> => {
    if (!ready.current || loading || saving || error || !canView || !offer && choices.length > 0) throw new Error('请先选择并加载云服务器配置')
    setSaving(true)
    try {
      const saved = await cloudProductApi.saveConfiguration(offer?.id ?? null, defaultId, {
        name: offer?.name ?? '云服务器', code: offer?.code ?? 'cloud-server-default', description: offer?.description ?? '', revision: offer?.revision ?? 0, draft,
      })
      setOffer(saved); setDefaultId(saved.id); setDraft(withZoneDurations(saved.draft)); setDirty(false); setResourceChanged(false)
      setChoices(current => current.some(row => row.id === saved.id) ? current.map(row => row.id === saved.id ? saved : row) : [...current, saved])
      return saved
    } finally { setSaving(false) }
  }
  const state: CloudWorkspaceState = { offer, draft, resources: references.resources, connections: references.connections, deliveryRules: references.delivery_rules,
    inventory, inventorySaved: pool => setInventory(current => [...current.filter(row => row.zone_id !== pool.zone_id || row.flavor_id !== pool.flavor_id), pool]),
    refreshResources: async () => {
      if (!area) return
      const sequence = request.current
      const fresh = await cloudProductApi.section(area)
      if (sequence === request.current) { setReferences(fresh.references); setInventory(fresh.inventory) }
    },
    editable, zoneId, setZone, changeDraft, select: (kind, id, included) => changeDraft(selectResource(draft, references.resources, kind, id, included)), resourceSaved, canNavigate, navigate }
  return { state, choices, loading, error, dirty, saving, resourceChanged, canView, canResources, save, reload,
    accept: (saved: CloudOffer, preserveDraft = false) => { setOffer(saved); if (!preserveDraft) { setDraft(withZoneDurations(saved.draft)); setResourceChanged(false) } },
    convertLegacy: () => { setDraft(withZoneDurations({ ...newCloudDraft(), ...draft, schema_version: '2.0.0', billing_mode: 'prepaid', cycles: ['month'], zone_durations: [], storage_policy: newCloudDraft().storage_policy, network_policy_id: 0, resource_ids: { ...draft.resource_ids, network_policies: [] } })); setDirty(true) },
  }
}
