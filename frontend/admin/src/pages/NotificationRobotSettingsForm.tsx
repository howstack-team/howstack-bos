import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Form, Input, Link, Message, Space, Spin, Tabs } from '@arco-design/web-react'
import { notificationRobotSettingsApi, emptyRobots, ROBOT_LABELS, robotKeys, type NotificationRobotSettings, type NotificationRobotSettingsInput, type RobotChannel } from '../api/notificationRobotSettingsApi'
import { settingsFieldErrors } from '../api/settingsApi'

/** 机器人账号信息全站共用；只管理设置，不发送测试消息。 */
export default function NotificationRobotSettingsForm({ canUpdate }: { canUpdate: boolean }) {
  const [form] = Form.useForm<NotificationRobotSettingsInput>()
  const [detail, setDetail] = useState<NotificationRobotSettings | null>(null)
  const [active, setActive] = useState<RobotChannel>('dingtalk')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)
  const [saving, setSaving] = useState(false)
  const [clearSecrets, setClearSecrets] = useState<NotificationRobotSettingsInput['clear_secrets']>({})
  const lock = useRef(false), mounted = useRef(true)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  useEffect(() => {
    const controller = new AbortController()
    void notificationRobotSettingsApi.get(controller.signal).then(value => {
      if (controller.signal.aborted) return
      setDetail(value)
      form.setFieldsValue({ revision: value.revision, robots: emptyRobots() })
    }).catch((cause: unknown) => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : '机器人设置加载失败') })
      .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [form, retry])
  async function save(values: NotificationRobotSettingsInput) {
    if (!canUpdate || !detail || error || lock.current) return
    lock.current = true; setSaving(true)
    try {
      const saved = await notificationRobotSettingsApi.save({ revision: detail.revision, robots: values.robots, clear_secrets: clearSecrets })
      if (!mounted.current) return
      setDetail(saved); setClearSecrets({}); form.setFieldsValue({ revision: saved.revision, robots: emptyRobots() })
      Message.success('机器人设置已保存')
    } catch (cause) {
      if (!mounted.current) return
      for (const [field, message] of Object.entries(settingsFieldErrors(cause))) {
        form.setFields({ [field]: { error: { message } } })
        const channel = field.split('.')[1]
        if (Object.hasOwn(ROBOT_LABELS, channel)) setActive(channel as RobotChannel)
      }
      Message.error(cause instanceof Error ? cause.message : '保存失败，请重试')
    } finally { lock.current = false; if (mounted.current) setSaving(false) }
  }
  return <div className="settings-form-panel">
    {error && <Alert type="error" content={error} action={<Button onClick={() => { setError(''); setLoading(true); setRetry(value => value + 1) }}>重新加载</Button>} />}
    <Alert type="info" content="机器人统一配置一次，各消息单独设置启用状态、标题和内容。" style={{ marginBottom: 16 }} />
    <Spin loading={loading} style={{ width: '100%' }}>
      <Form form={form} layout="vertical" disabled={!canUpdate || loading || saving || Boolean(error)} onSubmit={values => void save(values)}>
        <Tabs activeTab={active} onChange={key => setActive(key as RobotChannel)} destroyOnHide={false} lazyload={false}>
          {(Object.keys(ROBOT_LABELS) as RobotChannel[]).map(channel => <Tabs.TabPane key={channel} title={ROBOT_LABELS[channel]}>
            {robotKeys(channel).map(key => {
              const configured = detail?.configured_secrets[channel][key]
              const cleared = clearSecrets[channel]?.includes(key)
              return <Form.Item key={key} field={`robots.${channel}.${key}`} label={key === 'webhook_url' ? '机器人 Webhook' : '加签密钥'}
                extra={<Space wrap size="mini"><span>{cleared ? '保存后清除原设置' : configured ? '已设置，留空保持原设置' : key === 'webhook_url' ? '填写当前平台的官方 HTTPS 机器人地址' : '机器人启用加签时填写（选填）'}</span>
                  {configured && <Link href="#" disabled={!canUpdate || saving} onClick={event => {
                    event.preventDefault()
                    setClearSecrets(old => ({ ...old, [channel]: cleared ? old[channel]?.filter(value => value !== key) : [...(old[channel] ?? []), key] }))
                    const robots = form.getFieldValue('robots') as NotificationRobotSettingsInput['robots']
                    form.setFieldValue('robots', { ...robots, [channel]: { ...robots[channel], [key]: '' } })
                  }}>{cleared ? '保留原设置' : '清除'}</Link>}
                </Space>}>
                <Input.Password maxLength={key === 'webhook_url' ? 512 : 256} placeholder={configured && !cleared ? '已设置，留空保持原设置' : `请输入${key === 'webhook_url' ? '机器人 Webhook' : '加签密钥（选填）'}`}
                  onChange={() => setClearSecrets(old => ({ ...old, [channel]: old[channel]?.filter(value => value !== key) }))} />
              </Form.Item>
            })}
          </Tabs.TabPane>)}
        </Tabs>
        {canUpdate && <Form.Item><Button type="primary" htmlType="submit" loading={saving} disabled={loading || Boolean(error)}>保存设置</Button></Form.Item>}
      </Form>
    </Spin>
  </div>
}
