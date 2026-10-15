import { Alert, Button, Card, Descriptions, Divider, Empty, Form, InputNumber, Link, Radio, Select, Space, Spin, Typography } from '@arco-design/web-react'
import { Link as RouterLink } from 'react-router-dom'
import { useCloudPurchase } from '../hooks/useCloudPurchase'
import { useConsoleTheme } from '../ConsoleThemeContext'
import { paths } from '../routePaths'
import { minorToYuanText as formatMinor } from '../utils/money'
import type { PurchaseResourceKind } from '../api/purchaseApi'
import './CloudPurchasePage.css'

/** 匿名与会员共用购买页，使用原生 Arco 控件展示真实产品和服务端报价。 */
export default function CloudPurchasePage() {
  const state = useCloudPurchase(), { selection: s, options: o, quote, product } = state
  const theme = useConsoleTheme()!
  const disabled = state.submitting
  const disk = o?.options.disk_types.find(row => row.id === s.system_disk?.type_id)
  const image = o?.options.images.find(row => row.id === s.image_id)
  const group = o?.options.flavor_groups.find(row => row.id === (s.flavor_group_id ?? o.options.flavors.find(item => item.id === s.flavor_id)?.group_id))
  const options = (kind: PurchaseResourceKind) => (o?.options[kind] ?? []).map(row => ({ value: row.id, label: kind === 'flavors' ? `${row.name} · ${row.cpu} 核 / ${(row.memory_mib ?? 0) / 1024} GiB` : row.name }))
  const select = (label: string, field: 'region_id' | 'zone_id' | 'line_id' | 'flavor_group_id' | 'flavor_id' | 'image_type_id' | 'image_id', kind: PurchaseResourceKind) =>
    <Form.Item label={label}><Select aria-label={label} placeholder={`请选择${label}`} value={s[field]} options={options(kind)} disabled={disabled || !options(kind).length} onChange={value => state.choose(field, value)} /></Form.Item>
  return <main className="purchase-page">
    {!state.member && <header className="purchase-public-header"><a href="/" className="purchase-brand">{theme.site.logo && <img src={theme.site.logo} alt="" />}{theme.site.name}</a><RouterLink to={{ pathname: paths.login, state: { from: `${paths.purchase}?offer=${s ? product?.id ?? '' : ''}` } }}>登录</RouterLink></header>}
    <div className="purchase-heading"><div><Typography.Title heading={4}>购买云服务器</Typography.Title><Typography.Text type="secondary">选择适合您的配置，按实际选配计算费用。</Typography.Text></div><Link href="/products/cloud-server">查看产品介绍</Link></div>
    {state.error && <Alert type="error" content={state.error} action={<Button type="text" onClick={state.retry}>重新加载</Button>} />}
    {state.notice && <Alert type="info" content={state.notice} />}
    {state.catalogLoading ? <Spin /> : !state.products.length ? <Card><Empty description="暂无可购买的云服务器产品" /></Card> : <div className="purchase-layout">
      <Card className="purchase-form"><Form layout="vertical" disabled={disabled}>
        {state.products.length > 1 && <Form.Item label="产品"><Select aria-label="产品" value={product?.id} options={state.products.map(row => ({ value: row.id, label: row.name }))} onChange={state.selectProduct} /></Form.Item>}
        <Typography.Title heading={6}>部署位置</Typography.Title><Form.Item label="计费模式"><Radio.Group value="prepaid"><Radio value="prepaid">包年包月</Radio></Radio.Group></Form.Item>
        <div className="purchase-fields">{select('地域', 'region_id', 'regions')}{select('可用区', 'zone_id', 'zones')}{select('线路', 'line_id', 'lines')}</div>
        <Divider /><Typography.Title heading={6}>计算与系统</Typography.Title>
        <div className="purchase-fields">{select('规格组', 'flavor_group_id', 'flavor_groups')}{select('规格', 'flavor_id', 'flavors')}{select('镜像分类', 'image_type_id', 'image_types')}{select('镜像', 'image_id', 'images')}</div>
        {group?.cpu_model && <Typography.Text type="secondary">CPU：{group.cpu_model}{group.base_clock_mhz ? ` · 主频 ${group.base_clock_mhz} MHz` : ''}{group.boost_clock_mhz ? ` · 睿频 ${group.boost_clock_mhz} MHz` : ''}</Typography.Text>}
        {image?.default_username && <p className="purchase-hint">默认用户：{image.default_username}，登录端口：{image.default_port ?? 22}</p>}
        <Divider /><Typography.Title heading={6}>磁盘</Typography.Title>
        <div className="purchase-fields"><Form.Item label="系统盘类型"><Select aria-label="系统盘类型" value={s.system_disk?.type_id} options={options('disk_types')} onChange={id => {
          const row = o?.options.disk_types.find(item => item.id === id); if (row) state.update({ ...s, system_disk: { type_id: id, size_gib: Math.max(row.min_gib ?? 1, o?.storage_policy.default_size_gib ?? 40) } })
        }} /></Form.Item><Form.Item label="系统盘容量" extra={o ? `每台赠送 ${o.system_disk_included_gib} GiB` : undefined}><InputNumber aria-label="系统盘容量" mode="button" precision={0} min={Math.max(disk?.min_gib ?? 1, image?.min_disk_gib ?? 1)} max={disk?.max_gib} step={disk?.step_gib ?? 1} suffix="GiB" value={s.system_disk?.size_gib} onChange={value => state.update({ ...s, system_disk: { type_id: s.system_disk?.type_id ?? 0, size_gib: value } })} /></Form.Item></div>
        {(s.data_disks ?? []).map((item, index) => { const type = o?.options.disk_types.find(row => row.id === item.type_id); return <div key={index} className="purchase-disk-row">
          <Form.Item label={`数据盘 ${index + 1}`}><Select aria-label={`数据盘 ${index + 1} 类型`} value={item.type_id} options={options('disk_types')} onChange={value => { const rows = [...(s.data_disks ?? [])]; rows[index] = { type_id: value, size_gib: o?.options.disk_types.find(row => row.id === value)?.min_gib ?? 1 }; state.update({ ...s, data_disks: rows }) }} /></Form.Item>
          <Form.Item label="容量"><InputNumber aria-label={`数据盘 ${index + 1} 容量`} mode="button" precision={0} min={type?.min_gib ?? 1} max={type?.max_gib} step={type?.step_gib ?? 1} suffix="GiB" value={item.size_gib} onChange={value => { const rows = [...(s.data_disks ?? [])]; rows[index] = { ...item, size_gib: value }; state.update({ ...s, data_disks: rows }) }} /></Form.Item>
          <Link status="error" onClick={() => state.update({ ...s, data_disks: s.data_disks?.filter((_, row) => row !== index) })}>删除</Link></div> })}
        <Button long disabled={!disk || (s.data_disks?.length ?? 0) >= (o?.storage_policy.max_data_disks ?? 0)} onClick={() => { if (disk) state.update({ ...s, data_disks: [...(s.data_disks ?? []), { type_id: disk.id, size_gib: disk.min_gib ?? 1 }] }) }}>增加数据盘</Button>
        <Divider /><Typography.Title heading={6}>网络</Typography.Title><Form.Item label="带宽计费"><Radio.Group value="fixed"><Radio value="fixed">固定带宽</Radio></Radio.Group></Form.Item>
        <div className="purchase-fields">{(['egress', 'ingress'] as const).map(direction => <Form.Item key={direction} label={direction === 'egress' ? '上行带宽' : '下行带宽'} extra={o?.line_network ? `赠送 ${o.line_network[direction].included} Mbps` : undefined}>
          <InputNumber aria-label={direction === 'egress' ? '上行带宽' : '下行带宽'} mode="button" precision={0} min={o?.line_network?.[direction].min} max={o?.line_network?.[direction].max} step={o?.line_network?.[direction].step ?? 1} suffix="Mbps" value={s.network?.[`${direction}_mbps`]} onChange={value => { if (s.network) state.update({ ...s, network: { ...s.network, [`${direction}_mbps`]: value } }) }} /></Form.Item>)}
          <Form.Item label="IP 数量" extra={o?.line_network ? `每台赠送 ${o.line_network.ip.included} 个` : undefined}>{o?.line_network?.ip.mode === 'options' ? <Select aria-label="IP 数量" value={s.network?.ipv4_count} options={o.line_network.ip.options.map(value => ({ value, label: `${value} 个` }))} onChange={value => { if (s.network) state.update({ ...s, network: { ...s.network, ipv4_count: value } }) }} /> : <InputNumber aria-label="IP 数量" mode="button" precision={0} min={o?.line_network?.ip.min} max={o?.line_network?.ip.max} step={o?.line_network?.ip.step ?? 1} value={s.network?.ipv4_count} onChange={value => { if (s.network) state.update({ ...s, network: { ...s.network, ipv4_count: value } }) }} />}</Form.Item>
          <Form.Item label="防护"><Select aria-label="防护" value={s.protection_id ?? 0} options={[{ value: 0, label: '不额外购买' }, ...(o?.options.protections ?? []).map(row => ({ value: row.id, label: `${row.name}${row.capacity_gbps ? ` · ${row.capacity_gbps} G` : ''}` }))]} onChange={value => state.update({ ...s, protection_id: value })} /></Form.Item></div>
        <Divider /><Typography.Title heading={6}>购买数量与周期</Typography.Title><div className="purchase-fields"><Form.Item label="数量"><InputNumber aria-label="购买数量" mode="button" precision={0} min={1} max={o?.quantity_limit ?? 1} value={s.quantity ?? 1} onChange={value => state.update({ ...s, quantity: value })} /></Form.Item><Form.Item label="付费周期"><Select aria-label="付费周期" value={s.duration_months} options={(o?.durations ?? []).map(row => ({ value: row.months, label: `${row.label}（${row.months} 个月）` }))} onChange={value => state.update({ ...s, duration_months: value })} /></Form.Item></div>
      </Form></Card>
      <Card className="purchase-summary" title="费用确认"><Typography.Text>{product?.name}</Typography.Text><Divider />
        <Spin loading={state.loading || state.pricing}>{quote ? <><Descriptions column={1} data={quote.unit_breakdown.filter(row => row.amount_minor > 0).map(row => ({ label: row.label, value: `¥${formatMinor(row.amount_minor)}` }))} /><Divider /><Space direction="vertical"><Typography.Text type="secondary">每台 ¥{formatMinor(quote.unit_amount_minor)} × {quote.quantity} 台</Typography.Text>{quote.discount_minor > 0 && <Typography.Text type="secondary">周期优惠 ¥{formatMinor(quote.discount_minor)}</Typography.Text>}<Typography.Title heading={3}>¥{formatMinor(quote.amount_minor)}</Typography.Title></Space></> : <Typography.Text type="secondary">完成选配后显示费用</Typography.Text>}</Spin>
        <Button long type="primary" className="purchase-submit" disabled={!quote || state.loading || state.pricing || quote.amount_minor <= 0} loading={state.submitting} onClick={() => void state.submit()}>{state.member ? '提交订单' : '登录后继续'}</Button>
        {!state.member && <p className="purchase-hint">登录后保留当前选配结果</p>}
      </Card>
    </div>}
  </main>
}
