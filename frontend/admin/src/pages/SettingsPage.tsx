import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Alert, Button, Empty, Form, Input, InputNumber, Message, Spin, Tabs } from '@arco-design/web-react'
import {
  getBaseSettings, getSecuritySettings, listSettingsCategories, saveBaseSettings, saveSecuritySettings,
  settingsFieldErrors, settingsPermissions,
} from '../api/settingsApi.ts'
import type { BaseSettings, SecuritySettings, SettingsCategory } from '../api/settingsApi.ts'
import { useAdminAuth } from '../auth/AuthContext.ts'
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
    <Form.Item field="site_title" label="网站标题" rules={[
      { required: true, message: '请输入网站标题' },
      { validator: (value: string, callback) => callback(!value || (value.trim().length >= 2 && value.trim().length <= 120) ? undefined : '网站标题须为 2–120 个字符') },
    ]}><Input maxLength={120} showWordLimit placeholder="请输入网站标题" /></Form.Item>
    <Form.Item field="site_subtitle" label="网站副标题" rules={[{ maxLength: 120, message: '网站副标题不能超过 120 个字符' }]}><Input maxLength={120} showWordLimit placeholder="选填" /></Form.Item>
    <Form.Item field="site_keywords" label="网站关键词" rules={[{ maxLength: 255, message: '网站关键词不能超过 255 个字符' }]}><Input maxLength={255} showWordLimit placeholder="多个关键词请用逗号分隔" /></Form.Item>
    <Form.Item field="site_description" label="网站描述" rules={[{ maxLength: 500, message: '网站描述不能超过 500 个字符' }]}><Input.TextArea rows={4} maxLength={500} showWordLimit placeholder="选填" /></Form.Item>
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
    <Form.Item field="copyright" label="页脚版权信息" rules={[{ maxLength: 200, message: '版权信息不能超过 200 个字符' }]}><Input.TextArea rows={2} maxLength={200} showWordLimit placeholder="选填" /></Form.Item>
    <Form.Item field="icp_record" label="ICP备案号" rules={[{ maxLength: 100, message: 'ICP备案号不能超过 100 个字符' }]}><Input maxLength={100} showWordLimit placeholder="例如：京ICP备12345678号" /></Form.Item>
    <Form.Item field="public_security_record" label="公安备案号" rules={[{ maxLength: 100, message: '公安备案号不能超过 100 个字符' }]}><Input maxLength={100} showWordLimit placeholder="例如：京公网安备11000000000000号" /></Form.Item>
    <Form.Item field="business_license" label="经营许可证" rules={[{ maxLength: 100, message: '经营许可证不能超过 100 个字符' }]}><Input maxLength={100} showWordLimit placeholder="选填" /></Form.Item>
  </SettingsForm>
}

function SecuritySettingsForm({ canUpdate }: { canUpdate: boolean }) {
  return <SettingsForm<SecuritySettings> id="security-settings" canUpdate={canUpdate} load={getSecuritySettings} save={saveSecuritySettings}>
    <Form.Item field="session_expire" label="后台登录有效期（分钟）" extra="范围 5–1440 分钟。保存后对新登录生效，已有登录保持原有效期。" rules={[
      { required: true, message: '请输入后台登录有效期' },
      { type: 'number', min: 5, max: 1440, message: '后台登录有效期须为 5–1440 分钟' },
    ]}><InputNumber min={5} max={1440} precision={0} style={{ width: '100%' }} /></Form.Item>
    <Form.Item field="login_attempts" label="每 IP 登录尝试次数" extra="同一 IP 在下方时间窗口内允许的登录尝试总次数，包含成功和失败。" rules={[
      { required: true, message: '请输入登录尝试次数' },
      { type: 'number', min: 1, max: 100, message: '登录尝试次数须为 1–100 次' },
    ]}><InputNumber min={1} max={100} precision={0} style={{ width: '100%' }} /></Form.Item>
    <Form.Item field="login_window" label="登录限制窗口（秒）" extra="范围 10–3600 秒。窗口内达到次数上限后需等待限制解除。" rules={[
      { required: true, message: '请输入登录限制窗口' },
      { type: 'number', min: 10, max: 3600, message: '登录限制窗口须为 10–3600 秒' },
    ]}><InputNumber min={10} max={3600} precision={0} style={{ width: '100%' }} /></Form.Item>
  </SettingsForm>
}

/** 数据库分类决定 Tab，基础设置和安全设置分别使用固定字段表单。 */
export default function SettingsPage() {
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
            <Tabs destroyOnHide={false} headerPadding={false}>
              {categories.map((category) => <Tabs.TabPane key={category.code} title={category.name}>
                {category.code === 'base' ? <BaseSettingsForm canUpdate={canUpdate} /> : <SecuritySettingsForm canUpdate={canUpdate} />}
              </Tabs.TabPane>)}
            </Tabs>
          </>}
  </section>
}
