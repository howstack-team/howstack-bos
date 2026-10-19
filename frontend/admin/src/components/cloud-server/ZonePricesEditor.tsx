import { Table, Typography } from '@arco-design/web-react'
import { CYCLE_LABELS, type BillingCycle, type ComputeResource, type Prices } from '../../api/cloudProductApi'
import { minorToYuanText } from '../../utils/money'
import PriceInput from '../PriceInput'

/** 留空继承默认价格；使用 undefined 而非真假判断，保留显式零价。 */
export default function ZonePricesEditor({ value = {}, onChange, zoneIds, zones, regions, defaults, cycles, unit }: {
  value?: Record<number, Prices>; onChange?: (value: Record<number, Prices>) => void
  zoneIds: number[]; zones: ComputeResource[]; regions: ComputeResource[]; defaults: Prices; cycles: BillingCycle[]; unit: string
}) {
  const change = (id: number, cycle: BillingCycle, amount: number | undefined) => {
    const next = { ...value }, prices = { ...next[id] }
    if (amount === undefined) delete prices[cycle]; else prices[cycle] = amount
    if (Object.keys(prices).length) next[id] = prices; else delete next[id]
    onChange?.(next)
  }
  return <>
    <Typography.Paragraph type="secondary">留空继承默认价格，填写 0 表示免费。移除关联可用区会清除该区独立价格。</Typography.Paragraph>
    <Table rowKey="id" pagination={false} scroll={{ x: cycles.length > 1 ? 1000 : 520 }} data={zoneIds.map(id => zones.find(zone => zone.id === id) ?? { id, name: `失效可用区 #${id}`, region_id: undefined, code: '', description: '', status: 0, sort: 0 })}
      columns={[
        { title: '可用区', width: 180, ellipsis: true, render: (_, zone) => [regions.find(region => region.id === zone.region_id)?.name, zone.name].filter(Boolean).join(' / ') },
        ...cycles.map(cycle => ({ title: `${CYCLE_LABELS[cycle]}单价（${unit}）`, width: 230, render: (_: unknown, zone: ComputeResource) => <PriceInput
          aria-label={`${zone.name}${CYCLE_LABELS[cycle]}单价`} value={value[zone.id]?.[cycle]} onChange={amount => change(zone.id, cycle, amount)} unit={unit} placeholder="留空继承默认价" /> })),
        ...(cycles.length === 1 ? [{ title: '当前单价', width: 130, render: (_: unknown, zone: ComputeResource) => `${minorToYuanText(value[zone.id]?.[cycles[0]] ?? defaults[cycles[0]] ?? 0)}（${value[zone.id]?.[cycles[0]] === undefined ? '继承' : '独立'}）` }] : []),
      ]} />
  </>
}
