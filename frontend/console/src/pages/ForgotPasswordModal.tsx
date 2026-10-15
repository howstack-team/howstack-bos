import { useEffect, useRef, useState } from 'react'
import { Button, Form, Input, Message, Modal } from '@arco-design/web-react'
import { IconLock, IconSafe, IconUser } from '@arco-design/web-react/icon'
import { passwordResetErrorMessage, resetPassword, sendPasswordResetCode } from '../api/passwordResetApi'
import { reportPcAuthError } from '../utils/pcAuthFeedback'

interface ResetFields { account: string; code: string; password: string; confirm: string }
/** 通过绑定联系方式找回密码，关闭时清空凭据且不取消已提交的服务端动作。 */
export default function ForgotPasswordModal({ onClose }: { onClose: () => void }) {
  const [form] = Form.useForm<ResetFields>()
  const [sending, setSending] = useState(false)
  const [saving, setSaving] = useState(false)
  const [remaining, setRemaining] = useState(0)
  const mounted = useRef(true), busy = useRef(false), deadline = useRef(0)
  const challenge = useRef<{ id: string; account: string; expires: number } | null>(null)
  useEffect(() => {
    mounted.current = true
    const timer = window.setInterval(() => setRemaining(Math.max(0, Math.ceil((deadline.current - Date.now()) / 1000))), 500)
    return () => { mounted.current = false; window.clearInterval(timer) }
  }, [])
  async function sendCode() {
    if (busy.current || remaining > 0) return
    busy.current = true
    try { await form.validate(['account']) } catch { busy.current = false; return }
    if (!mounted.current) { busy.current = false; return }
    const account = form.getFieldValue('account').trim()
    setSending(true); challenge.current = null
    try {
      const response = await sendPasswordResetCode(account)
      if (!mounted.current) return
      challenge.current = { id: response.challenge_id, account, expires: Date.now() + response.expires_in * 1000 }
      deadline.current = Date.now() + response.retry_after * 1000
      setRemaining(response.retry_after)
      Message.success('验证码已发送，请查收短信或邮件')
    } catch (failure) { if (mounted.current) reportPcAuthError(failure, fields => form.setFields(fields), ['account'], passwordResetErrorMessage) }
    finally { busy.current = false; if (mounted.current) setSending(false) }
  }
  async function submit(values: ResetFields) {
    if (busy.current) return
    if (new TextEncoder().encode(values.password).length > 72) {
      form.setFields({ password: { error: { message: '密码最多 72 字节，请缩短密码' } } }); return
    }
    if (!challenge.current || challenge.current.account !== values.account.trim() || challenge.current.expires <= Date.now()) {
      form.setFields({ code: { error: { message: '请先获取当前账号的有效验证码' } } }); return
    }
    busy.current = true; setSaving(true)
    try {
      await resetPassword(values.account.trim(), values.code.trim(), challenge.current.id, values.password)
      Message.success('密码重置成功，请使用新密码登录')
      if (mounted.current) onClose()
    } catch (failure) {
      if (mounted.current) reportPcAuthError(failure, fields => form.setFields(fields), ['account', 'code', 'password'], passwordResetErrorMessage, { challenge_id: 'code' })
    }
    finally { busy.current = false; if (mounted.current) setSaving(false) }
  }
  return <Modal visible title="找回密码" footer={null} maskClosable onCancel={onClose}
    style={{ width: 480, maxWidth: 'calc(100vw - 32px)' }}>
    <p className="console-login-reset-hint">使用账号绑定的手机号或邮箱验证身份并设置新密码。</p>
    <Form<ResetFields> form={form} layout="vertical" size="large" requiredSymbol={false} disabled={sending || saving}
      onSubmit={submit} onValuesChange={changed => {
        if (Object.hasOwn(changed, 'account')) { challenge.current = null; form.resetFields(['code']) }
      }}>
      <Form.Item field="account" label="账号" rules={[
        { required: true, message: '请输入绑定的手机号或邮箱' },
        { match: /^(?:1[3-9][0-9]{9}|[^\s@]+@[^\s@]+\.[^\s@]+)$/, message: '请输入有效的手机号或邮箱' },
      ]}><Input prefix={<IconUser />} placeholder="请输入绑定的手机号或邮箱" autoComplete="username" maxLength={254} /></Form.Item>
      <div className="console-login-code">
        <Form.Item field="code" label="验证码" rules={[
          { required: true, message: '请输入验证码' }, { match: /^[0-9]{6}$/, message: '请输入 6 位数字验证码' },
        ]}><Input prefix={<IconSafe />} placeholder="请输入验证码" inputMode="numeric" autoComplete="one-time-code" maxLength={6} /></Form.Item>
        <Button className="console-login-send" size="large" htmlType="button" loading={sending} disabled={saving || remaining > 0}
          onClick={() => { void sendCode() }}>{remaining > 0 ? `${remaining} 秒后重发` : '获取验证码'}</Button>
      </div>
      <Form.Item field="password" label="新密码" rules={[
        { required: true, message: '请输入新密码' }, { minLength: 8, message: '密码至少 8 个字符' }, { maxLength: 72, message: '密码最多 72 个字符' },
      ]}><Input.Password visibilityToggle={false} prefix={<IconLock />} placeholder="请输入至少 8 个字符的新密码" autoComplete="new-password" maxLength={72} /></Form.Item>
      <Form.Item field="confirm" label="确认新密码" dependencies={['password']} rules={[
        { required: true, message: '请再次输入新密码' },
        { validator: (value, callback) => callback(!value || value === form.getFieldValue('password') ? undefined : '两次输入的密码不一致') },
      ]}><Input.Password visibilityToggle={false} prefix={<IconLock />} placeholder="请再次输入新密码" autoComplete="new-password" maxLength={72} /></Form.Item>
      <Button type="primary" htmlType="submit" size="large" long loading={saving} disabled={sending}>重置密码</Button>
    </Form>
  </Modal>
}
