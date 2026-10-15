import ZoneCascader from './cloud-server/ZoneCascader'
import { Button, Form, Input, InputNumber, Link, Select, Table, Typography } from '@arco-design/web-react'
import { IconPlus } from '@arco-design/web-react/icon'
import type { CloudDraft, ResourceData, DurationOption } from '../api/cloudProductApi'

export function PrepaidDurationEditor({ value, onChange, disabled = false }: { value: DurationOption[]; onChange: (v: DurationOption[]) => void; disabled?: boolean }) {
  const update = (index: number, patch: Partial<DurationOption>) => onChange(value.map((row, i) => i === index ? { ...row, ...patch } : row))
  return <section>
    <Typography.Paragraph type="secondary">按月设置服务期限；100% 为原价、80% 为 8 折，也可指定实际计费月数。</Typography.Paragraph>
    <Table rowKey="index" pagination={false} scroll={{ x: 720 }}
      data={value.map((row, index) => ({ ...row, index }))}
      columns={[
        { title: '周期长度', width: 155, render: (_, row) => <InputNumber disabled={disabled} aria-label="周期长度" mode="button" min={1} max={120} precision={0} suffix="月" placeholder="请输入月数" value={row.months} onChange={v => update(row.index, { months: v ?? 1 })} /> },
        { title: '显示名称', width: 155, render: (_, row) => <Input disabled={disabled} aria-label="周期显示名称" placeholder="例如季付、年付" maxLength={32} value={row.label} onChange={label => update(row.index, { label })} /> },
        { title: '优惠方式', width: 175, render: (_, row) => <Select disabled={disabled} aria-label="周期优惠方式" placeholder="请选择优惠方式" value={row.discount_kind} options={[{ value: 'rate', label: '折扣比例' }, { value: 'billed_months', label: '按指定月数收费' }]} onChange={discount_kind => update(row.index, { discount_kind })} /> },
        { title: '优惠值', width: 165, render: (_, row) => <InputNumber disabled={disabled} aria-label={row.discount_kind === 'rate' ? '折扣比例' : '计费月数'} mode="button" min={row.discount_kind === 'rate' ? 0.01 : 1} max={row.discount_kind === 'rate' ? 100 : row.months} precision={row.discount_kind === 'rate' ? 2 : 0} suffix={row.discount_kind === 'rate' ? '%' : '月'} placeholder="请输入优惠值" value={row.discount_kind === 'rate' ? (row.rate_bps ?? 10000) / 100 : row.billed_months ?? row.months} onChange={v => update(row.index, row.discount_kind === 'rate' ? { rate_bps: Math.round((v ?? 100) * 100) } : { billed_months: v ?? row.months })} /> },
        { title: '操作', width: 70, render: (_, row) => <Link status="error" href="#" disabled={disabled || value.length <= 1} onClick={event => { event.preventDefault(); if (!disabled && value.length > 1) onChange(value.filter((_, i) => i !== row.index)) }}>删除</Link> },
      ]} />
    <Button long style={{ marginTop: 16 }} icon={<IconPlus />} disabled={disabled || value.length >= 24} onClick={() => onChange([...value, { months: Math.min(120, Math.max(...value.map(v => v.months)) + 1), label: '', discount_kind: 'rate', rate_bps: 10000 }])}>增加周期</Button>
  </section>
}

export function PrepaidStorageEditor({ draft, resources, onChange }: { draft: CloudDraft; resources: ResourceData; onChange: (v: CloudDraft) => void }) {
  const policy = draft.storage_policy || { default_type_id: 0, default_size_gib: 40, max_data_disks: 8 }
  const update = (patch: Partial<typeof policy>) => onChange({ ...draft, storage_policy: { ...policy, ...patch } })
  return <div className="cloud-form-grid">
    <Form.Item label="默认系统盘类型"><Select allowClear placeholder="按可用区自动选择" value={policy.default_type_id || undefined} options={resources.disk_types.filter(v => draft.resource_ids.disk_types.includes(v.id)).map(v => ({ value: v.id, label: v.name }))} onChange={v => update({ default_type_id: v || 0 })} /></Form.Item>
    <Form.Item label="默认系统盘容量"><InputNumber mode="button" min={1} max={65536} precision={0} suffix="GiB" placeholder="请输入默认容量" value={policy.default_size_gib} onChange={v => update({ default_size_gib: v ?? 40 })} /></Form.Item>
    <Form.Item label="每台最多数据盘"><InputNumber mode="button" min={0} max={32} precision={0} suffix="块" placeholder="请输入数量上限" value={policy.max_data_disks} onChange={v => update({ max_data_disks: v ?? 0 })} /></Form.Item>
  </div>
}

export function PrepaidTrialEditor({ draft, resources, onChange }: { draft: CloudDraft; resources: ResourceData; onChange: (v: CloudDraft) => void }) {
  const scope = draft.trial_scope!
  if (!scope || !draft.trial_policy.enabled) return null
  const update = (patch: Partial<typeof scope>) => onChange({ ...draft, trial_scope: { ...scope, ...patch } })
  return <div>
    <Typography.Text type="secondary">资源范围留空表示允许全部可售项，防护留空则不提供附加防护；次数、同时试用和冷却限制由交易阶段执行。</Typography.Text>
    <div className="cloud-form-grid">
      {([{ key: 'zone_ids', kind: 'zones', label: '试用可用区' }, { key: 'flavor_ids', kind: 'flavors', label: '试用规格' }, { key: 'line_ids', kind: 'lines', label: '试用线路' }, { key: 'image_ids', kind: 'images', label: '试用镜像' }, { key: 'disk_type_ids', kind: 'disk_types', label: '试用存储类型' }, { key: 'protection_ids', kind: 'protections', label: '试用防护' }] as const).map(({ key, kind, label }) => <Form.Item key={key} label={label}>{kind === 'zones' ? <ZoneCascader multiple allowClear placeholder="不限（限可售范围）" value={scope[key] || []} regions={resources.regions} zones={resources.zones.filter(row => draft.resource_ids.zones.includes(row.id))} onChange={v => update({ [key]: v })} /> : <Select mode="multiple" allowClear placeholder={key === 'protection_ids' ? '不包含附加防护' : '不限（限可售范围）'} value={scope[key] || []} options={resources[kind].filter(row => draft.resource_ids[kind].includes(row.id)).map(row => ({ value: row.id, label: row.name }))} onChange={v => update({ [key]: v })} />}</Form.Item>)}
      {([{ key: 'max_system_disk_gib', label: '系统盘上限', max: 65536, unit: 'GiB' }, { key: 'max_data_disk_gib', label: '单块数据盘上限', max: 65536, unit: 'GiB' }, { key: 'max_data_disks', label: '数据盘数量上限', max: 32, unit: '块' }, { key: 'max_egress_mbps', label: '上行带宽上限', max: 1000000, unit: 'Mbps' }, { key: 'max_ingress_mbps', label: '下行带宽上限', max: 1000000, unit: 'Mbps' }, { key: 'max_ip_count', label: 'IP数量上限', max: 64, unit: '个' }, { key: 'concurrent_limit', label: '同时试用上限', max: 100, unit: '台' }, { key: 'cooldown_hours', label: '再次试用间隔', max: 8760, unit: '小时' }] as const).map(({ key, label, max, unit }) => <Form.Item key={key} label={label}><InputNumber mode="button" min={key === 'concurrent_limit' ? 1 : 0} max={max} precision={0} suffix={unit} placeholder={`请输入${label}`} value={scope[key]} onChange={v => update({ [key]: v ?? 0 })} /></Form.Item>)}
    </div>
  </div>
}
