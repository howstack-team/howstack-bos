import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Drawer, Form, Message, Modal, Space, Spin, Tabs, Typography } from '@arco-design/web-react'
import { ApiRequestError } from '../../api/httpClient'
import { MESSAGE_CHANNEL_LABELS, messageSceneApi, type MessageRecipientGroup, type MessageSceneDetail } from '../../api/messageSceneApi'
import type { MessageAudience, MessageChannel, MessageChannelValues } from '../../api/messageConfigurationApi'
import MessageChannelFields from './MessageChannelFields'
import { COMMUNITY_CHANNELS, communityChannelGroups, communityGroupKind, hasCustomRecipients } from './communityMessageConfiguration'
import './CommunityMessageEditor.css'

interface Props { code: string; audience: MessageAudience; activeChannel?: string; canSave: boolean; onChannelChange: (channel: string) => void; onClose: () => void; onSaved: () => void }
interface Values { channels: Partial<Record<MessageChannel, MessageChannelValues>> }

/** 社区版直接编辑渠道；完整策略读取后按修订保存，只合并实际改动，保留另一受众及隐藏高级组。 */
export default function CommunityMessageEditor({ code, audience, activeChannel, canSave, onChannelChange, onClose, onSaved }: Props) {
  const [form] = Form.useForm<Values>()
  const [detail, setDetail] = useState<MessageSceneDetail | null>(null), [groups, setGroups] = useState<MessageRecipientGroup[]>([])
  const [loading, setLoading] = useState(true), [saving, setSaving] = useState(false), [error, setError] = useState(''), [dirty, setDirty] = useState(false), [reload, setReload] = useState(0)
  const mounted = useRef(true), lock = useRef(false), changed = useRef(new Set<MessageChannel>()), switchChanged = useRef(new Set<MessageChannel>())
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  useEffect(() => {
    const controller = new AbortController()
    void messageSceneApi.get(code, controller.signal).then(value => {
      if (controller.signal.aborted) return
      if (!value.metadata[audience]) { setError('此消息不支持当前接收对象。'); return }
      const next = communityChannelGroups(value, audience)
      setDetail(value); setGroups(next)
      form.setFieldsValue({ channels: Object.fromEntries(COMMUNITY_CHANNELS[audience].map(channel => {
        const group = next.find(item => item.kind === communityGroupKind(audience, channel))
        const config = group?.configurations[channel]
        return [channel, config ? { ...config, enabled: group?.enabled === true && config.enabled } : undefined]
      }).filter(([, config]) => config)) })
    }).catch(cause => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : '消息配置读取失败') })
      .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [code, audience, form, reload])
  const channels = COMMUNITY_CHANNELS[audience].filter(channel => detail?.metadata[audience]?.channels.includes(channel))
  const selected = channels.find(channel => channel === activeChannel) ?? channels[0]
  const close = () => dirty ? Modal.confirm({ maskClosable: true, title: '放弃未保存的修改？', content: '关闭后，本次尚未保存的修改将丢失。', onOk: onClose }) : onClose()
  async function save() {
    if (!detail || !canSave || lock.current) return
    if (!changed.current.size) { onClose(); return }
    let values: Values
    try { values = await form.validate() } catch { return }
    lock.current = true; setSaving(true); setError('')
    const next = structuredClone(groups)
    for (const channel of changed.current) {
      const kind = communityGroupKind(audience, channel), group = next.find(item => item.kind === kind)
      if (!group || hasCustomRecipients(next, kind)) continue
      const edit = { ...values.channels[channel] }
      if (!switchChanged.current.has(channel)) delete edit.enabled
      group.configurations[channel] = { ...group.configurations[channel], ...edit }
      if (switchChanged.current.has(channel) && edit.enabled) group.enabled = true
    }
    // 未触碰的新增普通组不写入，避免仅编辑邮件时重新启用被主动清空的其他接收策略。
    const savedGroups = next.filter(group => detail.groups.some(original => original.id === group.id)
      || [...changed.current].some(channel => communityGroupKind(audience, channel) === group.kind))
    try {
      await messageSceneApi.save(code, { revisions: detail.revisions, groups: savedGroups })
      if (mounted.current) { Message.success('消息配置已保存'); onSaved() }
    } catch (cause) {
      if (!mounted.current) return
      const fields = cause instanceof ApiRequestError && cause.data && typeof cause.data === 'object' && 'errors' in cause.data ? cause.data.errors : null
      if (fields && typeof fields === 'object') for (const [field, messages] of Object.entries(fields)) {
        const parts = field.split('.'), group = savedGroups[Number(parts[1])], channel = parts[3] as MessageChannel
        if (group && group.kind === communityGroupKind(audience, channel) && channels.includes(channel) && Array.isArray(messages) && typeof messages[0] === 'string') {
          form.setFields({ [`channels.${channel}.${parts.slice(4).join('.')}`]: { error: { message: messages[0] } } }); onChannelChange(channel)
        }
      }
      setError(cause instanceof Error ? cause.message : '保存失败')
    } finally { lock.current = false; if (mounted.current) setSaving(false) }
  }
  return <Drawer maskClosable visible title={`${canSave ? '编辑' : '查看'}消息 · ${detail?.metadata[audience]?.name ?? '消息配置'}`} width="min(680px, calc(100vw - 24px))" onCancel={close}
    footer={<Space style={{ display: 'flex', justifyContent: 'flex-end' }}><Button onClick={close}>取消</Button>{canSave && <Button type="primary" loading={saving} disabled={loading || !detail} onClick={() => void save()}>保存</Button>}</Space>}>
    <div className="community-message-editor">
      {error && <Alert type="error" content={error} action={!detail ? <Button onClick={() => { setLoading(true); setError(''); setReload(value => value + 1) }}>重新加载</Button> : undefined} style={{ marginBottom: 16 }} />}
      <Spin loading={loading} style={{ width: '100%' }}>{detail && <>
        <Typography.Paragraph type="secondary" style={{ marginBottom: 16 }}>{detail.metadata[audience]?.description}</Typography.Paragraph>
        <Form form={form} layout="vertical" disabled={!canSave || saving} onChange={changes => {
          // Arco 的字段变更键为点路径；只记录用户操作，回填不标记为改动。
          for (const field of Object.keys(changes)) {
            const [, name, key] = field.split('.'), channel = name as MessageChannel
            if (!channels.includes(channel)) continue
            changed.current.add(channel)
            if (key === 'enabled') switchChanged.current.add(channel)
          }
          setDirty(true)
        }} onSubmit={() => void save()}>
          <Tabs activeTab={selected} onChange={onChannelChange} lazyload={false} destroyOnHide={false}>
            {channels.map(channel => {
              const kind = communityGroupKind(audience, channel), group = groups.find(item => item.kind === kind), custom = hasCustomRecipients(groups, kind)
              const config = group?.configurations[channel]
              if (!config) return null
              return <Tabs.TabPane key={channel} title={MESSAGE_CHANNEL_LABELS[channel]}>
                {custom && <Alert type="info" content="此渠道已设置自定义接收规则，当前页面仅展示配置，原有规则会继续保留。" style={{ marginBottom: 20 }} />}
                <div className="community-message-fields">
                  <MessageChannelFields prefix={`channels.${channel}`} channel={channel} values={config} detail={detail} audience={audience} disabled={!canSave || saving || custom} />
                </div>
              </Tabs.TabPane>
            })}
          </Tabs>
        </Form>
      </>}</Spin>
    </div>
  </Drawer>
}
