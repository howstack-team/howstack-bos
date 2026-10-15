import { Cascader } from '@arco-design/web-react'
import type { CascaderProps } from '@arco-design/web-react'
import type { ComputeResource } from '../../api/cloudProductApi'

type ZoneCascaderProps = Omit<CascaderProps, 'value' | 'defaultValue' | 'onChange' | 'options' | 'mode' | 'checkedStrategy' | 'changeOnSelect'> & {
  regions: ComputeResource[]
  zones: ComputeResource[]
} & ({ multiple: true; value?: number[]; onChange?: (value: number[]) => void }
  | { multiple?: false; value?: number; onChange?: (value: number | undefined) => void })

/** 界面使用地域 → 可用区路径；表单与 API 始终使用原有可用区 ID。 */
export default function ZoneCascader(props: ZoneCascaderProps) {
  const { regions, zones, value, onChange: _onChange, multiple, ...rest } = props
  const selected = typeof value === 'number' ? [value] : value ?? []
  const ungrouped = zones.filter(zone => !regions.some(region => region.id === zone.region_id))
  const missing = selected.filter(id => !zones.some(zone => zone.id === id))
  const zoneOption = (zone: ComputeResource) => ({ value: `zone-${zone.id}`, label: zone.name })
  const options = regions.map(region => {
    const children = zones.filter(zone => zone.region_id === region.id).map(zoneOption)
    return { value: `region-${region.id}`, label: region.name, children, disabled: !children.length }
  })
  if (ungrouped.length || missing.length) options.push({ value: 'region-unknown', label: '未归属地域', disabled: false,
    children: [...ungrouped.map(zoneOption), ...missing.map(id => ({ value: `zone-${id}`, label: `可用区 #${id}（已失效）`, disabled: true }))] })
  const path = (id: number) => {
    const zone = zones.find(row => row.id === id)
    const region = regions.find(row => row.id === zone?.region_id)
    return [region ? `region-${region.id}` : 'region-unknown', `zone-${id}`]
  }
  return <Cascader {...rest} placeholder={rest.placeholder ?? '请选择地域和可用区'} options={options}
    mode={multiple ? 'multiple' : undefined} checkedStrategy="child" showSearch={rest.showSearch ?? true}
    value={multiple ? selected.map(path) : selected.length ? path(selected[0]) : []}
    onChange={paths => {
      const ids = (multiple ? paths as string[][] : [paths as string[]]).flatMap(item => {
        const leaf = item[item.length - 1]
        return typeof leaf === 'string' && /^zone-\d+$/.test(leaf) ? [Number(leaf.slice(5))] : []
      })
      if (props.multiple === true) props.onChange?.([...new Set(ids)])
      else props.onChange?.(ids[0])
    }} />
}
