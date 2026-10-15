import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Card, Form, Input, Message, Spin } from '@arco-design/web-react'
import { useHistory } from 'react-router-dom'
import { adminProfileApi, type AdminPasswordInput, type AdminProfile, type AdminProfileInput } from '../api/adminProfileApi'
import { useAdminAuth } from '../auth/AuthContext'
import './PersonalSettingsPage.css'
import AdminMfaSettings from '../components/AdminMfaSettings'

/** 个人资料与改密独立提交；密码仅保存在当前表单中。 */
export default function PersonalSettingsPage() {
  const { permissions, clearSession } = useAdminAuth()
  const history = useHistory()
  const [profileForm] = Form.useForm<AdminProfileInput>()
  const [passwordForm] = Form.useForm<AdminPasswordInput>()
  const [profile, setProfile] = useState<AdminProfile | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [retry, setRetry] = useState(0)
  const [saving, setSaving] = useState(false)
  const [changing, setChanging] = useState(false)
  const saveLock = useRef(false)
  const passwordLock = useRef(false)
  const canUpdate = permissions.includes('admin.profile.update')
  const canChangePassword = permissions.includes('admin.profile.password')

  useEffect(() => {
    const controller = new AbortController()
    void adminProfileApi.get(controller.signal).then((value) => {
      if (controller.signal.aborted) return
      setProfile(value)
      profileForm.setFieldsValue(value)
    }).catch((error: unknown) => {
      if (!controller.signal.aborted) setLoadError(error instanceof Error ? error.message : '个人资料加载失败')
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false)
    })
    return () => controller.abort()
  }, [profileForm, retry])

  async function save(values: AdminProfileInput) {
    if (!canUpdate || saveLock.current) return
    saveLock.current = true
    setSaving(true)
    try {
      const saved = await adminProfileApi.update({
        employee_no: values.employee_no?.trim() || null,
        mobile: values.mobile?.trim() || null,
        email: values.email?.trim() || null,
      })
      setProfile(saved)
      profileForm.setFieldsValue(saved)
      Message.success('个人资料已保存')
    } catch (error) {
      Message.error(error instanceof Error ? error.message : '个人资料保存失败')
    } finally {
      saveLock.current = false
      setSaving(false)
    }
  }

  async function changePassword(values: AdminPasswordInput) {
    if (!canChangePassword || passwordLock.current) return
    passwordLock.current = true
    setChanging(true)
    try {
      await adminProfileApi.password(values)
      passwordForm.resetFields()
      clearSession()
      history.replace('/login')
      Message.success('密码已修改，请重新登录')
    } catch (error) {
      Message.error(error instanceof Error ? error.message : '密码修改失败')
    } finally {
      passwordLock.current = false
      setChanging(false)
    }
  }

  return <div className="personal-settings-page">
    <Card title="个人设置" bordered={false}>
      {loadError && <Alert type="error" content={loadError} action={<Button onClick={() => {
        setLoading(true)
        setLoadError('')
        setRetry((value) => value + 1)
      }}>重试</Button>} />}
      <Spin loading={loading} style={{ display: 'block' }}>
        {profile && !loadError && <Form form={profileForm} layout="vertical" className="personal-settings-form" onSubmit={save} disabled={!canUpdate || loading || saving}>
          <Form.Item label="管理员账号"><Input value={profile.username} disabled /></Form.Item>
          <Form.Item label="昵称"><Input value={profile.nickname} disabled /></Form.Item>
          <Form.Item label="邮箱" field="email" rules={[{ type: 'email', message: '请输入有效的邮箱地址' }, { maxLength: 254, message: '邮箱地址不能超过 254 个字符' }]}>
            <Input placeholder="请输入邮箱（选填）" maxLength={254} allowClear />
          </Form.Item>
          <Form.Item label="手机号" field="mobile" rules={[{ match: /^\+?[0-9]{6,20}$/, message: '请输入有效的手机号' }]}>
            <Input placeholder="请输入手机号（选填）" maxLength={21} allowClear />
          </Form.Item>
          <Form.Item label="工号" field="employee_no" rules={[{ maxLength: 32, message: '工号不能超过 32 个字符' }]}>
            <Input placeholder="请输入工号（选填）" maxLength={32} allowClear />
          </Form.Item>
          {canUpdate && <Button type="primary" htmlType="submit" loading={saving}>保存资料</Button>}
        </Form>}
      </Spin>
    </Card>
    <AdminMfaSettings />
    {canChangePassword && <Card title="修改密码" bordered={false}>
      <Form form={passwordForm} layout="vertical" className="personal-settings-form" onSubmit={changePassword} disabled={changing}>
        <Form.Item label="原密码" field="current_password" rules={[{ required: true, message: '请输入原密码' }]}>
          <Input.Password placeholder="请输入原密码" autoComplete="current-password" maxLength={128} />
        </Form.Item>
        <Form.Item label="新密码" field="new_password" rules={[{ required: true, message: '请输入新密码' }, { minLength: 8, message: '新密码至少需要 8 个字符' }, {
          validator: (value, callback) => {
            if (value && new TextEncoder().encode(value).length > 72) callback('新密码不能超过 72 字节，请减少字符数')
            else callback()
          },
        }]}>
          <Input.Password placeholder="请输入新密码，至少 8 个字符" autoComplete="new-password" maxLength={72} />
        </Form.Item>
        <Form.Item label="确认新密码" field="new_password_confirmation" dependencies={['new_password']} rules={[{ required: true, message: '请再次输入新密码' }, {
          validator: (value, callback) => {
            if (value && value !== passwordForm.getFieldValue('new_password')) callback('两次输入的新密码不一致')
            else callback()
          },
        }]}>
          <Input.Password placeholder="请再次输入新密码" autoComplete="new-password" maxLength={72} />
        </Form.Item>
        <Form.Item extra="修改成功后需要重新登录。">
          <Button type="primary" htmlType="submit" loading={changing}>修改密码</Button>
        </Form.Item>
      </Form>
    </Card>}
  </div>
}
