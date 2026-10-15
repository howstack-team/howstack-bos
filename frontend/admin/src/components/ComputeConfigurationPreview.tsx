import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Descriptions, Form, InputNumber, Select, Space, Spin, Table, Typography } from '@arco-design/web-react'
import { formatMoneyMinor } from '../utils/money'
import { cloudProductApi, cloudError, CYCLE_LABELS, type BillingCycle, type ComputeSelection, type PreviewOptions, type PricePreview, type ResourceKind, type DeliveryPreview, type ProvisioningResource } from '../api/cloudProductApi'

import { orderManagementApi } from '../api/orderApi'

/** 共用购买配置器；默认仅预览，订单入口显式提供创建回调，不接收凭据。 */
export default function ComputeConfigurationPreview({ offerId, versionId, onCreate, creating = false }: { offerId: number; versionId?: number; onCreate?: (selection: ComputeSelection, price: PricePreview) => void; creating?: boolean }) {
  const [selection, setSelection] = useState<ComputeSelection>({ quantity: 1, data_disks: [] })
  const [options, setOptions] = useState<PreviewOptions>()
  const [quote, setQuote] = useState<PricePreview>()
  const [delivery, setDelivery] = useState<DeliveryPreview>()
  const [checking, setChecking] = useState(false)
  const [busy, setBusy] = useState(false)
  const [pricing, setPricing] = useState(false)
  const [error, setError] = useState('')
  const [group, setGroup] = useState<number>()
  const [imageType, setImageType] = useState<number>()
  const requestSequence = useRef(0)
  const api = onCreate ? orderManagementApi : cloudProductApi
  useEffect(() => {
    const sequence = ++requestSequence.current
    let active = true
    void Promise.resolve().then(() => { if (active) { setBusy(true); setError(''); setQuote(undefined) } })
    api.options(offerId, { ...selection, flavor_group_id: group, image_type_id: imageType }, versionId).then((result) => {
      if (!active || sequence !== requestSequence.current) return
      setOptions(result)
      if (result.schema_version === '2.0.0') {
        const next = { ...result.configuration }
        const network = result.line_network
        if (network && (!next.network || next.network.egress_mbps === undefined || next.network.ingress_mbps === undefined || next.network.ipv4_count === undefined)) next.network = { egress_mbps: network.egress.default, ingress_mbps: network.ingress.default, ipv4_count: network.ip.default, billing_mode: 'fixed' }
        if (!result.durations?.some(row => row.months === next.duration_months)) next.duration_months = result.durations?.[0]?.months
        if (!next.system_disk && result.options.disk_types.length) {
          const preferredSize = result.storage_policy?.default_size_gib || 40
          const type = result.options.disk_types.find(row => row.id === result.storage_policy?.default_type_id) || result.options.disk_types.find(row => preferredSize >= (row.min_gib || 1) && preferredSize <= (row.max_gib || preferredSize) && (preferredSize - (row.min_gib || 1)) % (row.step_gib || 1) === 0) || result.options.disk_types[0]
          const selectedImage = result.options.images.find(row => row.id === next.image_id)
          const base = type.min_gib || 1; const step = type.step_gib || 1
          const min = Math.max(base, selectedImage?.min_disk_gib || 1, result.storage_policy?.default_size_gib || 1)
          const size = base + Math.ceil((min - base) / step) * step
          if (size <= (type.max_gib || size)) next.system_disk = { type_id: type.id, size_gib: size }
        }
        if (JSON.stringify(next) !== JSON.stringify(selection)) setSelection(next)
      }
      if (Object.keys(result.invalid_fields).length) setError('部分选择已失效，请重新选择对应配置。')
    }).catch((e: unknown) => { if (active && sequence === requestSequence.current) { setError(cloudError(e)); setOptions(undefined) } })
      .finally(() => { if (active && sequence === requestSequence.current) setBusy(false) })
    return () => { active = false }
  }, [offerId, versionId, selection, group, imageType, api])
  const change = (next: ComputeSelection) => { setSelection(next); setQuote(undefined); setDelivery(undefined) }
  const choose = (field: 'region_id' | 'zone_id' | 'line_id' | 'flavor_id' | 'image_id', value: number | undefined) => {
    const next = { ...selection, [field]: value }
    if (field === 'region_id') { delete next.zone_id }
    if (field === 'region_id' || field === 'zone_id') { delete next.duration_months; delete next.network; delete next.line_id; delete next.flavor_id; delete next.image_id; delete next.system_disk; delete next.protection_id; next.data_disks = []; setGroup(undefined); setImageType(undefined) }
    if (field === 'line_id') { delete next.protection_id; if (options?.schema_version === '2.0.0') delete next.network }
    if (field === 'flavor_id') { delete next.image_id; delete next.login_method }
    if (field === 'image_id') delete next.login_method
    change(next)
  }
  const pick = (label: string, field: 'region_id' | 'zone_id' | 'line_id' | 'flavor_id' | 'image_id', kind: ResourceKind) => {
    const rows = (options?.options[kind] || []).filter((row) => (kind !== 'flavors' || !group || row.group_id === group) && (kind !== 'images' || !imageType || row.type_id === imageType))
    return <Form.Item label={label} extra={field === 'image_id' && image?.default_username ? `默认用户 ${image.default_username} · 登录端口 ${image.default_port || 22}` : undefined}><Select placeholder={`请选择${label}`} allowClear showSearch value={selection[field]} options={rows.map((row) => ({ value: row.id, label: row.name }))} onChange={(value) => choose(field, value)} /></Form.Item>
  }
  const price = async () => {
    const sequence = requestSequence.current
    setPricing(true); setError('')
    try { const result = await api.price(offerId, { ...selection, flavor_group_id: group, image_type_id: imageType, billing_cycle: selection.billing_cycle || options?.cycles[0], login_method: selection.login_method || image?.login_methods?.[0] }, versionId); if (sequence === requestSequence.current) setQuote(result) }
    catch (e) { if (sequence === requestSequence.current) setError(cloudError(e)) } finally { setPricing(false) }
  }
  const prepaid = options?.schema_version === '2.0.0'
  const lineNetwork = options?.line_network
  const diskTypes = options?.options.disk_types || []
  const disk = diskTypes.find((row) => row.id === selection.system_disk?.type_id)
  const includedSystemDiskGib = options?.system_disk_included_gib ?? 0
  const image = options?.options.images.find((row) => row.id === selection.image_id)
  const selectedGroup = options?.options.flavor_groups.find(row => row.id === (group ?? options?.options.flavors.find(flavor => flavor.id === selection.flavor_id)?.group_id))
  const groupDescription = selectedGroup ? [selectedGroup.cpu_model, selectedGroup.base_clock_mhz ? `主频 ${selectedGroup.base_clock_mhz} MHz` : '', selectedGroup.boost_clock_mhz ? `睿频 ${selectedGroup.boost_clock_mhz} MHz` : ''].filter(Boolean).join(' · ') || undefined : undefined
  const amount = (minor: number) => formatMoneyMinor(minor, quote?.currency || 'CNY')
  const checkDelivery = async () => {
    const sequence = requestSequence.current
    setChecking(true); setError(''); setDelivery(undefined)
    try {
      const result = await cloudProductApi.deliveryPreview(offerId, { ...selection, flavor_group_id: group, image_type_id: imageType, billing_cycle: selection.billing_cycle || options?.cycles[0], login_method: selection.login_method || image?.login_methods?.[0] }, versionId)
      if (sequence === requestSequence.current) setDelivery(result)
    } catch (error) { if (sequence === requestSequence.current) setError(cloudError(error)) }
    finally { setChecking(false) }
  }
  const deliveredResource = (row: ProvisioningResource) => `${row.name}${row.external_id ? `（供应商编号：${row.external_id}）` : ''}`
  return <div>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <Spin loading={busy || creating} style={{ display: 'block' }}><Form layout="vertical">
      <div className="cloud-form-grid">{prepaid && <Form.Item label="计费模式"><Select placeholder="请选择计费模式" value="prepaid" options={[{ value: 'prepaid', label: '包年包月' }]} /></Form.Item>}{pick('地域', 'region_id', 'regions')}{pick('可用区', 'zone_id', 'zones')}{pick('线路', 'line_id', 'lines')}
        <Form.Item label="规格组" extra={groupDescription}><Select placeholder="请选择规格组" allowClear value={group} options={(options?.options.flavor_groups || []).map((row) => ({ value: row.id, label: row.name }))} onChange={(value) => { setGroup(value); choose('flavor_id', undefined) }} /></Form.Item>
        {pick('规格', 'flavor_id', 'flavors')}
        <Form.Item label="镜像类型"><Select placeholder="请选择镜像类型" allowClear value={imageType} options={(options?.options.image_types || []).map((row) => ({ value: row.id, label: <Space size={6}>{row.icon_url && <img src={row.icon_url} alt="" width={18} height={18} style={{ objectFit: 'contain', verticalAlign: 'middle' }} />}{row.name}</Space> }))} onChange={(value) => { setImageType(value); choose('image_id', undefined) }} /></Form.Item>
        {pick('镜像', 'image_id', 'images')}
        <Form.Item label="系统盘类型"><Select placeholder="请选择系统盘类型" value={selection.system_disk?.type_id} options={diskTypes.map((row) => ({ value: row.id, label: row.name }))} onChange={(value) => {
          const type = diskTypes.find((row) => row.id === value)
          const min = Math.max(type?.min_gib || 1, image?.min_disk_gib || 1)
          const base = type?.min_gib || 1; const step = type?.step_gib || 1
          change({ ...selection, system_disk: { type_id: value, size_gib: base + Math.ceil((min - base) / step) * step } })
        }} /></Form.Item>
        <Form.Item label="系统盘容量" extra={includedSystemDiskGib > 0 ? `每台赠送 ${includedSystemDiskGib} GiB${selection.system_disk?.size_gib !== undefined ? `，当前计费 ${Math.max(0, selection.system_disk.size_gib - includedSystemDiskGib)} GiB` : '，超出部分收费'}` : undefined}><InputNumber mode="button" placeholder="请输入系统盘容量" suffix="GiB" aria-label="系统盘容量" value={selection.system_disk?.size_gib} min={Math.max(disk?.min_gib || 1, image?.min_disk_gib || 1)} max={disk?.max_gib} step={disk?.step_gib || 1} precision={0} onChange={(value) => change({ ...selection, system_disk: { ...selection.system_disk, size_gib: value } })} style={{ width: '100%' }} /></Form.Item>
      </div>
      <Typography.Title heading={6}>数据盘</Typography.Title>
      {(selection.data_disks || []).map((dataDisk, index) => {
        const type = diskTypes.find((row) => row.id === dataDisk.type_id)
        const update = (value: { type_id?: number; size_gib?: number }) => change({ ...selection, data_disks: (selection.data_disks || []).map((entry, i) => i === index ? value : entry) })
        return <div className="cloud-disk-row" key={index}>
          <Select placeholder="请选择存储类型" aria-label={`数据盘 ${index + 1} 类型`} value={dataDisk.type_id} options={diskTypes.map((row) => ({ value: row.id, label: row.name }))} onChange={(value) => update({ type_id: value, size_gib: diskTypes.find((row) => row.id === value)?.min_gib || 1 })} />
          <InputNumber mode="button" placeholder="请输入容量" suffix="GiB" aria-label={`数据盘 ${index + 1} 容量（GiB）`} value={dataDisk.size_gib} min={type?.min_gib || 1} max={type?.max_gib} step={type?.step_gib || 1} precision={0} onChange={(value) => update({ ...dataDisk, size_gib: value })} />
          <Button onClick={() => change({ ...selection, data_disks: selection.data_disks?.filter((_, i) => i !== index) })}>移除</Button>
        </div>
      })}
      <Button disabled={!diskTypes.length || (selection.data_disks?.length || 0) >= (options?.storage_policy?.max_data_disks ?? 32)} onClick={() => change({ ...selection, data_disks: [...(selection.data_disks || []), { type_id: diskTypes[0]?.id, size_gib: diskTypes[0]?.min_gib }] })}>添加数据盘</Button>
      <div className="cloud-form-grid cloud-section">
        {prepaid && <Form.Item label="带宽计费模式"><Select placeholder="请选择带宽计费模式" value="fixed" options={[{ value: 'fixed', label: '固定带宽' }]} /></Form.Item>}
        {prepaid && (['egress', 'ingress'] as const).map(direction => <Form.Item key={direction} label={direction === 'egress' ? '上行带宽' : '下行带宽'} extra={lineNetwork ? `每台赠送 ${lineNetwork[direction].included} Mbps` : undefined}><InputNumber mode="button" placeholder="请选择线路后填写带宽" suffix="Mbps" value={selection.network?.[`${direction}_mbps`]} min={lineNetwork?.[direction].min} max={lineNetwork?.[direction].max} step={lineNetwork?.[direction].step || 1} precision={0} disabled={!lineNetwork} onChange={v => change({ ...selection, network: { ...selection.network, [`${direction}_mbps`]: v } })} style={{ width: '100%' }} /></Form.Item>)}
        {!prepaid && <Form.Item label="带宽"><InputNumber mode="button" placeholder="请输入带宽" suffix="Mbps" value={selection.network?.bandwidth_mbps} min={options?.network.bandwidth_min} max={options?.network.bandwidth_max} step={options?.network.bandwidth_step || 1} precision={0} onChange={(value) => change({ ...selection, network: { ...selection.network, bandwidth_mbps: value } })} style={{ width: '100%' }} /></Form.Item>}
        <Form.Item label="IPv4 数量" extra={prepaid && lineNetwork ? `每台赠送 ${lineNetwork.ip.included} 个` : undefined}>{prepaid && lineNetwork?.ip.mode === 'options' ? <Select placeholder="请选择IP数量" value={selection.network?.ipv4_count} options={lineNetwork.ip.options.map(value => ({ value, label: `${value}个` }))} onChange={v => change({ ...selection, network: { ...selection.network, ipv4_count: v } })} /> : <InputNumber mode="button" placeholder="请输入 IPv4 数量" suffix="个" value={selection.network?.ipv4_count} min={prepaid ? lineNetwork?.ip.min : options?.network.ip_min} max={prepaid ? lineNetwork?.ip.max : options?.network.ip_max} step={prepaid ? lineNetwork?.ip.step : 1} precision={0} onChange={v => change({ ...selection, network: { ...selection.network, ipv4_count: v } })} style={{ width: '100%' }} />}</Form.Item>
        <Form.Item label="防护"><Select placeholder="请选择防护" allowClear value={selection.protection_id} options={(options?.options.protections || []).map((row) => ({ value: row.id, label: row.name }))} onChange={(value) => change({ ...selection, protection_id: value })} /></Form.Item>
        <Form.Item label="登录方式"><Select placeholder="请选择登录方式" value={prepaid ? 'password' : selection.login_method || image?.login_methods?.[0]} options={(prepaid ? ['password'] : image?.login_methods || []).map((value) => ({ value, label: value === 'password' ? '密码' : 'SSH 密钥' }))} onChange={(value) => change({ ...selection, login_method: value })} /></Form.Item>
        <Form.Item label="付费周期">{prepaid ? <Select placeholder={selection.zone_id ? '请选择付费周期' : '请先选择可用区'} disabled={busy || !selection.zone_id} value={selection.duration_months} options={(options?.durations || []).map(row => ({ value: row.months, label: `${row.label}（${row.months}个月）` }))} onChange={v => change({ ...selection, duration_months: v })} /> : <Select placeholder="请选择计费周期" value={selection.billing_cycle || options?.cycles[0]} options={(options?.cycles || []).map(value => ({ value, label: CYCLE_LABELS[value] }))} onChange={(v: BillingCycle) => change({ ...selection, billing_cycle: v })} />}</Form.Item>
        <Form.Item label="购买数量"><InputNumber mode="button" placeholder="请输入购买数量" suffix="台" value={selection.quantity} min={1} max={options?.quantity_limit || 1} precision={0} onChange={(value) => change({ ...selection, quantity: value })} style={{ width: '100%' }} /></Form.Item>
      </div>
      <Space wrap><Button type="primary" disabled={busy || !options || creating} loading={pricing} onClick={() => void price()}>计算费用</Button><>{!onCreate && <Button disabled={busy || !options} loading={checking} onClick={() => void checkDelivery()}>校验交付配置</Button>}<Typography.Text type="secondary">{onCreate ? '创建后待付款，付款时检查库存；实例开通另行办理' : '仅预览，不创建实例'}</Typography.Text></></Space>
    </Form></Spin>
    {quote && <div className="cloud-section"><Descriptions title="费用预览" column={{ xs: 1, sm: 1, md: 2, lg: 2, xl: 2, xxl: 2, xxxl: 2 }} data={[{ label: '每台费用', value: amount(quote.unit_amount_minor) }, { label: `${quote.quantity} 台总费用`, value: amount(quote.amount_minor) }, ...(quote.subtotal_minor !== undefined ? [{ label: '总原价', value: amount(quote.subtotal_minor) }, { label: '周期优惠', value: amount(quote.discount_minor || 0) }] : [])]} />
      <Table rowKey="key" pagination={false} data={quote.unit_breakdown} scroll={{ x: 510 }} columns={[{ title: '计费项目', dataIndex: 'label', width: 180, ellipsis: true }, { title: '计费数量', width: 90, render: (_, row) => row.key === 'system_disk' || row.key.startsWith('data_disks.') ? `${row.quantity} GiB` : row.key.startsWith('network.') && row.key !== 'network.ip' ? `${row.quantity} Mbps` : row.quantity }, { title: '单价', width: 110, render: (_, row) => row.pricing_mode && row.pricing_mode !== 'flat' ? '阶梯计价' : `${amount(row.unit_price_minor)}${row.recurring ? ' / 月' : ''}` }, { title: '每台分项费用', width: 130, render: (_, row) => amount(row.amount_minor) }]} />
    </div>}
    {onCreate && <div style={{ marginTop: 16 }}><Button type="primary" disabled={busy || pricing || !quote || creating} loading={creating} onClick={() => { if (quote) onCreate(quote.configuration, quote) }}>创建订单</Button></div>}
    {delivery && <div className="cloud-section"><Descriptions title="交付配置预览" column={{ xs: 1, sm: 1, md: 2, lg: 2, xl: 2, xxl: 2, xxxl: 2 }} data={[
      { label: '地域', value: deliveredResource(delivery.configuration.region) }, { label: '可用区', value: deliveredResource(delivery.configuration.zone) },
      { label: '线路', value: deliveredResource(delivery.configuration.line) }, { label: '规格组', value: deliveredResource(delivery.configuration.flavor_group) },
      { label: '规格', value: `${deliveredResource(delivery.configuration.flavor)} · ${delivery.configuration.flavor.cpu} 核 / ${delivery.configuration.flavor.memory_mib} MiB` },
      { label: '镜像', value: deliveredResource(delivery.configuration.image) },
      { label: '系统盘', value: `${deliveredResource(delivery.configuration.system_disk.type)} · ${delivery.configuration.system_disk.size_gib} GiB` },
      { label: '数据盘', value: delivery.configuration.data_disks.map((disk) => `${deliveredResource(disk.type)} · ${disk.size_gib} GiB`).join('、') || '-' },
      { label: '带宽 / IPv4', value: delivery.configuration.duration ? `上行 ${delivery.configuration.network.egress_mbps} / 下行 ${delivery.configuration.network.ingress_mbps} Mbps / ${delivery.configuration.network.ipv4_count} 个` : `${delivery.configuration.network.bandwidth_mbps} Mbps / ${delivery.configuration.network.ipv4_count} 个` },
      { label: '防护', value: delivery.configuration.protection ? deliveredResource(delivery.configuration.protection) : '-' },
      { label: '周期 / 数量', value: `${delivery.configuration.duration ? `${delivery.configuration.duration.count}个月` : CYCLE_LABELS[delivery.configuration.billing_cycle]} / ${delivery.configuration.quantity} 台` },
      { label: '登录方式', value: delivery.configuration.login_method === 'password' ? '密码' : 'SSH 密钥' },
      { label: '生产能力', value: delivery.production_ready ? '已声明支持生产' : '尚未接入生产操作' },
    ]} />{delivery.provider_parameters && Object.keys(delivery.provider_parameters).length > 0 && <Form.Item label="供应商参数预览"><pre style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{JSON.stringify(delivery.provider_parameters, null, 2)}</pre></Form.Item>}</div>}
  </div>
}
