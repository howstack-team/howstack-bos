import SectionTitle from '../SectionTitle'
import { Empty, Form, InputNumber, Radio, Typography } from '@arco-design/web-react'
import { useLocation } from 'react-router-dom'
import ZoneScope from './ZoneScope'
import ComputeResourcesPage from '../../pages/ComputeResourcesPage'
import { PrepaidStorageEditor } from '../PrepaidPolicyEditor'
import { useCloudWorkspace } from './workspace'

/** 镜像、存储及默认系统盘规则统一在本分区维护。 */
export default function StoragePanel() {
  const state = useCloudWorkspace()
  const location = useLocation()
  const requested = new URLSearchParams(location.search).get('resource')
  const kind = requested === 'image_types' || requested === 'disk_types' ? requested : 'images'
  return <>
    <ZoneScope label="镜像和存储可用区" enabled={kind !== 'image_types'}>
    <Radio.Group aria-label="镜像和存储分类" type="button" className="cloud-resource-switch" value={kind}
      options={[{ value: 'images', label: '镜像' }, { value: 'image_types', label: '镜像分组' }, { value: 'disk_types', label: '存储类型' }]}
      onChange={value => state.navigate('storage', value)} />
    </ZoneScope>
    {!state.zoneId && kind !== 'image_types' ? <Empty description="请先配置地域和可用区" /> : <ComputeResourcesPage key={kind} kinds={[kind]} embedded workspace scopeZoneId={kind === 'image_types' ? undefined : state.zoneId} />}
    {kind === 'disk_types' && <section className="cloud-settings-section">
      <Typography.Title heading={6}><SectionTitle>系统盘与数据盘规则</SectionTitle></Typography.Title>
      <Form layout="vertical" className="cloud-policy-form" disabled={!state.editable}>
        <PrepaidStorageEditor draft={state.draft} resources={state.resources} onChange={state.changeDraft} />
        <Form.Item style={{ maxWidth: 420 }} label="系统盘赠送容量" extra="每台实例分别赠送，超出部分按所选存储类型计费。">
          <InputNumber mode="button" min={0} max={65536} precision={0} suffix="GiB" placeholder="请输入赠送容量" value={state.draft.system_disk_included_gib ?? 0}
            onChange={value => state.changeDraft({ ...state.draft, system_disk_included_gib: value ?? 0 })} />
        </Form.Item>
      </Form>
    </section>}
  </>
}
