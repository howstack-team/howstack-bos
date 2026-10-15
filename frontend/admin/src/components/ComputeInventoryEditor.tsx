import ZoneCascader from './cloud-server/ZoneCascader'
import { useEffect, useState } from 'react'
import { Alert, Descriptions, Drawer, Form, InputNumber, Message, Spin, Switch } from '@arco-design/web-react'
import { cloudError, cloudProductApi, type ComputeInventoryPool, type ComputeResource } from '../api/cloudProductApi'

/** 配额实时管理，不将库存写入销售快照。 */
export default function ComputeInventoryEditor({ flavor, zones, regions, initialZoneId, onClose, onSaved }: { flavor: ComputeResource; zones: ComputeResource[]; regions: ComputeResource[]; initialZoneId?: number; onClose: () => void; onSaved?: (pool: ComputeInventoryPool) => void }) {
  const [pools, setPools] = useState<ComputeInventoryPool[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [zone, setZone] = useState(initialZoneId || flavor.zone_ids?.[0] || flavor.zone_id)
  const [edited, setEdited] = useState<{ limited: boolean; capacity: number }>()
  const pool = pools.find(p => p.zone_id === zone && p.flavor_id === flavor.id)
  useEffect(() => { let active = true; cloudProductApi.inventory().then(rows => { if (active) setPools(rows) }).catch(e => { if (active) setError(cloudError(e)) }).finally(() => { if (active) setLoading(false) }); return () => { active = false } }, [])
  const limited = edited?.limited ?? pool?.capacity !== null
  const capacity = edited?.capacity ?? pool?.capacity ?? 0
  const save = async () => {
    if (!zone || saving || loading || error) return
    setSaving(true)
    try { const saved = await cloudProductApi.saveInventory({ zone_id: zone, flavor_id: flavor.id, capacity: limited ? capacity : null, revision: pool?.revision ?? 0 }); onSaved?.(saved); Message.success('库存已保存'); setEdited(undefined); setPools(await cloudProductApi.inventory()) }
    catch (e) { Message.error(cloudError(e)); try { setPools(await cloudProductApi.inventory()) } catch { setError('库存刷新失败，请重新打开') } }
    finally { setSaving(false) }
  }
  return <Drawer visible title={`库存 · ${flavor.name}`} width={560} onCancel={onClose} onOk={() => void save()} confirmLoading={saving} okButtonProps={{ disabled: loading || !!error }}>
    {error && <Alert type="error" content={error} />}
    <Spin loading={loading} style={{ display: 'block' }}><Form layout="vertical">
      <Form.Item label="可用区"><ZoneCascader placeholder="请选择可用区" value={zone} regions={regions} zones={zones.filter(row => (flavor.zone_ids || [flavor.zone_id]).includes(row.id))} onChange={value => { setZone(value); setEdited(undefined) }} /></Form.Item>
      <Form.Item label="限制库存"><Switch type="round" checkedText="限制" uncheckedText="不限" checked={limited} onChange={value => setEdited({ limited: value, capacity })} /></Form.Item>
      {limited && <Form.Item label="库存总配额" extra="不能低于预占与正式占用之和，0表示售罄。"><InputNumber mode="button" min={0} max={100000000} precision={0} suffix="台" placeholder="请输入库存总配额" value={capacity} onChange={v => setEdited({ limited, capacity: v ?? 0 })} style={{ width: '100%' }} /></Form.Item>}
      <Descriptions column={1} data={[{ label: '预占', value: `${pool?.reserved ?? 0}台` }, { label: '占用', value: `${pool?.occupied ?? 0}台` }, { label: '可售', value: !pool ? '尚未配置' : pool.available === null ? '不限制' : `${pool.available}台` }]} />
      <TypographyNote />
    </Form></Spin>
  </Drawer>
}
function TypographyNote() { return <Alert content="库存按可用区和规格管理；已售资源确认释放后才恢复可售数量。" /> }
