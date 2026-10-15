import { Button, Form, InputNumber, Link, Select, Table, Tooltip } from '@arco-design/web-react'
import { IconPlus } from '@arco-design/web-react/icon'
import { CYCLE_LABELS, type BillingCycle, type CloudDraft, type BandwidthTier } from '../api/cloudProductApi'
import PriceInput from './PriceInput'

/** 销售带宽价格；上限包含当前值，空上限表示最后一段不额外封顶。 */
export default function BandwidthPricingEditor({ network, cycles, onChange }: { network: CloudDraft['network']; cycles: BillingCycle[]; onChange: (network: CloudDraft['network']) => void }) {
  const mode = network.bandwidth_billing_mode || 'flat'
  const tiers = network.bandwidth_tiers || []
  return <div className="cloud-section">
    <Form.Item label="带宽计费方式" extra={mode === 'flat' ? undefined : mode === 'graduated' ? '按区间分别累计。例如前 5 Mbps 每 Mbps 1.00 元，超过部分 0.80 元，10 Mbps 共 9.00 元。' : '根据总带宽所在区间，全部带宽采用该段单价。例如 10 Mbps 落在 0.80 元档，总价为 8.00 元。'}><Select placeholder="请选择带宽计费方式" value={mode} options={[{ value: 'flat', label: '固定单价' }, { value: 'graduated', label: '按区间累进' }, { value: 'volume', label: '按整段单价' }]} onChange={(value: NonNullable<CloudDraft['network']['bandwidth_billing_mode']>) => onChange({ ...network, bandwidth_billing_mode: value, bandwidth_tiers: value !== 'flat' && !tiers.length ? [{ up_to_mbps: 5, prices: { hour: 0, day: 0, month: 0, year: 0 } }, { up_to_mbps: null, prices: { hour: 0, day: 0, month: 0, year: 0 } }] : tiers })} /></Form.Item>
    {mode !== 'flat' && <>
      <div className="table-scroll"><Table<BandwidthTier & { index: number }> rowKey="index" pagination={false} scroll={{ x: 300 + cycles.length * 180 }} data={tiers.map((tier, index) => ({ ...tier, index }))} columns={[
        { title: <Tooltip content="上限包含当前值；最后一段可留空，表示不额外封顶。">带宽上限</Tooltip>, width: 220, render: (_, row) => <InputNumber mode="button" aria-label={`第 ${row.index + 1} 段带宽上限`} min={1} max={1000000} precision={0} value={row.up_to_mbps ?? undefined} placeholder="请输入上限" suffix="Mbps" onChange={(value) => onChange({ ...network, bandwidth_tiers: tiers.map((tier, index) => index === row.index ? { ...tier, up_to_mbps: value ?? null } : tier) })} /> },
        ...cycles.map((cycle) => ({ title: `${CYCLE_LABELS[cycle]}单价`, width: 180, render: (_: unknown, row: { index: number }) => <PriceInput aria-label={`第 ${row.index + 1} 段${CYCLE_LABELS[cycle]}单价`} unit="元 / Mbps" value={tiers[row.index].prices[cycle]} onChange={(value) => onChange({ ...network, bandwidth_tiers: tiers.map((tier, index) => index === row.index ? { ...tier, prices: { ...tier.prices, [cycle]: value ?? 0 } } : tier) })} /> })),
        { title: '操作', width: 104, render: (_, row) => <Link onClick={() => onChange({ ...network, bandwidth_tiers: tiers.filter((_, index) => index !== row.index) })}>移除</Link> },
      ]} /></div>
      <Button icon={<IconPlus />} style={{ marginTop: 12 }} disabled={tiers.length >= 40} onClick={() => onChange({ ...network, bandwidth_tiers: [...tiers, { up_to_mbps: null, prices: { hour: 0, day: 0, month: 0, year: 0 } }] })}>添加价格区间</Button>
    </>}
  </div>
}
