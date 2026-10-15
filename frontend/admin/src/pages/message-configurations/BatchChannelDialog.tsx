import { useEffect, useRef, useState } from 'react'
import { Alert, Checkbox, Form, Link, Message, Select, Spin, Typography } from '@arco-design/web-react'
import { useHistory } from 'react-router-dom'
import FormDialog from '../../components/FormDialog'
import { useAdminAuth } from '../../auth/AuthContext'
import { channelPermission } from '../../api/channelApi'
import type { MessageAudience, MessageChannel } from '../../api/messageConfigurationApi'
import { messageSceneApi, MESSAGE_CHANNEL_LABELS, type MessageSceneDetail, type MessageSceneRow } from '../../api/messageSceneApi'
import { COMMUNITY_CHANNELS, communityGroupKind, supportsCommunityChannel } from './communityMessageConfiguration'

interface Props { audience: MessageAudience; rows: MessageSceneRow[]; onClose: () => void; onSaved: () => void }
interface Values { channel: MessageChannel; codes: string[]; channel_id?: number; state: 'keep' | 'enable' | 'disable' }

/** 先选渠道再选适用消息；默认不勾选，完整批量提交保留每条消息的模板与接收范围。 */
export default function BatchChannelDialog({ audience, rows, onClose, onSaved }: Props) {
  const eligibleRows = (channel: MessageChannel) => rows.filter(row => {
    const groups = row.groups.filter(group => group.kind === communityGroupKind(audience, channel))
    return supportsCommunityChannel(row, audience, channel) && groups.length === 1 && groups[0].enabled && groups[0].available_channels.includes(channel)
  })
  const channels = COMMUNITY_CHANNELS[audience].filter(channel => eligibleRows(channel).length > 0)
  const [form] = Form.useForm<Values>(), [channel, setChannel] = useState<MessageChannel | undefined>(channels[0]), [codes, setCodes] = useState<string[]>([])
  const [detail, setDetail] = useState<MessageSceneDetail | null>(null), [error, setError] = useState(''), [loading, setLoading] = useState(channels.length > 0), [saving, setSaving] = useState(false)
  const lock = useRef(false), mounted = useRef(true)
  const history = useHistory(), { permissions } = useAdminAuth()
  const applicable = channel ? eligibleRows(channel) : [], firstCode = applicable[0]?.code
  useEffect(() => {
    mounted.current = true
    const controller = new AbortController()
    if (!firstCode) { return () => { mounted.current = false; controller.abort() } }
    void messageSceneApi.get(firstCode, controller.signal).then(value => { if (!controller.signal.aborted) setDetail(value) })
      .catch(cause => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : '通道读取失败，请重新打开') })
      .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => { mounted.current = false; controller.abort() }
  }, [firstCode, channel])
  const sender = channel === 'email' || channel === 'sms'
  const available = channel && detail?.code === firstCode ? (detail.channel_options[channel] ?? []).filter(option => option.available) : []
  const missing = detail && channel && (sender ? available.length === 0 : Object.hasOwn(detail.robot_status, channel) && !detail.robot_status[channel])
  const permission = sender ? channelPermission(channel === 'email' ? 'mail' : 'sms', 'view') : 'admin.page.settings.view'
  const canConfigure = permissions.includes(permission), configPath = sender ? `/channels/${channel === 'email' ? 'mail' : 'sms'}` : '/settings'
  const setSelection = (next: string[]) => { setCodes(next); form.setFieldValue('codes', next) }
  async function save() {
    if (!detail || detail.code !== firstCode || error || lock.current || loading) return
    let values: Values
    try { values = await form.validate() } catch { return }
    const selected = eligibleRows(values.channel).filter(row => values.codes.includes(row.code))
    if (!selected.length || selected.length !== values.codes.length) { form.setFields({ codes: { error: { message: '请选择适用的消息' } } }); return }
    if (values.state === 'keep' && !values.channel_id) { form.setFields({ state: { error: { message: '请选择启用或禁用，或指定新的发送通道' } } }); return }
    if (missing && values.state === 'enable') { form.setFields({ state: { error: { message: '请先完成通道配置，再启用消息' } } }); return }
    lock.current = true; setSaving(true)
    try {
      await messageSceneApi.batch({ items: selected.map(row => ({ code: row.code, revisions: row.revisions })), kind: communityGroupKind(audience, values.channel), channel: values.channel,
        ...(sender && values.channel_id ? { channel_id: values.channel_id } : {}), ...(values.state !== 'keep' ? { enabled: values.state === 'enable' } : {}) })
      if (mounted.current) { Message.success(`已配置 ${selected.length} 条消息`); onSaved() }
    } catch (cause) { if (mounted.current) setError(cause instanceof Error ? cause.message : '批量配置失败，请重新打开') }
    finally { lock.current = false; if (mounted.current) setSaving(false) }
  }
  return <FormDialog title={`批量配置 · ${audience === 'customer' ? '发送给客户' : '发送给管理员'}`} visible onCancel={onClose} onOk={() => void save()} confirmLoading={saving} okDisabled={loading || Boolean(error) || !codes.length || !channels.length} width={480}>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    {!channels.length && <Alert type="warning" content="当前没有可批量修改的渠道，请分别编辑消息。" style={{ marginBottom: 16 }} />}
    <Form form={form} layout="vertical" initialValues={{ channel: channels[0], codes: [], state: 'keep' }} disabled={saving}>
      <Form.Item field="channel" label="消息渠道" rules={[{ required: true, message: '请选择消息渠道' }]}>
        <Select placeholder="请选择要配置的渠道" options={channels.map(value => ({ value, label: MESSAGE_CHANNEL_LABELS[value] }))} onChange={value => {
          if (value === channel) return
          setChannel(value as MessageChannel); setSelection([]); setDetail(null); setError(''); setLoading(true); form.setFieldValue('channel_id', undefined)
        }} />
      </Form.Item>
      <Form.Item field="codes" label="适用消息" extra="仅列出支持当前渠道、可批量修改的消息。" rules={[{ required: true, message: '请选择消息' }]}>
        <Select placeholder="请选择需要修改的消息" mode="multiple" allowClear maxTagCount={2} showSearch options={applicable.map(row => ({ value: row.code, label: row.metadata[audience]?.name ?? row.name }))} onChange={(value: string[]) => setCodes(value)}
          dropdownRender={menu => <><div style={{ padding: '8px 12px', borderBottom: '1px solid var(--color-border-2)' }}><Checkbox disabled={saving} checked={codes.length > 0 && codes.length === applicable.length} indeterminate={codes.length > 0 && codes.length < applicable.length}
            onChange={checked => setSelection(checked ? applicable.map(row => row.code) : [])}>全部适用消息（{applicable.length}）</Checkbox></div>{menu}</>} />
      </Form.Item>
      <Spin loading={loading} style={{ width: '100%' }}>
        {missing && !loading && <Alert type="warning" content={<span>{canConfigure ? '暂无可用渠道，请先配置并启用。' : '暂无可用渠道，请联系管理员配置。'}{' '}{canConfigure && <Link href={history.createHref({ pathname: configPath, search: sender ? '' : '?tab=notifications' })} target="_blank" rel="noopener noreferrer">去配置</Link>}</span>} style={{ marginBottom: 16 }} />}
        {sender && <Form.Item field="channel_id" label="发送通道" tooltip="不选择时保留各消息原通道，仅显示可用通道。"><Select disabled={loading || saving} placeholder="请选择发送通道（可不修改）" allowClear options={available.map(option => ({ value: option.id, label: option.name }))} /></Form.Item>}
        <Form.Item field="state" label="启用状态" rules={[{ required: true, message: '请选择启用状态' }]}><Select disabled={loading || saving} placeholder="请选择启用状态" options={[{ value: 'keep', label: '保持原状态' }, { value: 'enable', label: '启用' }, { value: 'disable', label: '禁用' }]} /></Form.Item>
      </Spin>
    </Form>
    <Typography.Text type="secondary">将修改 {codes.length} 条消息的{channel ? MESSAGE_CHANNEL_LABELS[channel] : '渠道'}配置，不更改消息模板。</Typography.Text>
  </FormDialog>
}
