import { useState } from 'react'
import { Link } from 'react-router-dom'
import Button from '@arco-design/mobile-react/esm/button'
import Input from '@arco-design/mobile-react/esm/input'
import Picker from '@arco-design/mobile-react/esm/picker'
import { useCloudPurchase } from '../hooks/useCloudPurchase'
import type { PurchaseResourceKind } from '../api/purchaseApi'
import { minorToYuanText } from '../utils/money'
import { Brand, Busy, EmptyState } from './ui'

function Choice({ label, value, rows, onChange, disabled }: { label: string; value?: number; rows: { value: number; label: string }[]; onChange: (id: number) => void; disabled?: boolean }) {
  const [open, setOpen] = useState(false)
  return <div className="m-purchase-control"><span>{label}</span><Button type="default" inline size="small" disabled={disabled || !rows.length} onClick={() => setOpen(true)}>{rows.find(row => row.value === value)?.label ?? '请选择'} <span aria-hidden="true">›</span></Button>
    <Picker title={label} visible={open} cascade={false} data={[rows]} value={value === undefined ? undefined : [value]} maskClosable
      onHide={() => setOpen(false)} onOk={values => { onChange(Number(values[0])); setOpen(false) }} /></div>
}
function Quantity({ label, value, min, max, step, unit = '', onChange, disabled }: { label: string; value?: number; min?: number; max?: number; step?: number; unit?: string; onChange: (value: number) => void; disabled?: boolean }) {
  return <div className="m-purchase-control"><span>{label}</span><Input ariaLabel={label} type="number" value={value === undefined ? '' : String(value)} disabled={disabled} suffix={unit || undefined} inputStyle={{ minWidth: 0 }}
    nativeProps={{ min, max, step, inputMode: 'numeric' }} onInput={(_, next) => onChange(Number(next))} /></div>
}
/** 独立移动购买界面；只使用 Arco Mobile，复用公开选配与订单 API。 */
export default function MobileCloudPurchasePage() {
  const state = useCloudPurchase(), { selection: s, options: o, quote, product } = state
  const options = (kind: PurchaseResourceKind) => (o?.options[kind] ?? []).map(row => ({ value: row.id, label: kind === 'flavors' ? `${row.name} · ${row.cpu}核 / ${(row.memory_mib ?? 0) / 1024}GiB` : row.name }))
  const choose = (label: string, field: 'region_id' | 'zone_id' | 'line_id' | 'flavor_group_id' | 'flavor_id' | 'image_type_id' | 'image_id', kind: PurchaseResourceKind) => <Choice label={label} value={s[field]} rows={options(kind)} disabled={state.submitting} onChange={value => state.choose(field, value)} />
  const disk = o?.options.disk_types.find(row => row.id === s.system_disk?.type_id)
  const image = o?.options.images.find(row => row.id === s.image_id)
  return <div className="m-purchase"><header className="m-purchase-header"><a href="/"><Brand small /></a></header><div className="m-purchase-heading"><h1>购买云服务器</h1><a href="/products/cloud-server">产品介绍</a></div>
    <fieldset className="m-purchase-body" disabled={state.submitting}>
    {state.error && <section className="m-panel m-purchase-error" role="alert"><p>{state.error}</p><Button size="small" inline type="default" onClick={state.retry}>重新加载</Button></section>}
    {state.notice && <p className="m-panel m-muted">{state.notice}</p>}
    {state.catalogLoading ? <Busy /> : !state.products.length ? <EmptyState text="暂无可购买的云服务器产品" /> : <>
      <section className="m-panel"><h2>部署位置</h2>{state.products.length > 1 && <Choice label="产品" value={product?.id} rows={state.products.map(row => ({ value: row.id, label: row.name }))} onChange={state.selectProduct} />}
        <div className="m-purchase-control"><span>计费模式</span><span>包年包月</span></div>{choose('地域', 'region_id', 'regions')}{choose('可用区', 'zone_id', 'zones')}{choose('线路', 'line_id', 'lines')}</section>
      <section className="m-panel"><h2>计算与系统</h2>{choose('规格组', 'flavor_group_id', 'flavor_groups')}{choose('规格', 'flavor_id', 'flavors')}{choose('镜像分类', 'image_type_id', 'image_types')}{choose('镜像', 'image_id', 'images')}
        {image?.default_username && <p className="m-purchase-hint">默认用户 {image.default_username}，端口 {image.default_port ?? 22}</p>}</section>
      <section className="m-panel"><h2>磁盘</h2><Choice label="系统盘类型" value={s.system_disk?.type_id} rows={options('disk_types')} onChange={id => { const type = o?.options.disk_types.find(row => row.id === id); if (type) state.update({ ...s, system_disk: { type_id: id, size_gib: Math.max(type.min_gib ?? 1, o?.storage_policy.default_size_gib ?? 40) } }) }} />
        <Quantity label="系统盘容量" value={s.system_disk?.size_gib} min={Math.max(disk?.min_gib ?? 1, image?.min_disk_gib ?? 1)} max={disk?.max_gib} step={disk?.step_gib ?? 1} unit="GiB" onChange={value => state.update({ ...s, system_disk: { type_id: s.system_disk?.type_id ?? 0, size_gib: value } })} />
        <p className="m-purchase-hint">每台赠送 {o?.system_disk_included_gib ?? 0} GiB</p>
        {(s.data_disks ?? []).map((item, index) => { const type = o?.options.disk_types.find(row => row.id === item.type_id); return <div className="m-purchase-disk" key={index}><div className="m-row"><h3>数据盘 {index + 1}</h3><button className="m-purchase-delete" onClick={() => state.update({ ...s, data_disks: s.data_disks?.filter((_, row) => row !== index) })}>删除</button></div>
          <Choice label="存储类型" value={item.type_id} rows={options('disk_types')} onChange={id => { const rows = [...(s.data_disks ?? [])]; rows[index] = { type_id: id, size_gib: o?.options.disk_types.find(row => row.id === id)?.min_gib ?? 1 }; state.update({ ...s, data_disks: rows }) }} />
          <Quantity label={`数据盘 ${index + 1} 容量`} value={item.size_gib} min={type?.min_gib} max={type?.max_gib} step={type?.step_gib} unit="GiB" onChange={value => { const rows = [...(s.data_disks ?? [])]; rows[index] = { ...item, size_gib: value }; state.update({ ...s, data_disks: rows }) }} /></div> })}
        <Button type="default" size="small" disabled={!disk || (s.data_disks?.length ?? 0) >= (o?.storage_policy.max_data_disks ?? 0)} onClick={() => { if (disk) state.update({ ...s, data_disks: [...(s.data_disks ?? []), { type_id: disk.id, size_gib: disk.min_gib ?? 1 }] }) }}>增加数据盘</Button></section>
      <section className="m-panel"><h2>网络</h2><div className="m-purchase-control"><span>带宽计费</span><span>固定带宽</span></div>
        {(['egress', 'ingress'] as const).map(direction => <Quantity key={direction} label={direction === 'egress' ? '上行带宽' : '下行带宽'} value={s.network?.[`${direction}_mbps`]} min={o?.line_network?.[direction].min} max={o?.line_network?.[direction].max} step={o?.line_network?.[direction].step} unit="Mbps" onChange={value => { if (s.network) state.update({ ...s, network: { ...s.network, [`${direction}_mbps`]: value } }) }} />)}
        {o?.line_network?.ip.mode === 'options' ? <Choice label="IP 数量" value={s.network?.ipv4_count} rows={o.line_network.ip.options.map(value => ({ value, label: `${value} 个` }))} onChange={value => { if (s.network) state.update({ ...s, network: { ...s.network, ipv4_count: value } }) }} /> : <Quantity label="IP 数量" value={s.network?.ipv4_count} min={o?.line_network?.ip.min} max={o?.line_network?.ip.max} step={o?.line_network?.ip.step} onChange={value => { if (s.network) state.update({ ...s, network: { ...s.network, ipv4_count: value } }) }} />}
        <Choice label="防护" value={s.protection_id ?? 0} rows={[{ value: 0, label: '不额外购买' }, ...options('protections')]} onChange={value => state.update({ ...s, protection_id: value })} /></section>
      <section className="m-panel"><h2>数量与周期</h2><Quantity label="购买数量" value={s.quantity ?? 1} min={1} max={o?.quantity_limit ?? 1} step={1} onChange={value => state.update({ ...s, quantity: value })} />
        <Choice label="付费周期" value={s.duration_months} rows={(o?.durations ?? []).map(row => ({ value: row.months, label: `${row.label}（${row.months}个月）` }))} onChange={value => state.update({ ...s, duration_months: value })} /></section>
      <section className="m-panel"><h2>实例密码（选填）</h2><Input type="password" value={state.loginPassword} maxLength={72} placeholder="留空则由系统生成" onInput={(_, value) => state.setLoginPassword(value)} />{state.passwordError && <p className="m-field-error" role="alert">{state.passwordError}</p>}<p className="m-muted">密码不会保存在浏览器中，开通后可查看系统生成的密码。</p></section>
      <section className="m-panel"><h2>费用明细</h2>{state.pricingZone && <p className="m-purchase-hint">按{state.pricingZone}价格计费</p>}{quote ? quote.unit_breakdown.filter(row => row.amount_minor > 0).map(row => <div className="m-row m-purchase-part" key={row.key}><span>{row.label}</span><span>¥{minorToYuanText(row.amount_minor)}</span></div>) : <p className="m-muted">完成选配后显示费用</p>}{quote && <p className="m-purchase-hint">每台 ¥{minorToYuanText(quote.unit_amount_minor)} × {quote.quantity} 台</p>}</section>
      <footer className="m-purchase-submit"><div><span className="m-muted">合计</span><strong>{state.loading || state.pricing ? '计算中…' : quote ? `¥${minorToYuanText(quote.amount_minor)}` : '—'}</strong></div>
        <Button size="medium" inline disabled={!quote || state.loading || state.pricing || quote.amount_minor <= 0} loading={state.submitting} onClick={() => void state.submit()}>{state.member ? '提交订单' : '登录后继续'}</Button></footer>
      {!state.member && <p className="m-purchase-hint">登录后保留当前选配结果</p>}
    </>}
    {state.member && <p><Link to="/overview">返回会员中心</Link></p>}
    </fieldset>
  </div>
}
