import { useEffect, useState } from 'react'
import { Alert, Button, Drawer, Dropdown, Menu, Message, Modal, Select, Space, Spin, Typography } from '@arco-design/web-react'
import { IconEye, IconMore, IconSave } from '@arco-design/web-react/icon'
import { Prompt, useHistory, useLocation } from 'react-router-dom'
import { useAdminAuth } from '../auth/AuthContext'
import { cloudError, cloudProductApi, type ResourceKind } from '../api/cloudProductApi'
import ComputeConfigurationPreview from '../components/ComputeConfigurationPreview'
import ProviderMappingsPage from '../components/ProviderMappingsPage'
import { CLOUD_AREAS, CloudWorkspaceContext, type CloudArea } from '../components/cloud-server/workspace'
import useWorkspaceConfiguration from '../components/cloud-server/useWorkspaceConfiguration'
import ConfigurationGuide from '../components/cloud-server/ConfigurationGuide'
import ConfigurationHistory from '../components/cloud-server/ConfigurationHistory'
import SpecificationPanel from '../components/cloud-server/SpecificationPanel'
import DeploymentPanel from '../components/cloud-server/DeploymentPanel'
import NetworkPanel from '../components/cloud-server/NetworkPanel'
import StoragePanel from '../components/cloud-server/StoragePanel'
import PurchaseSettings from '../components/cloud-server/PurchaseSettings'
import { cloudSectionFromPath, cloudSectionPath, cloudSectionPermission } from '../components/cloud-server/navigation'
import './cloud-products.css'

const oldAreas: Record<string, { area: CloudArea; resource?: ResourceKind; manage?: string }> = {
  overview: { area: 'deployment' }, regions: { area: 'deployment', manage: 'regions' }, zones: { area: 'deployment', manage: 'zones' },
  flavors: { area: 'specification', resource: 'flavors' }, lines: { area: 'network', resource: 'lines' },
  images: { area: 'storage', resource: 'images' }, offers: { area: 'purchase' }, delivery: { area: 'deployment' },
}

/** 五个页面共用一份配置，不提供销售方案新建列表。 */
export default function ProductManagementPage() {
  const { permissions } = useAdminAuth()
  const history = useHistory()
  const location = useLocation()
  const params = new URLSearchParams(location.search)
  const hasLegacyTab = params.has('tab')
  const legacySpecification = !cloudSectionFromPath(location.pathname) && ['flavors', 'flavor_groups'].includes(params.get('resource') ?? '')
  const requested = cloudSectionFromPath(location.pathname) ?? (legacySpecification ? 'specification' : params.get('tab') ?? 'deployment')
  const active: CloudArea = CLOUD_AREAS.find(area => area.key === requested)?.key ?? oldAreas[requested]?.area ?? 'deployment'
  const areas = CLOUD_AREAS.filter(area => permissions.includes(cloudSectionPermission(area.key)) && permissions.includes(area.key === 'purchase' ? 'admin.cloud-products.view' : 'admin.compute-resources.view'))
  const selectedArea = areas.some(area => area.key === active) ? active : areas[0]?.key
  const model = useWorkspaceConfiguration(selectedArea)
  const state = model.state
  const [busy, setBusy] = useState(false)
  const [preview, setPreview] = useState<{ versionId?: number }>()
  const [showHistory, setShowHistory] = useState(false)
  const [mappingReferences, setMappingReferences] = useState<Awaited<ReturnType<typeof cloudProductApi.connections>>>([])
  const [mappingError, setMappingError] = useState('')
  const [mappingLoading, setMappingLoading] = useState(false)
  const mapping = params.get('mapping')
  const mappingId = mapping && /^[1-9]\d*$/.test(mapping) ? Number(mapping) : undefined
  const canPreview = permissions.includes('admin.cloud-products.preview')
  const canEdit = state.offer ? permissions.includes('admin.cloud-products.update') : permissions.includes('admin.cloud-products.create')
  const isPurchase = selectedArea === 'purchase'
  useEffect(() => {
    if (!selectedArea || location.pathname === cloudSectionPath(selectedArea) && !hasLegacyTab) return
    const next = new URLSearchParams(location.search)
    next.delete('tab')
    const previous = oldAreas[requested]
    if (previous?.resource) next.set('resource', previous.resource)
    if (previous?.manage) next.set('manage', previous.manage)
    if (['offers', 'delivery', 'overview', 'regions', 'zones'].includes(requested)) next.delete('edit')
    if (requested === 'delivery') next.delete('mapping')
    history.replace({ ...location, pathname: cloudSectionPath(selectedArea), search: next.toString() })
  }, [requested, selectedArea, hasLegacyTab, location, history])
  useEffect(() => {
    if (!mappingId) return
    let active = true
    if (!permissions.includes('admin.provider-connections.view')) return
    void Promise.resolve().then(() => { if (active) { setMappingLoading(true); setMappingError('') } })
    cloudProductApi.connections().then(rows => { if (active) setMappingReferences(rows) }).catch(e => { if (active) setMappingError(cloudError(e)) }).finally(() => { if (active) setMappingLoading(false) })
    return () => { active = false }
  }, [mappingId, permissions])
  const save = async () => {
    try { const saved = await model.save(); if (saved.configuration_ready === false) Message.warning(`配置已保存，尚未生效：${saved.configuration_notice ?? '请完善配置'}`); else Message.success(saved.status === 1 ? '配置已保存并生效' : '配置已保存（当前已停用）') } catch (e) { Message.error(cloudError(e)) }
  }
  const openPreview = async () => {
    if (!state.offer || model.dirty) {
      try { await model.save() } catch (e) { Message.error(cloudError(e)); return }
    }
    setPreview({})
  }
  const choose = (id: number) => {
    const navigate = () => { const next = new URLSearchParams(location.search); next.set('configuration', String(id)); next.delete('edit'); next.delete('mapping'); history.push({ ...location, search: next.toString() }) }
    if (model.dirty) Modal.confirm({ title: '切换配置？', content: '当前尚未保存的购买范围及规则会丢失，已保存资源不受影响。', onOk: navigate }); else navigate()
  }
  const legacy = Boolean(state.offer && state.draft.schema_version !== '2.0.0')
  return <CloudWorkspaceContext.Provider value={state}>
    <Prompt when={model.dirty} message={next => next.pathname === location.pathname || areas.some(area => cloudSectionPath(area.key) === next.pathname) && new URLSearchParams(next.search).get('configuration') === params.get('configuration') ? true : '云服务器配置尚未保存，确定离开？'} />
    <section className="page-card cloud-workspace">
      {model.loading ? <Spin style={{ display: 'block', padding: 40 }} /> : model.error ? <Alert type="error" content={model.error} action={<Button onClick={() => void model.reload()}>重试</Button>} />
        : <>
          <div className="cloud-workspace-heading">
            <div><Typography.Title heading={5} style={{ margin: 0 }}>{CLOUD_AREAS.find(area => area.key === selectedArea)?.title ?? '云服务器配置'}</Typography.Title>
              {isPurchase && <Typography.Text type="secondary">{model.dirty ? '有未保存的配置' : !state.offer ? '尚未保存配置' : state.offer.has_unpublished_changes || !state.offer.published_version_id ? '配置待完善' : `${state.offer.status ? '已生效' : '已停用'}${model.resourceChanged ? ' · 资源已更新，请保存配置' : ''}`}</Typography.Text>}
            </div>
            {isPurchase && <Space wrap className="cloud-workspace-actions">
              {canPreview && <Button icon={<IconEye />} disabled={busy || model.saving || !state.offer && !state.editable || model.dirty && !state.editable} onClick={() => void openPreview()}>购买预览</Button>}
              {canEdit && model.canView && <Button type="primary" icon={<IconSave />} loading={model.saving} disabled={!state.editable || busy} onClick={() => void save()}>保存配置</Button>}
              {state.offer && <Dropdown trigger="click" droplist={<Menu onClickMenuItem={key => {
                if (key === 'history') setShowHistory(true)
                if (key === 'disable' && state.offer) Modal.confirm({ title: '停用当前配置？', onOk: async () => { setBusy(true); try { model.accept(await cloudProductApi.status(state.offer!, 0), true); Message.success('配置已停用') } catch (e) { Message.error(cloudError(e)) } finally { setBusy(false) } } })
              }}>
                {model.canView && <Menu.Item key="history">配置记录</Menu.Item>}
                {permissions.includes('admin.cloud-products.update') && state.offer.status === 1 && <Menu.Item key="disable">停用配置</Menu.Item>}
              </Menu>}><Button icon={<IconMore />} aria-label="配置辅助操作" disabled={busy || model.saving} /></Dropdown>}
            </Space>}
          </div>
          {state.offer?.configuration_ready === false && state.offer.configuration_notice && <Alert type="warning" content={`配置已保存，尚未生效：${state.offer.configuration_notice}`} style={{marginBottom: 20}} />}
          {model.choices.length > 1 && <div className="cloud-existing-configuration"><Typography.Text>已有多份配置，请明确选择；保存后作为默认入口。</Typography.Text><Select aria-label="选择已有配置" placeholder="请选择需要使用的配置" value={state.offer?.id} onChange={choose} options={model.choices.map(row => ({ value: row.id, label: row.name }))} style={{ width: 260, maxWidth: '100%' }} /></div>}
          {legacy && <Alert type="warning" content="当前配置沿用旧计费方式，历史版本继续保留。转为包年包月后，请检查每条线路的上下行、IP 及月价。" action={canEdit ? <Button onClick={() => Modal.confirm({ title: '转为包年包月配置？', content: '保留现有资源与历史配置记录；新周期按月计费，旧带宽不会自动转换为上下行，请检查线路。转换后请检查并保存配置。', onOk: model.convertLegacy })}>转为包年包月</Button> : undefined} style={{ marginBottom: 20 }} />}
          {selectedArea ? <div className="cloud-workspace-panel">
            {selectedArea === 'deployment' ? <DeploymentPanel /> : selectedArea === 'specification' ? <SpecificationPanel /> : selectedArea === 'network' ? <NetworkPanel /> : selectedArea === 'storage' ? <StoragePanel /> : legacy ? <Alert content="请先明确转换计费方式，旧配置不会自动调整。" /> : <PurchaseSettings />}
          </div> : <Alert content="当前账号没有云服务器配置页面查看权限，请联系管理员授权。" />}
          {!legacy && model.canView && (state.offer || model.choices.length === 0) && <ConfigurationGuide />}
          {!isPurchase && canEdit && model.canView && (model.dirty || model.resourceChanged) && <div className="cloud-workspace-savebar" role="region" aria-label="待保存的配置更改">
            <Typography.Text type="secondary">{model.resourceChanged ? '资源已保存，请更新购买配置。' : '有未保存的配置更改。'}</Typography.Text>
            <Button type="primary" icon={<IconSave />} loading={model.saving} disabled={!state.editable || busy} onClick={() => void save()}>保存更改</Button>
          </div>}
        </>}
    </section>
    {mappingId && <ProviderMappingsPage mode="drawer" connection={mappingReferences.find(row => row.id === mappingId)} loading={mappingLoading} error={permissions.includes('admin.provider-connections.view') ? mappingError : '没有资源供应商查看权限'} onBack={() => { const next = new URLSearchParams(location.search); next.delete('mapping'); history.replace({ ...location, search: next.toString() }) }} />}
    {showHistory && state.offer && <ConfigurationHistory onClose={() => setShowHistory(false)} onPreview={canPreview ? versionId => { setShowHistory(false); setPreview({ versionId }) } : undefined} />}
    <Drawer visible={Boolean(preview)} title={preview?.versionId ? '购买预览 · 历史配置' : '购买预览 · 当前配置'} width="min(920px, calc(100vw - 24px))" footer={null} onCancel={() => setPreview(undefined)}>
      {preview && state.offer && <ComputeConfigurationPreview key={`${state.offer.id}:${preview.versionId ?? state.offer.revision}`} offerId={state.offer.id} versionId={preview.versionId} />}
    </Drawer>
  </CloudWorkspaceContext.Provider>
}
