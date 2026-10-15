import { Alert, Form, Input, Link, Select, Space, Switch, Typography } from '@arco-design/web-react'
import { useHistory } from 'react-router-dom'
import { useAdminAuth } from '../../auth/AuthContext'
import { channelPermission } from '../../api/channelApi'
import { MESSAGE_CHANNEL_LABELS, type MessageSceneDetail } from '../../api/messageSceneApi'
import type { MessageAudience, MessageChannel, MessageChannelValues } from '../../api/messageConfigurationApi'

interface Props { prefix: string; channel: MessageChannel; values: MessageChannelValues; detail: MessageSceneDetail; audience: MessageAudience; disabled?: boolean }

/** 渠道只引用已配置连接，保留厂商模板与变量需求，不暴露账户密钥。 */
export default function MessageChannelFields({ prefix, channel, values, detail, audience, disabled = false }: Props) {
  const history = useHistory(), { permissions } = useAdminAuth()
  const sender = channel === 'email' || channel === 'sms'
  const permission = sender ? channelPermission(channel === 'email' ? 'mail' : 'sms', 'view') : 'admin.page.settings.view'
  const canConfigure = permissions.includes(permission)
  const options = detail.channel_options[channel] ?? []
  const missing = sender ? !options.some(option => option.available) : Object.hasOwn(detail.robot_status, channel) && !detail.robot_status[channel]
  const path = sender ? `/channels/${channel === 'email' ? 'mail' : 'sms'}` : '/settings'
  return <>
    {missing && <Alert type="warning" content={<span>{sender ? `暂无可用的${MESSAGE_CHANNEL_LABELS[channel]}通道，` : '此机器人尚未配置，'}{canConfigure ? '请先配置并启用。' : '请联系管理员。'}{' '}
      {canConfigure && <Link href={history.createHref({ pathname: path, search: sender ? '' : '?tab=notifications' })} target="_blank" rel="noopener noreferrer">去配置</Link>}
    </span>} style={{ marginBottom: 20 }} />}
    {channel === 'wechat' && <Alert type="info" content="当前仅保存公众号模板，尚未接入公众号发送。" style={{ marginBottom: 20 }} />}
    <Form.Item field={`${prefix}.enabled`} label="启用此渠道" triggerPropName="checked"><Switch disabled={disabled} type="round" checkedText="启用" uncheckedText="禁用" /></Form.Item>
    {sender && <Form.Item field={`${prefix}.channel_id`} label="发送通道" formatter={value => value === 0 ? undefined : value} normalize={value => value ?? 0} tooltip="账户和密钥在通道管理中维护。">
      <Select disabled={disabled} placeholder="请选择发送通道" allowClear options={options.map(option => ({ value: option.id, label: option.name, disabled: !option.available }))} />
    </Form.Item>}
    {Object.hasOwn(values, 'title') && <Form.Item field={`${prefix}.title`} label={channel === 'email' ? '邮件主题' : '消息标题'}><Input disabled={disabled} placeholder="请输入标题，支持使用消息变量" maxLength={128} /></Form.Item>}
    {Object.hasOwn(values, 'body') && <Form.Item field={`${prefix}.body`} label="消息内容" extra={channel === 'sms' ? '模板短信需填写厂商模板 ID 和变量映射；短信宝使用此内容。' : undefined}>
      <Input.TextArea disabled={disabled} placeholder="请输入消息内容，支持 {{变量名}}" maxLength={4000} showWordLimit autoSize={{ minRows: 4, maxRows: 8 }} />
    </Form.Item>}
    {['sms', 'wechat'].includes(channel) && <>
      <Form.Item field={`${prefix}.template_id`} label="厂商模板 ID" tooltip={channel === 'sms' ? '填写审核通过的模板 ID，短信宝可留空。' : '填写公众号模板 ID。'}><Input disabled={disabled} placeholder="请输入厂商模板 ID" maxLength={128} /></Form.Item>
      <Form.Item field={`${prefix}.variables`} label="变量映射" tooltip="JSON 对象，键为厂商字段，值使用下方消息变量。"><Input.TextArea disabled={disabled} placeholder={'例如 {"字段名":"{{变量名}}"}'} rows={3} maxLength={4096} /></Form.Item>
    </>}
    <div className="community-message-variables"><Typography.Text type="secondary">可用变量</Typography.Text><Space wrap size="small" style={{ marginTop: 8, display: 'flex' }}>
      {detail.metadata[audience]?.variables.map(variable => <Typography.Text key={variable.key} type="secondary" style={{ fontSize: 12 }}>{variable.label}：<Typography.Text copyable style={{ fontSize: 12 }}>{`{{${variable.key}}}`}</Typography.Text></Typography.Text>)}
    </Space></div>
  </>
}
