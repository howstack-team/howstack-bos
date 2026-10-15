import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Dropdown, Empty, Form, Menu, Message, Modal, Space, Spin, Typography } from '@arco-design/web-react'
import { IconArrowDown, IconArrowUp, IconDelete, IconPlus } from '@arco-design/web-react/icon'
import { ApiRequestError } from '../../api/httpClient'
import { MESSAGE_GROUP_LABELS, MESSAGE_PURPOSE_LABELS, messageSceneApi, type MessageGroupKind, type MessageRecipientGroup, type MessageSceneDetail } from '../../api/messageSceneApi'
import PageFormEditor from '../../components/PageFormEditor'
import MessageGroupPanel, { type GroupFormValues } from './MessageGroupPanel'
import './MessageScenes.css'

interface Props { code: string; activeGroup?: string; activeChannel?: string; canSave: boolean; onNavigate: (group: string, channel?: string) => void; onClose: () => void; onSaved: () => void }

/** 多组、多模板配置使用独立页面；URL定位场景、组和渠道，返回时保留列表分页。 */
export default function MessageSceneEditor({ code, activeGroup, activeChannel, canSave, onNavigate, onClose, onSaved }: Props) {
  const [form] = Form.useForm<GroupFormValues>()
  const [detail, setDetail] = useState<MessageSceneDetail | null>(null), [groups, setGroups] = useState<MessageRecipientGroup[]>([])
  const [error, setError] = useState(''), [loading, setLoading] = useState(true), [saving, setSaving] = useState(false), [retry, setRetry] = useState(0)
  const [dirty, setDirty] = useState(false)
  const lock = useRef(false), mounted = useRef(true)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  useEffect(() => {
    const controller = new AbortController()
    void messageSceneApi.get(code, controller.signal).then(value => {
      if (controller.signal.aborted) return
      setDetail(value); setGroups(value.groups); form.setFieldsValue({ groups: Object.fromEntries(value.groups.map(g => [g.id, g])) })
    }).catch(cause => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : '场景配置读取失败') })
      .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [code, form, retry])
  const selected = groups.find(g => g.id === activeGroup) ?? groups[0]
  const close = () => dirty ? Modal.confirm({ title: '放弃未保存的修改？', content: '返回列表后，本次尚未保存的配置将丢失。', onOk: onClose }) : onClose()
  function add(kind: MessageGroupKind) {
    const template = detail?.group_templates[kind]
    if (!template || groups.length >= 10) return
    const group = { ...structuredClone(template), id: `g-${crypto.randomUUID()}`, name: `${MESSAGE_GROUP_LABELS[kind]} ${groups.filter(g => g.kind === kind).length + 1}` }
    form.setFields({ [`groups.${group.id}`]: { value: group } }); setGroups(current => [...current, group]); setDirty(true); onNavigate(group.id)
  }
  function remove(group: MessageRecipientGroup) {
    Modal.confirm({ title: '删除接收组？', content: `删除“${group.name}”仅影响后续发送，历史消息和发送记录仍保留。`, onOk: () => {
      const next = groups.filter(g => g.id !== group.id); setGroups(next); setDirty(true)
      if (selected?.id === group.id && next[0]) onNavigate(next[0].id)
    } })
  }
  function move(offset: number) {
    const index = groups.findIndex(group => group.id === selected?.id), next = index + offset
    if (index < 0 || next < 0 || next >= groups.length) return
    setGroups(current => { const values = [...current]; [values[index], values[next]] = [values[next], values[index]]; return values }); setDirty(true)
  }
  async function save() {
    if (!detail || !canSave || lock.current) return
    let values: GroupFormValues
    try { values = await form.validate() } catch (cause) {
      const fields = typeof cause === 'object' && cause !== null ? Object.keys(cause) : []
      const parts = fields.find(field => field.startsWith('groups.'))?.split('.')
      if (parts?.[1]) onNavigate(parts[1], parts[2] === 'configurations' ? parts[3] : undefined)
      return
    }
    lock.current = true; setSaving(true); setError('')
    try {
      await messageSceneApi.save(code, { revisions: detail.revisions, groups: groups.map(group => {
        const edit = values.groups?.[group.id]
        return { ...group, ...edit, id: group.id, kind: group.kind, recipients: { ...group.recipients, ...edit?.recipients, ids: edit?.recipients?.mode === 'founder' ? [] : edit?.recipients?.ids ?? group.recipients.ids },
          configurations: Object.fromEntries(Object.entries(group.configurations).map(([channel, config]) => [channel, { ...config, ...edit?.configurations?.[channel as keyof typeof group.configurations] }])) }
      }) })
      if (mounted.current) { Message.success('消息场景配置已保存'); onSaved() }
    } catch (cause) {
      if (!mounted.current) return
      const fieldErrors = cause instanceof ApiRequestError && cause.data && typeof cause.data === 'object' && 'errors' in cause.data ? cause.data.errors : null
      if (fieldErrors && typeof fieldErrors === 'object') {
        for (const [field, messages] of Object.entries(fieldErrors)) {
          const parts = field.split('.'), group = groups[Number(parts[1])]
          if (group && Array.isArray(messages) && typeof messages[0] === 'string') {
            parts[1] = group.id; form.setFields({ [parts.join('.')]: { error: { message: messages[0] } } }); onNavigate(group.id, parts[2] === 'configurations' ? parts[3] : undefined)
          }
        }
      }
      setError(cause instanceof Error ? cause.message : '保存失败')
    } finally { lock.current = false; if (mounted.current) setSaving(false) }
  }
  return <PageFormEditor title={`编辑消息 · ${detail?.name ?? '消息配置'}`} onCancel={close} onSave={canSave ? () => void save() : undefined} saving={saving} disabled={loading || !detail}>
    {error && <Alert type="error" content={error} action={!detail ? <Button onClick={() => { setError(''); setLoading(true); setRetry(v => v + 1) }}>重新加载</Button> : undefined} style={{ marginBottom: 16 }} />}
    <Spin loading={loading} style={{ width: '100%' }}>{detail && <>
      <div className="message-scene-summary"><div><Typography.Text bold>{detail.name}</Typography.Text><Typography.Text type="secondary">{MESSAGE_PURPOSE_LABELS[detail.purpose]}</Typography.Text></div><Typography.Paragraph type="secondary">{detail.description}</Typography.Paragraph></div>
      {detail.purpose === 'verification' && <Alert type="info" content="验证码模板可保存；验证码业务需经专用验证服务接入，不进入普通通知队列。" style={{ marginBottom: 16 }} />}
      <div className="message-scenes-editor">
        <aside className="message-group-navigation"><div className="message-group-navigation-heading"><Typography.Text bold>接收组</Typography.Text>{canSave && <Dropdown trigger="click" droplist={<Menu onClickMenuItem={key => add(key as MessageGroupKind)}>{Object.keys(detail.group_templates).map(kind => <Menu.Item key={kind}>{MESSAGE_GROUP_LABELS[kind as MessageGroupKind]}</Menu.Item>)}</Menu>}><Button type="text" icon={<IconPlus />} disabled={groups.length >= 10 || saving}>添加</Button></Dropdown>}</div>
          {groups.map(g => <button type="button" className={`message-group-navigation-item${selected?.id === g.id ? ' is-active' : ''}`} key={g.id} onClick={() => onNavigate(g.id)}><span>{g.name}</span><small>{g.name === MESSAGE_GROUP_LABELS[g.kind] ? '接收策略' : MESSAGE_GROUP_LABELS[g.kind]}</small></button>)}
          <Typography.Paragraph type="secondary" className="message-group-order-hint">重叠范围内，同一接收人、同一渠道只发送一次，使用靠前接收组的模板。</Typography.Paragraph>
        </aside>
        <main className="message-group-content">
          {groups.length === 0 && <Empty description="暂无接收组，添加后配置接收对象和渠道。" />}
          {selected && <div className="message-group-content-heading"><Typography.Title heading={6} style={{ margin: 0 }}>{selected.name}</Typography.Title>{canSave && <Space><Button type="text" icon={<IconArrowUp />} disabled={saving || groups[0]?.id === selected.id} onClick={() => move(-1)}>上移</Button><Button type="text" icon={<IconArrowDown />} disabled={saving || groups.at(-1)?.id === selected.id} onClick={() => move(1)}>下移</Button><Button type="text" status="danger" icon={<IconDelete />} disabled={saving} onClick={() => remove(selected)}>删除</Button></Space>}</div>}
          <Form form={form} layout="vertical" disabled={!canSave || saving} onValuesChange={() => setDirty(true)} onSubmit={() => void save()}>
            {groups.map(g => <div key={g.id} style={{ display: selected?.id === g.id ? undefined : 'none' }}><MessageGroupPanel group={g} detail={detail} form={form} activeChannel={selected?.id === g.id ? activeChannel : undefined} onChannelChange={channel => onNavigate(g.id, channel)} onNameChange={name => setGroups(current => current.map(item => item.id === g.id ? { ...item, name } : item))} /></div>)}
          </Form>
        </main>
      </div>
    </>}</Spin>
  </PageFormEditor>
}
