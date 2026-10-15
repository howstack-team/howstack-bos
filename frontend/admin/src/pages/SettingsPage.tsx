import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Alert, Button, Empty, Form, Input, Message, Spin, Switch, Tabs } from '@arco-design/web-react'
import {
  getRegistrationSettings, saveRegistrationSettings, getBaseSettings, listSettingsCategories, saveBaseSettings,
  settingsFieldErrors, settingsPermissions,
} from '../api/settingsApi.ts'
import type { BaseSettings, RegistrationSettings, SettingsCategory } from '../api/settingsApi.ts'
import { useAdminAuth } from '../auth/AuthContext.ts'
import { useHistory, useLocation } from 'react-router-dom'
import NotificationRobotSettingsForm from './NotificationRobotSettingsForm'
import SecuritySettingsForm from './SecuritySettingsForm'
import './SettingsPage.css'

interface SettingsFormProps<T extends object> {
  id: string
  canUpdate: boolean
  load: (signal?: AbortSignal) => Promise<T>
  save: (values: T) => Promise<T>
  children: ReactNode
}

/** 各分类独立维护表单与请求状态，切换标签时不重新回填未保存的输入。 */
function SettingsForm<T extends object>({ id, canUpdate, load, save, children }: SettingsFormProps<T>) {
  const [form] = Form.useForm<T>()
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [retry, setRetry] = useState(0)
  const [saving, setSaving] = useState(false)
  const submitLock = useRef(false)

  useEffect(() => {
    const controller = new AbortController()
    void load(controller.signal).then((values) => {
      if (!controller.signal.aborted) form.setFieldsValue(values as Parameters<typeof form.setFieldsValue>[0])
    }).catch((error: unknown) => {
      if (!controller.signal.aborted) setLoadError(error instanceof Error ? error.message : '设置加载失败')
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false)
    })
    return () => controller.abort()
  }, [form, load, retry])

  async function submit(values: T) {
    if (!canUpdate || loading || loadError || submitLock.current) return
    submitLock.current = true
    setSaving(true)
    try {
      const saved = await save(values)
      form.setFieldsValue(saved as Parameters<typeof form.setFieldsValue>[0])
      Message.success('保存成功')
    } catch (error) {
      const fields = form.getFieldsValue()
      for (const [field, message] of Object.entries(settingsFieldErrors(error))) {
        if (Object.hasOwn(fields, field)) {
          form.setFields({ [field]: { error: { message } } } as Parameters<typeof form.setFields>[0])
        }
      }
      Message.error(error instanceof Error ? error.message : '设置保存失败，请重试')
    } finally {
      submitLock.current = false
      setSaving(false)
    }
  }

  return <div className="settings-form-panel">
    {loadError && <Alert type="error" content={loadError} action={<Button size="small" onClick={() => {
      setLoading(true)
      setLoadError('')
      setRetry((value) => value + 1)
    }}>重新加载</Button>} />}
    <Spin loading={loading} style={{ width: '100%' }}>
      <Form<T> id={id} form={form} layout="vertical" disabled={!canUpdate || loading || saving || Boolean(loadError)} onSubmit={(values) => void submit(values)} scrollToFirstError>
        {children}
        {canUpdate && <Form.Item><Button type="primary" htmlType="submit" loading={saving} disabled={loading || Boolean(loadError)}>保存设置</Button></Form.Item>}
      </Form>
    </Spin>
  </div>
}

function BaseSettingsForm({ canUpdate }: { canUpdate: boolean }) {
  return <SettingsForm<BaseSettings> id="base-settings" canUpdate={canUpdate} load={getBaseSettings} save={saveBaseSettings}>
    <Form.Item field="site_title" label="平台名称" rules={[
      { required: true, message: '请输入网站标题' },
      { validator: (value: string, callback) => callback(!value || (value.trim().length >= 2 && value.trim().length <= 120) ? undefined : '网站标题须为 2–120 个字符') },
    ]}><Input maxLength={120} showWordLimit placeholder="请输入网站标题" /></Form.Item>
    <Form.Item field="site_url" label="网站地址" rules={[
      { required: true, message: '请输入网站地址' },
      { maxLength: 255, message: '网站地址不能超过 255 个字符' },
      { validator: (value: string, callback) => {
        if (!value) return callback()
        try {
          const url = new URL(value.trim())
          callback(/^https?:\/\//i.test(value.trim()) && (url.protocol === 'http:' || url.protocol === 'https:') && url.hostname ? undefined : '请输入以 http:// 或 https:// 开头的有效地址')
        } catch { callback('请输入以 http:// 或 https:// 开头的有效地址') }
      } },
    ]}><Input maxLength={255} placeholder="https://example.com" /></Form.Item>
    <Form.Item field="copyright" label="页脚版权信息" rules={[{ maxLength: 200, message: '版权信息不能超过 200 个字符' }]}><Input.TextArea rows={2} maxLength={200} showWordLimit placeholder="请输入页脚版权信息（选填）" /></Form.Item>
    <Form.Item field="icp_record" label="ICP备案号" rules={[{ maxLength: 100, message: 'ICP备案号不能超过 100 个字符' }]}><Input maxLength={100} showWordLimit placeholder="例如：京ICP备12345678号" /></Form.Item>
    <Form.Item field="public_security_record" label="公安备案号" rules={[{ maxLength: 100, message: '公安备案号不能超过 100 个字符' }]}><Input maxLength={100} showWordLimit placeholder="例如：京公网安备11000000000000号" /></Form.Item>
    <Form.Item field="business_license" label="经营许可证" rules={[{ maxLength: 100, message: '经营许可证不能超过 100 个字符' }]}><Input maxLength={100} showWordLimit placeholder="请输入经营许可证（选填）" /></Form.Item>
  </SettingsForm>
}

function RegistrationSettingsForm({ canUpdate }: { canUpdate: boolean }) {
  return <SettingsForm<RegistrationSettings> id="registration-settings" canUpdate={canUpdate} load={getRegistrationSettings} save={saveRegistrationSettings}>
    <Form.Item field="mobile_enabled" label="手机号注册" extra="开启后，客户可使用手机号和密码注册。" triggerPropName="checked" formatter={value => value === 1} normalize={value => value ? 1 : 0}>
      <Switch type="round" checkedText="开启" uncheckedText="关闭" />
    </Form.Item>
    <Form.Item field="mobile_verification" label="手机号验证码验证" extra="仅在手机号注册开启时生效。开启后需在消息配置的注册验证码中启用短信并选择可用通道。" triggerPropName="checked" formatter={value => value === 1} normalize={value => value ? 1 : 0}>
      <Switch type="round" checkedText="需要" uncheckedText="不需要" />
    </Form.Item>
    <Form.Item field="email_enabled" label="邮箱注册" extra="开启后，客户可使用邮箱和密码注册。两种注册方式同时关闭时，停止开放自助注册。" triggerPropName="checked" formatter={value => value === 1} normalize={value => value ? 1 : 0}>
      <Switch type="round" checkedText="开启" uncheckedText="关闭" />
    </Form.Item>
    <Form.Item field="email_verification" label="邮箱验证码验证" extra="仅在邮箱注册开启时生效。开启后需在消息配置的注册验证码中启用邮件并选择可用通道。关闭验证码验证时，注册联系方式不会标记为已验证。" triggerPropName="checked" formatter={value => value === 1} normalize={value => value ? 1 : 0}>
      <Switch type="round" checkedText="需要" uncheckedText="不需要" />
    </Form.Item>
  </SettingsForm>
}

/** 数据库分类决定 Tab，基础设置和安全设置分别使用固定字段表单。 */
export default function SettingsPage() {
  const history = useHistory(), location = useLocation()
  const { permissions } = useAdminAuth()
  const canView = permissions.includes(settingsPermissions.view)
  const canUpdate = permissions.includes(settingsPermissions.update)
  const [categories, setCategories] = useState<SettingsCategory[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    if (!canView) return
    const controller = new AbortController()
    void listSettingsCategories(controller.signal).then((value) => {
      if (!controller.signal.aborted) setCategories(value)
    }).catch((cause: unknown) => {
      if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : '设置分类加载失败')
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false)
    })
    return () => controller.abort()
  }, [canView, retry])

  if (!canView) return <section className="page-card"><Alert type="warning" content="你没有查看系统设置的权限。" /></section>
  return <section className="page-card settings-page">
    {error ? <Alert type="error" content={error} action={<Button size="small" onClick={() => {
      setLoading(true)
      setError('')
      setRetry((value) => value + 1)
    }}>重新加载</Button>} />
      : loading ? <div className="settings-loading"><Spin /></div>
        : categories.length === 0 ? <Empty description="暂无可用的设置分类" />
          : <>
            {!canUpdate && <Alert type="info" content="你当前只有查看权限，无法修改系统设置。" style={{ marginBottom: 16 }} />}
            <Tabs destroyOnHide={false} headerPadding={false}
              activeTab={categories.find(category => category.code === new URLSearchParams(location.search).get('tab'))?.code ?? categories[0].code}
              onChange={tab => { const params = new URLSearchParams(location.search); params.set('tab', tab); history.replace({ ...location, search: params.toString() }) }}>
              {categories.map((category) => <Tabs.TabPane key={category.code} title={category.name}>
                {category.code === 'base' ? <BaseSettingsForm canUpdate={canUpdate} /> : category.code === 'security' ? <SecuritySettingsForm canUpdate={canUpdate} /> : category.code === 'registration' ? <RegistrationSettingsForm canUpdate={canUpdate} /> : <NotificationRobotSettingsForm canUpdate={canUpdate} />}
              </Tabs.TabPane>)}
            </Tabs>
          </>}
  </section>
}
