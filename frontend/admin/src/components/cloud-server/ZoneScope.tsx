import ZoneCascader from './ZoneCascader'
import type { ReactNode } from 'react'
import { Form } from '@arco-design/web-react'
import { useCloudWorkspace } from './workspace'

/** 分类与可用区处于同一上下文行，分类切换不丢失可用区。 */
export default function ZoneScope({ children, label, enabled = true }: { children: ReactNode; label: string; enabled?: boolean }) {
  const state = useCloudWorkspace()
  return <div className="cloud-context-bar">
    <div className="cloud-context-categories">{children}</div>
    {enabled && <Form layout="inline" className="cloud-context-zone" style={{ width: 'auto', maxWidth: '100%' }}><Form.Item label="可用区" style={{ marginBottom: 0 }}>
      <ZoneCascader aria-label={label} placeholder="请选择可用区" value={state.zoneId} regions={state.resources.regions} zones={state.resources.zones}
        onChange={value => { if (value !== undefined) state.setZone(value) }} style={{ width: 220, maxWidth: '100%' }} />
    </Form.Item></Form>}
  </div>
}
