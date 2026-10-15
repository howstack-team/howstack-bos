import { useState } from 'react'
import { Alert, Button, Form, Link, Select, Typography } from '@arco-design/web-react'
import { IconSettings } from '@arco-design/web-react/icon'
import { useHistory, useLocation } from 'react-router-dom'
import { useAdminAuth } from '../../auth/AuthContext'
import type { ComputeResource, ProviderParameterRule } from '../../api/cloudProductApi'
import ProviderParameterEditor from '../ProviderParameterEditor'
import FormDialog from '../FormDialog'
import { useCloudWorkspace } from './workspace'

/** 提供者在可用区就地选择；资源对应与高级参数分别提供上下文入口。 */
export default function DeploymentProvider({ zone, editorMode = 'drawer' }: { zone: ComputeResource; editorMode?: 'drawer' | 'modal' }) {
  const state = useCloudWorkspace()
  const history = useHistory()
  const location = useLocation()
  const { permissions } = useAdminAuth()
  const [advanced, setAdvanced] = useState(false)
  const [rules, setRules] = useState<ProviderParameterRule[]>([])
  const inherited = state.deliveryRules.find(rule => rule.id === state.draft.delivery_rule_id)
  const binding = state.draft.zone_provider_bindings?.find(item => item.zone_id === zone.id)
  const connectionId = binding?.connection_id ?? inherited?.connection_id ?? state.draft.provider_connection_id
  const available = state.connections.filter(row => row.status === 1 && row.adapter_available && row.adapter_accepting && !row.requires_retest && row.capabilities.product_types.includes('cloud_server'))
  const missing = connectionId > 0 && !available.some(row => row.id === connectionId)
  const update = (id: number, mapping = binding?.parameter_mapping ?? inherited?.parameter_mapping ?? []) => {
    state.changeDraft({ ...state.draft, zone_provider_bindings: [...(state.draft.zone_provider_bindings ?? []).filter(item => item.zone_id !== zone.id), { zone_id: zone.id, connection_id: id, parameter_mapping: mapping }] })
  }
  const map = () => {
    const params = new URLSearchParams(location.search)
    params.set('mapping', String(connectionId))
    params.delete('edit'); params.delete('zone-config')
    history.push({ ...location, search: params.toString() })
  }
  return <div className="cloud-provider-setup">
    <Form layout="vertical" className="cloud-provider-form">
      <Form.Item label="提供者">
        <Select aria-label="可用区提供者" placeholder="请选择已测试并启用的提供者" value={connectionId || undefined} disabled={!state.editable}
          allowClear showSearch onChange={value => update(value || 0)} style={{ width: '100%' }}
          options={[...available.map(row => ({ value: row.id, label: row.name })), ...(missing ? [{ value: connectionId, label: `${state.connections.find(row => row.id === connectionId)?.name ?? '原提供者'}（当前不可用）`, disabled: true }] : [])]} />
      </Form.Item>
      {connectionId > 0 && permissions.includes('admin.provider-connections.view') && <Form.Item><Button icon={<IconSettings />} onClick={map}>资源对应</Button></Form.Item>}
      {connectionId > 0 && <Form.Item><Link disabled={!state.editable} onClick={() => { setRules(binding?.parameter_mapping ?? inherited?.parameter_mapping ?? []); setAdvanced(true) }}>高级参数</Link></Form.Item>}
    </Form>
    {!available.length && <Alert type="warning" content={<div className="cloud-inline-notice"><span>暂无可用提供者，请先配置、测试并启用。</span>{permissions.includes('admin.provider-connections.view') && <Link onClick={() => history.push('/providers/connections')}>去配置提供者</Link>}</div>} />}
    {missing && available.length > 0 && <Typography.Paragraph type="warning">原提供者当前不可用，请检查连接或重新选择。</Typography.Paragraph>}
    <FormDialog mode={editorMode} width={860} visible={advanced} title={`高级参数 · ${zone.name}`} onCancel={() => setAdvanced(false)} onOk={() => { update(connectionId, rules); setAdvanced(false) }}>
      <Form layout="vertical"><Form.Item label="提供者参数对应" extra="用于单位或枚举转换；无需额外转换时留空。保存配置后生效。"><ProviderParameterEditor value={rules} onChange={setRules} /></Form.Item></Form>
    </FormDialog>
  </div>
}
