import ZoneCascader from '../components/cloud-server/ZoneCascader'
import SectionTitle from '../components/SectionTitle'
import { applyClientTableQuery } from '../utils/clientTableQuery'
import useTableQuery from '../hooks/useTableQuery'
import { IconRefresh, IconPlus } from '@arco-design/web-react/icon'
import TableQueryForm from '../components/TableQueryForm'
import FormDialog from '../components/FormDialog'
import useEditorLocation from '../hooks/useEditorLocation'
import { useCallback, useContext, useEffect, useState } from 'react'
import { Alert, Button, Checkbox, Form, Input, InputNumber, Message, Radio, Select, Spin, Switch, Table, Typography } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { cloudProductApi, cloudError, CYCLE_LABELS, RESOURCE_LABELS, type ResourceKind, type ResourceData, type ComputeResource } from '../api/cloudProductApi'
import { useAdminAuth } from '../auth/AuthContext'
import EnabledStatus from '../components/EnabledStatus'
import TableActions from '../components/TableActions'
import PriceInput from '../components/PriceInput'
import ComputeInventoryEditor from '../components/ComputeInventoryEditor'
import LineNetworkEditor from '../components/LineNetworkEditor'
import { newLineNetwork } from '../api/cloudProductApi'
import { tableCellText } from '../utils/tableCellText'
import { TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination'
import { CloudWorkspaceContext, resourceZones } from '../components/cloud-server/workspace'
import { minorToYuanText } from '../utils/money'
import './cloud-products.css'

const relationships: Partial<Record<ResourceKind, [string, ResourceKind, string][]>> = {
  zones: [['region_id', 'regions', '地域']], lines: [['zone_ids', 'zones', '可用区']], flavors: [['zone_ids', 'zones', '可用区'], ['group_id', 'flavor_groups', '规格组']],
  images: [['zone_ids', 'zones', '可用区'], ['type_id', 'image_types', '镜像类型']], disk_types: [['zone_ids', 'zones', '可用区']], protections: [['line_ids', 'lines', '线路']],
}
function resourceDefaults() {
  return {
    status: 1, sort: 0, description: '', country_code: '', test_ip: '', cpu: 2, memory_mib: 2048, architecture: 'x86_64',
    min_cpu: 1, min_memory_mib: 128, min_disk_gib: 20, login_methods: ['password'],
    cpu_model: '', base_clock_mhz: null, boost_clock_mhz: null,
    read_mbps: null, write_mbps: null, read_iops: null, write_iops: null,
    min_gib: 20, max_gib: 2048, step_gib: 1, max_count: 8,
    bandwidth_min: 1, bandwidth_max: 100, bandwidth_step: 1, ip_min: 1, ip_max: 1,
    default_username: 'root', default_port: 22, capacity_gbps: 0, icon_url: '', network_configuration: newLineNetwork(),
    prices: { hour: 0, day: 0, month: 0, year: 0 },
  }
}

/** 管理独立云服务器配置资源，不自动覆盖销售方案。 */
export default function ComputeResourcesPage({ kinds = Object.keys(RESOURCE_LABELS) as ResourceKind[], embedded = false, workspace = false, scopeZoneId, scopeLineId, scopeRegionId, onConfigureZone, editorMode = 'drawer', editorEnabled = true }: { kinds?: ResourceKind[]; embedded?: boolean; workspace?: boolean; scopeZoneId?: number; scopeLineId?: number; scopeRegionId?: number; onConfigureZone?: (row: ComputeResource) => void; editorMode?: 'drawer' | 'modal'; editorEnabled?: boolean }) {
  const context = useContext(CloudWorkspaceContext)
  const shared = workspace ? context : null
  const isShared = Boolean(shared)
  const searchFields = shared ? [] : [
    { key: 'name', label: '名称', maxLength: 128 },
    { key: 'code', label: '标识', maxLength: 128 },
  ]
  const { queryParams, sortColumn, filterColumn, onTableChange, fields, draftFields, setField, discard, page, pageSize, setPage, setPageSize, search, reset } = useTableQuery({ statusValues: [0, 1], sortFields: ['sort'], textFields: searchFields.map(field => field.key) })
  const { permissions } = useAdminAuth()
  const { editor: requestedEditor, openEditor, closeEditor } = useEditorLocation()
  const editor = editorEnabled ? requestedEditor : null
  const [editorReady, setEditorReady] = useState<number | 'new' | null>(null)
  const can = (action: string) => permissions.includes(`admin.compute-resources.${action}`) && (!shared || shared.draft.schema_version === '2.0.0')
  const [localResources, setResources] = useState<ResourceData>()
  const resources = shared?.resources ?? localResources
  const [kind, setKind] = useState<ResourceKind>(kinds[0])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [stockFlavor, setStockFlavor] = useState<ComputeResource | null>(null)
  const [editing, setEditing] = useState<ComputeResource | null>(null)
  const [saving, setSaving] = useState(false)
  const [form] = Form.useForm()
  const load = useCallback(async () => {
    setLoading(true); setError('')
    try { if (!isShared) setResources(await cloudProductApi.resources()) } catch (e) { setError(cloudError(e)) } finally { setLoading(false) }
  }, [isShared])
  useEffect(() => { let active = true; void Promise.resolve().then(() => { if (active) void load() }); return () => { active = false } }, [load])
  const formValues = useCallback((row: ComputeResource | null | undefined) => {
    if (row) {
      const priceKind = kind as 'flavors' | 'disk_types' | 'protections'
      return { ...row, prices: { ...row.prices, ...(shared?.draft.price_overrides[priceKind]?.[row.id] ?? {}) },
        ...(kind === 'lines' ? { network_configuration: row.network_configuration || newLineNetwork() } : {}) }
    }
    return { ...resourceDefaults(), ...(scopeZoneId ? { zone_ids: [scopeZoneId] } : shared ? { zone_ids: shared.draft.resource_ids.zones } : {}),
      ...(scopeLineId ? { line_ids: [scopeLineId] } : {}),
      ...(shared && kind === 'zones' ? { region_id: scopeRegionId ?? shared.resources.regions[0]?.id } : {}) }
  }, [kind, scopeZoneId, scopeLineId, scopeRegionId, shared])
  const edit = (row: ComputeResource | null) => { setEditorReady(null); openEditor(row?.id) }
  const resourceEditor = editor !== null
  const detailedForm = ['disk_types', 'lines'].includes(kind)
  const canEditResource = editor === 'new' ? can('create') : can('update')
  const editorRow = typeof editor === 'number' ? resources?.[kind].find((row) => row.id === editor) : null
  const editorError = !canEditResource ? '没有资源编辑权限' : error || (resources && typeof editor === 'number' && !editorRow ? '资源不存在或已删除' : '')
  useEffect(() => {
    let active = true
    if (!resourceEditor) {
      void Promise.resolve().then(() => { if (active) setEditorReady(null) })
      return () => { active = false }
    }
    if (!resources || editorError || editorReady === editor) return
    void Promise.resolve().then(() => {
      if (!active) return
      const row = typeof editor === 'number' ? resources[kind].find((item) => item.id === editor) : null
      form.resetFields()
      form.setFieldsValue(formValues(row))
      setEditing(row ?? null)
      setEditorReady(editor)
    })
    return () => { active = false }
  }, [resourceEditor, editor, editorReady, resources, editorError, form, kind, formValues])
  const save = async () => {
    if (saving || !can(editing ? 'update' : 'create') || resourceEditor && (editorReady !== editor || editorError)) return
    let values: Record<string, unknown>
    try { values = await form.validate() as Record<string, unknown> } catch { return }
    setSaving(true)
    try { const saved = await cloudProductApi.saveResource(kind, editing?.id ?? null, values); if (shared) await shared.resourceSaved(kind, saved, !editing); closeEditor(); Message.success(shared?.editable ? '资源已保存；请保存更改以更新购买配置' : '已保存'); await load() }
    catch (e) { Message.error(cloudError(e)) } finally { setSaving(false) }
  }
  const columns: TableColumnProps<ComputeResource>[] = [
    { title: '名称', dataIndex: 'name', width: shared ? 140 : 180, ellipsis: true },
    ...(!shared ? [{ title: '标识', dataIndex: 'code', width: 150, ellipsis: true }] : []),
    ...(kind === 'regions' ? [{ title: '国家代码', dataIndex: 'country_code', width: 100, render: tableCellText }] : []),
    ...(kind === 'lines' ? [{ title: '测试 IP', dataIndex: 'test_ip', width: 180, ellipsis: true, render: tableCellText }] : []),
    ...(relationships[kind] || []).filter(([field]) => !(scopeZoneId && field === 'zone_ids' || scopeLineId && field === 'line_ids' || scopeRegionId && field === 'region_id')).map(([field, parent, label]) => ({ title: label, width: shared ? 145 : 180, ellipsis: true, render: (_: unknown, row: ComputeResource) => {
      const value = row[field as keyof ComputeResource]; const ids = Array.isArray(value) ? value : [value]
      return tableCellText(resources?.[parent].filter((item) => ids.includes(item.id)).map((item) => shared && parent === 'flavor_groups' ? [item.name, item.cpu_model, item.base_clock_mhz ? `${item.base_clock_mhz / 1000} GHz` : ''].filter(Boolean).join(' · ') : item.name).join('、'))
    } })),
    ...(shared && kind === 'zones' ? [{ title: '提供者', width: 180, ellipsis: true, render: (_: unknown, row: ComputeResource) => {
      const inherited = shared.deliveryRules.find(rule => rule.id === shared.draft.delivery_rule_id)
      const id = shared.draft.zone_provider_bindings?.find(binding => binding.zone_id === row.id)?.connection_id ?? inherited?.connection_id ?? shared.draft.provider_connection_id
      return id ? tableCellText(shared.connections.find(connection => connection.id === id)?.name) : '未配置'
    } }] : []),
    ...(kind === 'flavors' ? [{ title: '配置', width: shared ? 130 : 150, render: (_: unknown, row: ComputeResource) => `${row.cpu} 核 / ${row.memory_mib} MiB` }] : []),
    ...(shared && kind === 'flavors' && scopeZoneId ? [{ title: '可售库存', width: 100, render: (_: unknown, row: ComputeResource) => { const pool = shared.inventory.find(item => item.zone_id === scopeZoneId && item.flavor_id === row.id); return !pool ? '尚未配置' : pool.available === null ? '不限' : `${pool.available} 台` } }] : []),
    ...(kind === 'flavor_groups' ? [{ title: 'CPU 型号', dataIndex: 'cpu_model', width: 200, ellipsis: true, render: tableCellText },
      { title: '主频 / 睿频', width: 170, render: (_: unknown, row: ComputeResource) => `${row.base_clock_mhz ? `${(row.base_clock_mhz / 1000).toFixed(2)} GHz` : '-'} / ${row.boost_clock_mhz ? `${(row.boost_clock_mhz / 1000).toFixed(2)} GHz` : '-'}` }] : []),
    ...(shared && kind === 'lines' ? [{ title: '默认上行 / 下行', width: 180, render: (_: unknown, row: ComputeResource) => row.network_configuration ? `${row.network_configuration.egress.default} / ${row.network_configuration.ingress.default} Mbps` : '待配置' }, { title: '赠送 IP', width: 90, render: (_: unknown, row: ComputeResource) => row.network_configuration?.ip.included ?? '待配置' }] : []),
    ...(kind === 'images' ? [{ title: '最低配置', width: 240, ellipsis: true, render: (_: unknown, row: ComputeResource) => `${row.min_cpu} 核 / ${row.min_memory_mib} MiB / ${row.min_disk_gib} GiB 系统盘` }] : []),
    ...(kind === 'disk_types' ? [{ title: '读 / 写速度（MB/s）', width: 190, render: (_: unknown, row: ComputeResource) => `${tableCellText(row.read_mbps)} / ${tableCellText(row.write_mbps)}` },
      { title: '读 / 写 IOPS', width: 170, render: (_: unknown, row: ComputeResource) => `${tableCellText(row.read_iops)} / ${tableCellText(row.write_iops)}` }] : []),
    ...(kind === 'network_policies' ? [{ title: '带宽（Mbps）', width: 170, render: (_: unknown, row: ComputeResource) => `${row.bandwidth_min}–${row.bandwidth_max}，步长 ${row.bandwidth_step}` },
      { title: 'IPv4 数量', width: 120, render: (_: unknown, row: ComputeResource) => `${row.ip_min}–${row.ip_max}` }] : []),
    ...(shared && ['flavors', 'disk_types', 'protections'].includes(kind) ? [{ title: kind === 'disk_types' ? '容量月价（元 / GiB）' : '月价（元）', width: kind === 'flavors' ? 110 : 150, render: (_: unknown, row: ComputeResource) => minorToYuanText(shared.draft.price_overrides[kind as 'flavors' | 'disk_types' | 'protections']?.[row.id]?.month ?? row.prices?.month ?? 0) }] : []),
    ...(!shared ? [{ title: '描述', dataIndex: 'description', width: 240, ellipsis: true, render: tableCellText }, { ...sortColumn('sort'), title: '排序', width: 90 }] : []),
    { ...filterColumn('status', { '0': '禁用', '1': '启用' }), title: '状态', width: 90, render: (_, row) => <EnabledStatus status={row.status} /> },
    ...(shared && !['flavor_groups', 'image_types', 'network_policies', 'regions'].includes(kind) ? [{ title: '参与购买', width: 90, render: (_: unknown, row: ComputeResource) => <Checkbox aria-label={`${row.name}参与购买`} checked={shared.draft.resource_ids[kind].includes(row.id)} disabled={!shared.editable || row.status !== 1 || kind !== 'zones' && !(kind === 'protections' ? (row.line_ids ?? [row.line_id ?? 0]).some(id => shared.draft.resource_ids.lines.includes(id)) : resourceZones(row).some(id => shared.draft.resource_ids.zones.includes(id)))} onChange={value => shared.select(kind, row.id, value)} /> }] : []),
    { title: '操作', width: kind === 'zones' && onConfigureZone ? 150 : 110, fixed: 'right', render: (_, row) => <TableActions actions={[...(can('update') ? [{ key: 'edit', label: '编辑', onClick: () => edit(row) }, ...(kind === 'flavors' ? [{ key: 'stock', label: '库存', onClick: () => setStockFlavor(row) }] : [])] : []), ...(kind === 'zones' && onConfigureZone ? [{ key: 'provider', label: '配置提供者', onClick: () => onConfigureZone(row) }] : [])]} /> },
  ]
  const numeric = (field: string, label: string, min: number, max: number, required = true, unit?: string) => <Form.Item key={field} field={field} label={label} tooltip={field.endsWith('_clock_mhz') ? '以 MHz 填写，例如 3.1 GHz 填写 3100；硬件参数仅用于展示，以实际供应能力为准。' : undefined} rules={[{ required, message: `请输入${label}` }]}><InputNumber mode="button" min={min} max={max} precision={0} suffix={unit} placeholder={`请输入${label}${required ? '' : '（选填）'}`} style={{ width: '100%' }} /></Form.Item>
  const resourceForm = <Form form={form} layout="vertical">
        {detailedForm && <Typography.Title heading={6}><SectionTitle>基本信息</SectionTitle></Typography.Title>}
        <div className={detailedForm ? 'cloud-form-grid' : undefined}>
        <Form.Item field="name" label="名称" rules={[{ required: true, message: '请输入名称' }, { maxLength: 128 }]}><Input placeholder="请输入名称" /></Form.Item>
        <Form.Item field="code" label="稳定标识" tooltip="最多 64 个字符，使用字母、数字、下划线或短横线。" rules={[{ required: true, message: '请输入标识' }, { match: /^[A-Za-z0-9_-]+$/, message: '使用字母、数字、下划线或短横线' }, { maxLength: 64 }]}><Input placeholder="请输入稳定标识" /></Form.Item>
        </div>
        {kind === 'regions' && <Form.Item field="country_code" label="国家代码" tooltip="两位英文字母，如 CN、US，保存时自动转为大写。" rules={[{ match: /^[A-Za-z]{2}$/, message: '请输入两位英文字母' }]}><Input maxLength={2} allowClear placeholder="例如 CN、US（选填）" /></Form.Item>}
        {(relationships[kind] || []).map(([field, parent, label]) => <Form.Item key={field} field={field} label={label} rules={[{ required: true, message: `请选择${label}` }]}>{parent === 'zones' ? <ZoneCascader multiple regions={resources?.regions ?? []} zones={resources?.zones ?? []} placeholder="请选择关联可用区" allowClear /> : <Select placeholder={`请选择${label}`} mode={field.endsWith('_ids') ? 'multiple' : undefined} showSearch options={(resources?.[parent] || []).map((row) => ({ value: row.id, label: row.name }))} />}</Form.Item>)}
        {kind === 'lines' && <Form.Item field="test_ip" label="测试 IP" tooltip="供客户测试线路连通性，支持 IPv4 或 IPv6 地址。"><Input maxLength={45} allowClear placeholder="例如 192.0.2.1、2001:db8::1（选填）" /></Form.Item>}
        {(kind === 'flavors' || kind === 'images') && <Form.Item field="architecture" label="架构" rules={[{ required: true }]}><Select placeholder="请选择架构" options={['x86_64', 'aarch64']} /></Form.Item>}
        {kind === 'flavors' && <>{numeric('cpu', 'CPU 核数', 1, 4096, true, '核')}{numeric('memory_mib', '内存', 128, 16777216, true, 'MiB')}</>}
        {kind === 'flavor_groups' && <><Form.Item field="cpu_model" label="CPU 型号" tooltip="用于展示 CPU 型号，以实际供应能力为准。"><Input maxLength={128} placeholder="例如 AMD EPYC 9554，选填" /></Form.Item><div className="cloud-form-grid">{numeric('base_clock_mhz', 'CPU 主频', 1, 20000, false, 'MHz')}{numeric('boost_clock_mhz', 'CPU 睿频', 1, 20000, false, 'MHz')}</div></>}
        {kind === 'images' && <><div className="cloud-form-grid">{numeric('min_cpu', '最低 CPU 核数', 1, 4096, true, '核')}{numeric('min_memory_mib', '最低内存', 128, 16777216, true, 'MiB')}{numeric('min_disk_gib', '最低系统盘容量', 1, 65536, true, 'GiB')}</div><Form.Item field="login_methods" label="支持的登录方式" rules={[{ required: true }]}><Select placeholder="请选择支持的登录方式" mode="multiple" options={[{ value: 'password', label: '密码' }, { value: 'ssh_key', label: 'SSH 密钥' }]} /></Form.Item></>}
        {kind === 'lines' && <Form.Item field="network_configuration"><LineNetworkEditor /></Form.Item>}
        {kind === 'image_types' && <Form.Item field="icon_url" label="图标地址"><Input placeholder="请输入HTTPS图标地址或站内路径（选填）" /></Form.Item>}
        {kind === 'images' && <div className="cloud-form-grid"><Form.Item field="default_username" label="默认用户名"><Input placeholder="例如 root、Administrator" /></Form.Item>{numeric('default_port', '默认登录端口', 1, 65535)}</div>}
        {kind === 'protections' && numeric('capacity_gbps', '防护大小', 0, 1000000, true, 'Gbps')}
        {kind === 'disk_types' && <Typography.Title heading={6}><SectionTitle>容量与数量</SectionTitle></Typography.Title>}
        {kind === 'disk_types' && <div className="cloud-form-grid">{numeric('min_gib', '最小容量', 1, 65536, true, 'GiB')}{numeric('max_gib', '最大容量', 1, 65536, true, 'GiB')}{numeric('step_gib', '容量步长', 1, 65536, true, 'GiB')}{numeric('max_count', '最多数据盘数量', 0, 32, true, '块')}</div>}
        {kind === 'disk_types' && <Typography.Title heading={6}><SectionTitle>性能参数</SectionTitle></Typography.Title>}
        {kind === 'disk_types' && <div className="cloud-form-grid">{numeric('read_mbps', '读取速度', 1, 1000000, false, 'MB/s')}{numeric('write_mbps', '写入速度', 1, 1000000, false, 'MB/s')}{numeric('read_iops', '读取性能', 1, 100000000, false, 'IOPS')}{numeric('write_iops', '写入性能', 1, 100000000, false, 'IOPS')}</div>}
        {kind === 'network_policies' && <div className="cloud-form-grid">{numeric('bandwidth_min', '最小带宽', 0, 1000000, true, 'Mbps')}{numeric('bandwidth_max', '最大带宽', 0, 1000000, true, 'Mbps')}{numeric('bandwidth_step', '带宽步长', 1, 1000000, true, 'Mbps')}{numeric('ip_min', '最少 IPv4 数量', 0, 64, true, '个')}{numeric('ip_max', '最多 IPv4 数量', 0, 64, true, '个')}</div>}
        {kind === 'disk_types' && <Typography.Title heading={6}><SectionTitle>周期价格</SectionTitle></Typography.Title>}
        {['flavors', 'disk_types', 'protections'].includes(kind) && <>
          <div className="cloud-form-grid">{Object.entries(CYCLE_LABELS).filter(([cycle]) => !shared || cycle === 'month').map(([cycle, label]) => <Form.Item key={cycle} field={`prices.${cycle}`} label={`${label}价格`} rules={[{ required: true, message: `请输入${label}价格` }]}>
            <PriceInput aria-label={`${label}价格`} unit={kind === 'disk_types' ? '元 / GiB' : '元 / 台'} />
          </Form.Item>)}</div></>}
        {numeric('sort', '排序', 0, 100000)}
        <Form.Item className="form-dialog-wide" field="description" label="描述"><Input.TextArea maxLength={1000} showWordLimit placeholder="请输入描述（选填）" /></Form.Item>
        <Form.Item field="status" label="状态" triggerPropName="checked" formatter={(value) => value === 1} normalize={(value) => value ? 1 : 0}><Switch type="round" checkedText="启用" uncheckedText="禁用" /></Form.Item>
      </Form>
  return <section className={embedded ? 'cloud-resource-table' : 'page-card'}>
    {kinds.length > 1 && <Radio.Group type="button" aria-label="资源分类" className="cloud-resource-switch" value={kind}
      onChange={(value) => { setKind(value as ResourceKind); reset() }}>
      {kinds.map((key) => <Radio key={key} value={key}>{RESOURCE_LABELS[key]}</Radio>)}
    </Radio.Group>}
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    {!shared && <TableQueryForm fields={searchFields} values={draftFields} onFieldChange={setField}
      appliedCount={Object.keys(queryParams).filter(key => !['sort_by', 'sort_order'].includes(key)).length} onCancelFilters={discard}
      onSearch={search} onReset={reset} loading={loading}>


    </TableQueryForm>}
    <div className="page-toolbar"><Button icon={<IconRefresh />} loading={loading} onClick={async () => { if (!shared) { await load(); return }; setLoading(true); setError(''); try { await shared.refreshResources() } catch (e) { setError(cloudError(e)) } finally { setLoading(false) } }}>刷新</Button>
      {can('create') && <Button icon={<IconPlus />} type="primary" onClick={() => edit(null)}>新建{RESOURCE_LABELS[kind]}</Button>}</div>
    <div className="table-scroll"><Table onChange={onTableChange} key={`${kind}:${JSON.stringify(fields)}`} rowKey="id" columns={columns} data={applyClientTableQuery((resources?.[kind] || []).filter((row) => (!scopeRegionId || row.region_id === scopeRegionId) && (!scopeZoneId || resourceZones(row).includes(scopeZoneId)) && (!scopeLineId || (row.line_ids ?? [row.line_id ?? 0]).includes(scopeLineId)) && row.name.includes(fields.name ?? '') && row.code.includes(fields.code ?? '')), queryParams, ['status'])} loading={loading}
      scroll={{ x: shared && kind === 'zones' ? 610 : shared ? 760 : 930 }} pagination={{ current: page, pageSize, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS, showTotal: true,
        onChange: setPage, onPageSizeChange: setPageSize }} /></div>
    {stockFlavor && resources && <ComputeInventoryEditor flavor={stockFlavor} zones={resources.zones} regions={resources.regions} initialZoneId={scopeZoneId} onSaved={shared?.inventorySaved} onClose={() => setStockFlavor(null)} />}
    {resourceEditor && <FormDialog width={detailedForm ? 960 : editorMode === 'modal' ? 560 : 720} mode={editorMode} visible title={`${editor === 'new' ? '新建' : '编辑'}${RESOURCE_LABELS[kind]}`} onCancel={closeEditor} onOk={() => void save()} confirmLoading={saving} okDisabled={Boolean(editorError) || loading || editorReady !== editor}>
      {editorError ? <Alert type="error" content={editorError} /> : loading || editorReady !== editor ? <Spin /> : resourceForm}
    </FormDialog>}
  </section>
}
