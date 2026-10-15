import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Form, Input, InputNumber, Message, Spin, Typography } from '@arco-design/web-react'
import { IconRefresh } from '@arco-design/web-react/icon'
import { ApiRequestError } from '../api/httpClient'
import { getAdminEntrySettings, getSecuritySettings, saveAdminEntrySettings, saveSecuritySettings, settingsFieldErrors, type AdminEntrySettings, type SecuritySettings } from '../api/settingsApi'
import SectionTitle from '../components/SectionTitle'

type SecurityFormValues = SecuritySettings & AdminEntrySettings

/** 登录策略与后台入口统一提交，所有字段先校验，入口保存成功后才跳转。 */
export default function SecuritySettingsForm({ canUpdate }: { canUpdate: boolean }) {
  const [form] = Form.useForm<SecurityFormValues>()
  const [current, setCurrent] = useState(''), [loading, setLoading] = useState(true), [saving, setSaving] = useState(false)
  const [error, setError] = useState(''), [conflict, setConflict] = useState(false), [attempt, setAttempt] = useState(0)
  const lock = useRef(false)
  const initialized = useRef(false)
  const backendOrigin = import.meta.env.DEV ? new URL(import.meta.env.VITE_BACKEND_TARGET ?? 'http://127.0.0.1:8989').origin : window.location.origin
  useEffect(() => {
    const controller = new AbortController()
    void Promise.all([getSecuritySettings(controller.signal), getAdminEntrySettings(controller.signal)]).then(([security, entry]) => {
      if (!controller.signal.aborted) {
        setCurrent(entry.admin_path)
        // 并发冲突后重新读取当前入口，保留管理员尚未保存的输入。
        if (!initialized.current) form.setFieldsValue({ ...security, ...entry })
        initialized.current = true
        setError(''); setConflict(false)
      }
    }).catch(reason => { if (!controller.signal.aborted) setError(reason instanceof Error ? reason.message : '安全设置加载失败') })
      .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [form, attempt])

  async function save(values: SecurityFormValues) {
    if (!canUpdate || loading || conflict || !current || lock.current) return
    lock.current = true; setSaving(true)
    let securitySaved = false
    try {
      const savedSecurity = await saveSecuritySettings(values)
      securitySaved = true
      form.setFieldsValue(savedSecurity)
      // 路径修改可能导致页面离开，必须在登录策略保存之后执行。
      if (values.admin_path !== current) {
        const savedEntry = await saveAdminEntrySettings(values.admin_path, current)
        setCurrent(savedEntry.admin_path); form.setFieldsValue(savedEntry)
        setError(''); Message.success('保存成功')
        if (savedEntry.admin_path !== current) window.location.assign(`${backendOrigin}/${savedEntry.admin_path}/settings?tab=security`)
      } else { setError(''); Message.success('保存成功') }
    } catch (reason) {
      const fields = settingsFieldErrors(reason)
      for (const field of ['session_expire', 'login_attempts', 'login_window', 'admin_path']) {
        if (fields[field]) form.setFields({ [field]: { error: { message: fields[field] } } })
      }
      const detail = reason instanceof Error ? reason.message : '设置保存失败，请重试'
      setError(securitySaved ? `登录安全设置已保存，但后台入口保存失败：${detail}` : detail)
      setConflict(reason instanceof ApiRequestError && reason.status === 409)
    } finally { lock.current = false; setSaving(false) }
  }

  return <div className="settings-form-panel">
    {error && <Alert type="error" content={error} style={{ margin: '16px 0' }} action={<Button size="small" icon={<IconRefresh />} disabled={saving} onClick={() => {
      setLoading(true); setError(''); setAttempt(value => value + 1)
    }}>重新加载</Button>} />}
    <Spin loading={loading} style={{ width: '100%' }}>
      <Form<SecurityFormValues> id="security-settings" form={form} layout="vertical" disabled={!canUpdate || loading || saving || !current || conflict} onSubmit={values => void save(values)} scrollToFirstError>
        <Form.Item><SectionTitle>登录安全</SectionTitle></Form.Item>
        <Form.Item field="session_expire" label="后台登录有效期" extra="范围 5–1440 分钟。保存后对新登录生效，已有登录保持原有效期。" rules={[
          { required: true, message: '请输入后台登录有效期' },
          { type: 'number', min: 5, max: 1440, message: '后台登录有效期须为 5–1440 分钟' },
        ]}><InputNumber mode="button" placeholder="请输入后台登录有效期" suffix="分钟" min={5} max={1440} precision={0} style={{ width: '100%' }} /></Form.Item>
        <Form.Item field="login_attempts" label="每 IP 登录尝试次数" extra="同一 IP 在下方时间窗口内允许的登录尝试总次数，包含成功和失败。" rules={[
          { required: true, message: '请输入登录尝试次数' },
          { type: 'number', min: 1, max: 100, message: '登录尝试次数须为 1–100 次' },
        ]}><InputNumber mode="button" placeholder="请输入每 IP 登录尝试次数" suffix="次" min={1} max={100} precision={0} style={{ width: '100%' }} /></Form.Item>
        <Form.Item field="login_window" label="登录限制窗口" extra="范围 10–3600 秒。窗口内达到次数上限后需等待限制解除。" rules={[
          { required: true, message: '请输入登录限制窗口' },
          { type: 'number', min: 10, max: 3600, message: '登录限制窗口须为 10–3600 秒' },
        ]}><InputNumber mode="button" placeholder="请输入登录限制窗口" suffix="秒" min={10} max={3600} precision={0} style={{ width: '100%' }} /></Form.Item>
        <Form.Item><SectionTitle>后台访问入口</SectionTitle></Form.Item>
        <Form.Item field="admin_path" label="后台访问路径" extra="以小写字母开头，使用小写字母、数字或连字符，3–32位。保存后原入口失效，当前页面将进入新后台。" rules={[
          { required: true, message: '请输入后台访问路径' },
          { match: /^[a-z][a-z0-9-]{2,31}$/, message: '请输入3–32位的小写字母、数字或连字符，并以字母开头' },
        ]}><Input prefix="/" maxLength={32} placeholder="例如：manage-center" /></Form.Item>
        {current && <Form.Item label="当前后台地址"><Typography.Text copyable>{`${backendOrigin}/${current}/`}</Typography.Text></Form.Item>}
        {canUpdate && <Form.Item><Button type="primary" htmlType="submit" loading={saving} disabled={loading || conflict || !current}>保存设置</Button></Form.Item>}
      </Form>
    </Spin>
  </div>
}
