import SectionTitle from './SectionTitle'
import { Button, Form, InputNumber, Link, Radio, Select, Table, Typography } from '@arco-design/web-react'
import { IconPlus } from '@arco-design/web-react/icon'
import { newLineNetwork, type LineNetwork } from '../api/cloudProductApi'
import PriceInput from './PriceInput'

/** 线路拥有上下行带宽、赠送、阶梯与IP；月费以整数分保存。 */
export default function LineNetworkEditor({ value, onChange }: { value?: LineNetwork | null; onChange?: (value: LineNetwork) => void }) {
  const network = value || newLineNetwork()
  const change = (next: LineNetwork) => onChange?.(next)
  const number = (label: string, current: number, update: (v: number) => void, min = 0, max = 1000000, suffix = 'Mbps') => <Form.Item label={label}><InputNumber mode="button" placeholder={`请输入${label}`} min={min} max={max} precision={0} suffix={suffix} value={current} onChange={v => update(v ?? min)} style={{ width: '100%' }} /></Form.Item>
  return <div>
    <Typography.Text type="secondary">固定带宽，按实例视角分别设置上下行。价格为月单价；赠送额度每台独立计算。</Typography.Text>
    {(['egress', 'ingress'] as const).map(direction => {
      const band = network[direction]
      const update = (patch: Partial<typeof band>) => change({ ...network, [direction]: { ...band, ...patch } })
      return <section className="cloud-section" key={direction}>
        <Typography.Title heading={6}><SectionTitle>{direction === 'egress' ? '上行带宽' : '下行带宽'}</SectionTitle></Typography.Title>
        <div className="cloud-form-grid">{(['min', 'max', 'step', 'default', 'included'] as const).map((key, i) => number(['最小带宽', '最大带宽', '调整步长', '默认带宽', '赠送带宽'][i], band[key], v => update({ [key]: v }), key === 'step' ? 1 : 0))}</div>
        <Form.Item label="价格算法"><Radio.Group type="button" value={band.pricing_mode} onChange={pricing_mode => update({ pricing_mode })} options={[{ label: '固定单价', value: 'flat' }, { label: '累进阶梯', value: 'graduated' }, { label: '整档单价', value: 'volume' }]} /></Form.Item>
        {band.pricing_mode === 'flat' ? <Form.Item label="月单价"><PriceInput unit="元 / Mbps" value={band.price_minor} onChange={v => update({ price_minor: v ?? 0 })} /></Form.Item> : <>
          <Typography.Paragraph type="secondary">上限包含边界，最后一段可留空。整档按实际带宽选档，再扣除赠送数量。</Typography.Paragraph>
          <Table rowKey="index" pagination={false} scroll={{ x: 620 }} data={band.tiers.map((tier, index) => ({ ...tier, index }))} columns={[
            { title: '阶梯上限', width: 270, render: (_, row) => <InputNumber mode="button" placeholder="最后一段可留空" aria-label="阶梯上限" suffix="Mbps" min={1} max={1000000} precision={0} value={row.up_to_mbps ?? undefined} onChange={v => update({ tiers: band.tiers.map((t, i) => i === row.index ? { ...t, up_to_mbps: v ?? null } : t) })} style={{ width: '100%' }} /> },
            { title: '月单价', width: 270, render: (_, row) => <PriceInput placeholder="请输入月单价" aria-label="阶梯月单价" unit="元 / Mbps" value={row.price_minor} onChange={v => update({ tiers: band.tiers.map((t, i) => i === row.index ? { ...t, price_minor: v ?? 0 } : t) })} style={{ width: '100%' }} /> },
            { title: '操作', width: 80, render: (_, row) => <Link status="error" href="#" onClick={event => { event.preventDefault(); update({ tiers: band.tiers.filter((_, i) => i !== row.index) }) }}>删除</Link> },
          ]} />
          <Button long style={{ marginTop: 16 }} icon={<IconPlus />} disabled={band.tiers.length >= 40} onClick={() => update({ tiers: [...band.tiers, { up_to_mbps: null, price_minor: 0 }] })}>增加阶梯</Button>
        </>}
      </section>
    })}
    <Typography.Title heading={6}><SectionTitle>IP数量</SectionTitle></Typography.Title>
    <Form.Item label="选择方式"><Radio.Group type="button" value={network.ip.mode} onChange={mode => change({ ...network, ip: { ...network.ip, mode } })} options={[{ label: '数量范围', value: 'range' }, { label: '固定选项', value: 'options' }]} /></Form.Item>
    {network.ip.mode === 'options' ? <Form.Item label="可选数量"><Select mode="multiple" placeholder="请选择允许的IP数量" value={network.ip.options} options={Array.from({ length: 65 }, (_, value) => ({ value, label: `${value}个` }))} onChange={options => change({ ...network, ip: { ...network.ip, options } })} /></Form.Item> : <div className="cloud-form-grid">{(['min', 'max', 'step'] as const).map((key, i) => number(['最少数量', '最多数量', '调整步长'][i], network.ip[key], v => change({ ...network, ip: { ...network.ip, [key]: v } }), key === 'step' ? 1 : 0, 64, '个'))}</div>}
    <div className="cloud-form-grid">{(['default', 'included'] as const).map((key, i) => number(['默认数量', '赠送数量'][i], network.ip[key], v => change({ ...network, ip: { ...network.ip, [key]: v } }), 0, 64, '个'))}</div>
    <Form.Item label="额外IP月单价"><PriceInput unit="元 / 个" value={network.ip.price_minor} onChange={v => change({ ...network, ip: { ...network.ip, price_minor: v ?? 0 } })} /></Form.Item>
  </div>
}
