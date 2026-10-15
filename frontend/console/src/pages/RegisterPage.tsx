import { useEffect, useRef, useState } from 'react'
import { Button, Checkbox, Form, Input, Link as ArcoLink, Message, Spin, Tabs } from '@arco-design/web-react'
import { IconEmail, IconLock, IconMobile, IconSafe } from '@arco-design/web-react/icon'
import { isOAuthRegistration } from '../utils/oauthNavigation'
import { Link, useHistory, useLocation } from 'react-router-dom'
import { getRegistrationOptions, registerMember, sendRegistrationCode, type RegistrationMethod, type RegistrationOptions } from '../api/registrationApi'
import { paths } from '../routes'
import AuthPageLayout from '../components/AuthPageLayout'
import { reportPcAuthError } from '../utils/pcAuthFeedback'
const submitButtonStyle = { height: 48, fontSize: 16 }
const fieldStyle = { marginBottom: 'var(--console-register-field-gap, 16px)' }

interface RegistrationForm { account: string; password: string; confirm: string; code?: string; agreement: boolean }

/** 验证码输入保持受控，使验证码字段能正常展示前后端校验错误。 */
function RegistrationCodeInput({ id, value, onChange, sending, saving, remaining, onSend }: {
  id?: string; value?: string; onChange?: (value: string) => void;
  sending: boolean; saving: boolean; remaining: number; onSend: () => void;
}) {
  return <div className="console-register-code">
    <Input id={id} value={value} onChange={onChange} disabled={sending || saving} prefix={<IconSafe style={{ fontSize: 16 }} />} placeholder="请输入验证码" inputMode="numeric" autoComplete="one-time-code" maxLength={6} />
    <Button size="large" style={{ padding: '0 10px' }} htmlType="button" loading={sending} disabled={saving || remaining > 0} onClick={onSend}>{remaining > 0 ? `${remaining} 秒后重发` : '获取验证码'}</Button>
  </div>
}
/** 方式与验证要求以服务端为准，不保存密码或验证码。 */
export default function RegisterPage() {
  const history = useHistory(), location = useLocation()
  const [form] = Form.useForm<RegistrationForm>()
  const [options, setOptions] = useState<RegistrationOptions | null>(null)
  const [method, setMethod] = useState<RegistrationMethod>('mobile')
  const [loading, setLoading] = useState(true), [retry, setRetry] = useState(0)
  const [loadFailed, setLoadFailed] = useState(false), [sending, setSending] = useState(false), [saving, setSaving] = useState(false)
  const [remaining, setRemaining] = useState(0)
  const deadline = useRef(0), challenge = useRef(''), busy = useRef(false), mounted = useRef(true)
  useEffect(() => {
    mounted.current = true
    document.body.setAttribute('arco-theme', 'light')
    const timer = window.setInterval(() => setRemaining(Math.max(0, Math.ceil((deadline.current - Date.now()) / 1000))), 1000)
    return () => { mounted.current = false; window.clearInterval(timer); document.body.removeAttribute('arco-theme') }
  }, [])
  useEffect(() => {
    const controller = new AbortController()
    void getRegistrationOptions(controller.signal).then(value => {
      if (controller.signal.aborted) return
      setOptions(value); setMethod(value.mobile_enabled ? 'mobile' : 'email')
      if (!value.mobile_enabled && !value.email_enabled) Message.info('暂未开放会员注册，请联系服务方。')
    }).catch(cause => { if (!controller.signal.aborted) { setLoadFailed(true); Message.error(cause instanceof Error ? cause.message : '注册设置加载失败') } })
      .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [retry])
  const verification = options?.[`${method}_verification`] === 1
  async function sendCode() {
    if (busy.current || remaining > 0) return
    busy.current = true
    try { await form.validate(['account']) } catch { busy.current = false; return }
    if (!mounted.current) { busy.current = false; return }
    setSending(true); challenge.current = ''
    deadline.current = Date.now() + 60_000; setRemaining(60)
    try {
      const account = form.getFieldValue('account')
      const result = await sendRegistrationCode(method, typeof account === 'string' ? account.trim() : '')
      if (!mounted.current) return
      challenge.current = result.challenge_id
      Message.success(method === 'mobile' ? '验证码已发送，请查收短信' : '验证码已发送，请查收邮件')
    } catch (cause) { if (mounted.current) reportPcAuthError(cause, fields => form.setFields(fields), ['account'], error => error instanceof Error ? error.message : '验证码发送失败') }
    finally { busy.current = false; if (mounted.current) setSending(false) }
  }
  async function submit(values: RegistrationForm) {
    if (!options || busy.current) return
    if (new TextEncoder().encode(values.password).length > 72) {
      form.setFields({ password: { error: { message: '密码最多 72 字节，请缩短密码' } } }); return
    }
    if (verification && !challenge.current) {
      form.setFields({ code: { error: { message: '请先获取当前账号的验证码' } } }); return
    }
    busy.current = true; setSaving(true)
    try {
      await registerMember({ method, account: values.account.trim(), password: values.password,
        code: verification ? values.code ?? '' : '', challenge_id: verification ? challenge.current : '' })
      if (!mounted.current) return
      Message.success(isOAuthRegistration(location.state) ? '注册成功，请完成账号关联' : '注册成功，请登录'); history.replace({ pathname: isOAuthRegistration(location.state) ? paths.oauthBind : paths.login, state: location.state })
    } catch (cause) { if (mounted.current) reportPcAuthError(cause, fields => form.setFields(fields), verification ? ['account', 'password', 'code'] : ['account', 'password'], error => error instanceof Error ? error.message : '注册失败，请稍后重试', { challenge_id: 'code' }) }
    finally { busy.current = false; if (mounted.current) setSaving(false) }
  }
  const methods: RegistrationMethod[] = options ? (['mobile', 'email'] as const).filter(key => options[`${key}_enabled`] === 1) : []
  return <AuthPageLayout titleId="register-title" variant="register">
      <div className="console-login-heading">
        <h1 id="register-title">欢迎注册</h1>
        <span>已有账号？<Link to={{ pathname: paths.login, state: location.state }}>立即登录</Link></span>
      </div>
      <p className="console-login-subtitle">创建账号，开始管理您的云业务</p>
      {!loading && loadFailed && <Button onClick={() => { setLoadFailed(false); setLoading(true); setRetry(value => value + 1) }}>重试</Button>}
      {loading ? <div className="registration-loading"><Spin /></div> : options && methods.length > 0 && <>
        {methods.length > 1 && <Tabs activeTab={method} size="large" animation={false} className="console-login-tabs" onChange={value => {
          if (busy.current) return
          setMethod(value as RegistrationMethod); challenge.current = ''; form.resetFields(['account', 'code'])
        }}>{methods.map(key => <Tabs.TabPane key={key} disabled={sending || saving} title={key === 'mobile' ? '手机号注册' : '邮箱注册'} />)}</Tabs>}
        <Form<RegistrationForm> className="console-register-form" form={form} layout="vertical" size="large" requiredSymbol={false} initialValues={{ agreement: false }} aria-labelledby="register-title" disabled={sending || saving} onSubmit={values => void submit(values)}
          onValuesChange={changed => { if (Object.hasOwn(changed, 'account')) { challenge.current = ''; form.resetFields(['code']) } }}>
          <Form.Item style={fieldStyle} field="account" label={method === 'mobile' ? '手机号' : '邮箱'} rules={[
            { required: true, message: method === 'mobile' ? '请输入手机号' : '请输入邮箱' },
            method === 'mobile' ? { match: /^1[3-9][0-9]{9}$/, message: '请输入有效的 11 位手机号' } : { type: 'email', message: '邮箱格式不正确' },
          ]}><Input prefix={method === 'mobile' ? <IconMobile style={{ fontSize: 16 }} /> : <IconEmail style={{ fontSize: 16 }} />} placeholder={method === 'mobile' ? '请输入手机号' : '请输入邮箱'} autoComplete={method === 'mobile' ? 'tel' : 'email'} maxLength={method === 'mobile' ? 11 : 254} /></Form.Item>
          {verification && <Form.Item style={fieldStyle} field="code" label="验证码" rules={[{ required: true, message: '请输入验证码' }, { match: /^[0-9]{6}$/, message: '请输入 6 位验证码' }]}>
            <RegistrationCodeInput sending={sending} saving={saving} remaining={remaining} onSend={() => void sendCode()} />
          </Form.Item>}
          <Form.Item style={fieldStyle} field="password" label="密码" rules={[{ required: true, message: '请输入密码' }, { minLength: 8, message: '密码至少 8 个字符' }, { maxLength: 72, message: '密码最多 72 个字符' }]}>
            <Input.Password visibilityToggle={false} prefix={<IconLock style={{ fontSize: 16 }} />} placeholder="请输入至少 8 个字符的密码" autoComplete="new-password" maxLength={72} />
          </Form.Item>
          <Form.Item style={fieldStyle} field="confirm" label="确认密码" dependencies={['password']} rules={[{ required: true, message: '请再次输入密码' },
            { validator: (value, callback) => callback(!value || value === form.getFieldValue('password') ? undefined : '两次输入的密码不一致') }]}>
            <Input.Password visibilityToggle={false} prefix={<IconLock style={{ fontSize: 16 }} />} placeholder="请再次输入密码" autoComplete="new-password" maxLength={72} />
          </Form.Item>
          <Form.Item field="agreement" triggerPropName="checked" style={fieldStyle} rules={[
            { validator: (value, callback) => callback(value === true ? undefined : '请阅读并同意服务协议和隐私政策') },
          ]}>
            <Checkbox disabled={sending || saving}>我已阅读并同意
              <ArcoLink href="" style={{ padding: 0 }} onClick={event => { event.preventDefault(); event.stopPropagation() }}>《服务协议》</ArcoLink>
              和<ArcoLink href="" style={{ padding: 0 }} onClick={event => { event.preventDefault(); event.stopPropagation() }}>《隐私政策》</ArcoLink>
            </Checkbox>
          </Form.Item>
          <Button type="primary" size="large" style={{ ...submitButtonStyle, fontWeight: 600 }} htmlType="submit" long loading={saving} disabled={sending}>注册</Button>
        </Form>
      </>}
  </AuthPageLayout>
}
