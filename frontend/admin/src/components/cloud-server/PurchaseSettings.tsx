import ZoneCascader from './ZoneCascader'
import SectionTitle from '../SectionTitle'
import { Alert, Descriptions, Empty, Form, InputNumber, Link, Typography } from '@arco-design/web-react'
import { PrepaidDurationEditor } from '../PrepaidPolicyEditor'
import { useCloudWorkspace } from './workspace'
import { minorToYuanText } from '../../utils/money'

/** 云服务器仅维护购买限制和周期；非零历史开通费明确提示并可主动取消。 */
export default function PurchaseSettings() {
  const state = useCloudWorkspace()
  const draft = state.draft
  const zones = state.resources.zones.filter(row => row.status === 1 && draft.resource_ids.zones.includes(row.id))
  const zoneId = zones.some(row => row.id === state.zoneId) ? state.zoneId : zones[0]?.id
  const periods = draft.zone_durations?.find(row => row.zone_id === zoneId)?.durations ?? []
  return <Form layout="vertical" disabled={!state.editable}>
    <section className="cloud-settings-section">
      <Typography.Title heading={6}><SectionTitle>购买限制</SectionTitle></Typography.Title>
      <Descriptions column={2} data={[{ label: '计费模式', value: '包年包月' }, { label: '登录方式', value: '密码登录' }]} />
      <Form.Item style={{ maxWidth: 420 }} label="单次购买数量上限"><InputNumber mode="button" aria-label="单次购买数量上限" min={1} max={100} precision={0} suffix="台" placeholder="请输入数量上限" value={draft.quantity_limit} onChange={value => state.changeDraft({ ...draft, quantity_limit: value ?? 1 })} /></Form.Item>
      {draft.setup_minor > 0 && <Alert type="warning" content={`当前历史配置仍包含每台 ¥${minorToYuanText(draft.setup_minor)} 的一次性费用，继续保存将保留此费用。`}
        action={state.editable ? <Link onClick={() => state.changeDraft({ ...draft, setup_minor: 0 })}>取消一次性费用</Link> : undefined} />}
    </section>
    <section className="cloud-settings-section">
      <Typography.Title heading={6}><SectionTitle>付费周期</SectionTitle></Typography.Title>
      {zones.length > 0 ? <>
        <div className="cloud-period-context"><Form.Item label="可用区" style={{ width: 320, maxWidth: '100%', marginBottom: 0 }}>
          <ZoneCascader aria-label="付费周期可用区" placeholder="请选择可用区" value={zoneId} disabled={false} regions={state.resources.regions} zones={zones}
            onChange={value => { if (value !== undefined) state.setZone(value) }} style={{ maxWidth: 320 }} />
        </Form.Item><Typography.Text type="secondary">每个可用区独立设置周期和优惠</Typography.Text></div>
        <PrepaidDurationEditor disabled={!state.editable} value={periods} onChange={durations => state.changeDraft({ ...draft, zone_durations: (draft.zone_durations ?? []).map(row => row.zone_id === zoneId ? { ...row, durations } : row) })} />
      </> : <Empty description="请先在地域和可用区中选择参与购买的可用区" />}
    </section>
    <section className="cloud-settings-section">
      <Typography.Title heading={6}><SectionTitle>分项价格</SectionTitle></Typography.Title>
      <div className="cloud-price-locations">
        <Link disabled={!state.canNavigate('specification')} onClick={() => state.navigate('specification', 'flavors')}>规格月价 → 规格与套餐</Link>
        <Link disabled={!state.canNavigate('network')} onClick={() => state.navigate('network', 'lines')}>带宽、IP → 对应线路</Link>
        <Link disabled={!state.canNavigate('network')} onClick={() => state.navigate('network', 'protections')}>防护附加价 → 防护配置</Link>
        <Link disabled={!state.canNavigate('storage')} onClick={() => state.navigate('storage', 'disk_types')}>磁盘容量价 → 存储类型</Link>
      </div>
      <Typography.Text type="secondary">各项月价按所选周期与优惠汇总；可在顶部购买预览中试算。</Typography.Text>
    </section>
  </Form>
}
