import { useState } from 'react'
import type { FormInstance } from '@arco-design/web-react'
import { Alert, Form, Input, Link, Select, Space, Switch, Tabs, Typography } from '@arco-design/web-react'
import { useHistory } from 'react-router-dom'
import { useAdminAuth } from '../../auth/AuthContext'
import { channelPermission } from '../../api/channelApi'
import { MESSAGE_CHANNEL_LABELS, type MessageRecipientGroup, type MessageSceneDetail } from '../../api/messageSceneApi'
import type { MessageChannel, MessageRecipientPolicy } from '../../api/messageConfigurationApi'

export interface GroupFormValues { groups: Record<string, MessageRecipientGroup> }
interface Props { group: MessageRecipientGroup; detail: MessageSceneDetail; form: FormInstance<GroupFormValues>; activeChannel?: string; onChannelChange: (value: string) => void; onNameChange: (value: string) => void }

/** 渠道凭据仅引用；模板属于接收组，标签页切换保留尚未保存的内容。 */
export default function MessageGroupPanel({ group, detail, form, activeChannel, onChannelChange, onNameChange }: Props) {
  const history = useHistory(), { permissions } = useAdminAuth()
  const prefix = `groups.${group.id}`
  const [mode, setMode] = useState<MessageRecipientPolicy['mode']>(group.recipients.mode)
  const audience = group.kind === 'customer' ? 'customer' : 'admin'
  const metadata = detail.metadata[audience]
  const recipientOptions = (detail.recipient_options[mode] ?? []).map(o => ({ value: o.id, label: o.name, disabled: false }))
  if (group.recipients.mode === mode) for (const id of group.recipients.ids) {
    if (!recipientOptions.some(option => option.value === id)) recipientOptions.push({ value: id, label: `已停用或删除的对象 #${id}`, disabled: true })
  }
  const channels = Object.keys(group.configurations) as MessageChannel[]
  const selected = channels.find(c => c === activeChannel) ?? channels[0]
  const link = (path: string, permission: string, text: string) => permissions.includes(permission)
    ? <Link href={history.createHref({ pathname: path.split('?')[0], search: path.includes('?') ? `?${path.split('?')[1]}` : '' })} target="_blank" rel="noopener noreferrer">{text}</Link> : null
  return <div className="message-group-panel">
    <div className="message-group-basics">
      <Form.Item field={`${prefix}.name`} label="接收组名称" rules={[{ required: true, message: '请输入接收组名称' }]}><Input placeholder="例如售后团队、关联客户" maxLength={64} onChange={onNameChange} /></Form.Item>
      <Form.Item field={`${prefix}.enabled`} label="启用接收组" triggerPropName="checked"><Switch checkedText="启用" uncheckedText="禁用" /></Form.Item>
    </div>
    <Typography.Paragraph type="secondary">{metadata?.description}</Typography.Paragraph>
    {group.kind === 'customer' && <Typography.Paragraph type="secondary">接收人为事件关联客户，无需重复指定账号。</Typography.Paragraph>}
    {group.kind === 'admin' && <div className="message-group-basics">
      <Form.Item field={`${prefix}.recipients.mode`} label="接收范围">
        <Select placeholder="请选择接收范围" options={[{ value: 'founder', label: '创始管理员' }, { value: 'departments', label: '指定部门' }, { value: 'roles', label: '指定角色' }, { value: 'members', label: '指定管理员' }]}
          onChange={(value: MessageRecipientPolicy['mode']) => { setMode(value); form.setFields({ [`${prefix}.recipients`]: { value: { mode: value, ids: [] } } }) }} />
      </Form.Item>
      {mode !== 'founder' && <Form.Item field={`${prefix}.recipients.ids`} label={mode === 'departments' ? '接收部门' : mode === 'roles' ? '接收角色' : '接收管理员'} rules={[{ required: true, message: '请选择接收对象' }]}>
        <Select placeholder="请选择启用的接收对象" mode="multiple" allowClear showSearch options={recipientOptions} />
      </Form.Item>}
    </div>}
    {group.kind === 'robot' && <Typography.Paragraph type="secondary">发送到系统设置中的群机器人，每个渠道只发送一次。 {link('/settings?tab=notifications', 'admin.page.settings.view', '管理机器人')}</Typography.Paragraph>}
    <Tabs activeTab={selected} onChange={onChannelChange} lazyload={false} destroyOnHide={false}>
      {channels.map(channel => <Tabs.TabPane key={channel} title={MESSAGE_CHANNEL_LABELS[channel]}>
        {channel === 'wechat' && <Alert type="info" content="当前仅保存公众号模板，尚未接入公众号发送。" style={{ marginBottom: 16 }} />}
        {Object.hasOwn(detail.robot_status, channel) && !detail.robot_status[channel] && <Alert type="warning" content={<span>{permissions.includes('admin.page.settings.view') ? '此机器人尚未配置，请先在系统设置中完成配置。' : '此机器人尚未配置，请联系管理员。'} {link('/settings?tab=notifications', 'admin.page.settings.view', '去配置')}</span>} style={{ marginBottom: 16 }} />}
        {['email', 'sms'].includes(channel) && !(detail.channel_options[channel] ?? []).some(o => o.available) && <Alert type="warning" content={<span>暂无可用的{MESSAGE_CHANNEL_LABELS[channel]}通道，{permissions.includes(channelPermission(channel === 'email' ? 'mail' : 'sms', 'view')) ? '请先配置并启用。' : '请联系管理员。'} {link(`/channels/${channel === 'email' ? 'mail' : 'sms'}`, channelPermission(channel === 'email' ? 'mail' : 'sms', 'view'), '去配置')}</span>} style={{ marginBottom: 16 }} />}
        <Form.Item field={`${prefix}.configurations.${channel}.enabled`} label="启用此渠道" triggerPropName="checked"><Switch checkedText="启用" uncheckedText="禁用" /></Form.Item>
        {['email', 'sms'].includes(channel) && <Form.Item field={`${prefix}.configurations.${channel}.channel_id`} label="发送通道" formatter={value => value === 0 ? undefined : value} normalize={value => value ?? 0} extra="仅引用已配置并启用的通道；账号、密钥在通道管理维护。">
          <Select placeholder="请选择发送通道" allowClear options={(detail.channel_options[channel] ?? []).map(o => ({ value: o.id, label: o.name, disabled: !o.available }))} />
        </Form.Item>}
        {Object.hasOwn(group.configurations[channel] ?? {}, 'title') && <Form.Item field={`${prefix}.configurations.${channel}.title`} label={channel === 'email' ? '邮件主题' : '消息标题'}><Input placeholder="请输入标题，可使用下方变量" maxLength={128} /></Form.Item>}
        {Object.hasOwn(group.configurations[channel] ?? {}, 'body') && <Form.Item field={`${prefix}.configurations.${channel}.body`} label="消息内容" extra={channel === 'sms' ? '模板短信需填写厂商模板 ID 和变量映射；短信宝使用此内容。' : '按纯文本保存，支持当前场景声明的变量。'}><Input.TextArea placeholder="请输入消息内容，可使用 {{变量名}}" maxLength={4000} showWordLimit autoSize={{ minRows: 4, maxRows: 8 }} /></Form.Item>}
        {['sms', 'wechat'].includes(channel) && <>
          <Form.Item field={`${prefix}.configurations.${channel}.template_id`} label="厂商模板 ID" extra={channel === 'sms' ? '填写审核通过的模板 ID，短信宝可留空。' : '填写公众号模板 ID。'}><Input placeholder="请输入厂商模板 ID" maxLength={128} /></Form.Item>
          <Form.Item field={`${prefix}.configurations.${channel}.variables`} label="变量映射" extra="JSON 对象，键为厂商字段，值仅使用当前场景的变量。"><Input.TextArea placeholder={'例如 {"字段名":"{{变量名}}"}'} rows={3} maxLength={4096} /></Form.Item>
        </>}
      </Tabs.TabPane>)}
    </Tabs>
    <div className="message-template-variables"><Typography.Text bold>可用变量</Typography.Text><Space wrap size="small">{metadata?.variables.map(v => <Typography.Text key={v.key} type="secondary">{v.label}：<Typography.Text copyable>{`{{${v.key}}}`}</Typography.Text></Typography.Text>)}</Space></div>
  </div>
}
