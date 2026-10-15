import { useEffect, useRef, useState } from 'react'
import { Button, Checkbox, Divider, Form, Input, Message, Tabs } from '@arco-design/web-react'
import { IconLock, IconMobile, IconSafe, IconUser } from '@arco-design/web-react/icon'
import { Link, useHistory, useLocation } from 'react-router-dom'
import { paths } from '../routes'
import { useMemberAuth } from '../auth/AuthContext'
import { completeOAuth, loginErrorMessage, loginWithSms, sendLoginCode, startOAuth, type LoginProvider } from '../api/loginApi'
import { loginReturnPath } from '../utils/loginReturnPath'
import { reportPcAuthError } from '../utils/pcAuthFeedback'
import wechatIcon from '../assets/wechat-login.svg'
import qqIcon from '../assets/qq-login.svg'
import { useAuthOptions } from '../hooks/useAuthOptions'

import ForgotPasswordModal from './ForgotPasswordModal'
import AuthPageLayout from '../components/AuthPageLayout'

interface LoginFields { account: string; password: string; mobile: string; code: string }
const returnPathKey = 'console.oauth.return-path'
/** 登录主按钮保留独立登录页的触控尺寸，输入框使用 Arco 原生大号尺寸。 */
const loginButtonStyle = { height: 48, fontSize: 16 }

/** 会员登录：密码、短信验证码与第三方授权共用原有会话及返回路径。 */
export default function LoginPage() {
  const options = useAuthOptions(error => { Message.error(loginErrorMessage(error)) })
  const location = useLocation()
  const history = useHistory()
  const { login, establishSession } = useMemberAuth()
  const [form] = Form.useForm<LoginFields>()
  const [method, setMethod] = useState('password')
  const [remember, setRemember] = useState(false)
  const [forgotOpen, setForgotOpen] = useState(false)
  const [submitting, setSubmitting] = useState(() => {
    const query = new URLSearchParams(location.search)
    return ['code', 'state', 'error'].some(key => query.has(key))
  })
  const [sending, setSending] = useState(false)
  const [oauthLoading, setOAuthLoading] = useState<LoginProvider | null>(null)
  const [deadline, setDeadline] = useState(0)
  const [remaining, setRemaining] = useState(0)
  const challenge = useRef<{ id: string; mobile: string; expires: number } | null>(null)
  const sendingBusy = useRef(false)
  const submitBusy = useRef(false)
  const callbackStarted = useRef(false)
  const mounted = useRef(true)
  const busy = submitting || sending || oauthLoading !== null

  useEffect(() => {
    mounted.current = true
    document.body.setAttribute('arco-theme', 'light')
    return () => { mounted.current = false; document.body.removeAttribute('arco-theme') }
  }, [])
  useEffect(() => {
    const update = () => setRemaining(Math.max(0, Math.ceil((deadline - Date.now()) / 1000)))
    update()
    if (!deadline) return
    const timer = window.setInterval(update, 500)
    return () => window.clearInterval(timer)
  }, [deadline])
  useEffect(() => {
    const query = new URLSearchParams(location.search)
    if (callbackStarted.current || !['code', 'state', 'error'].some(key => query.has(key))) return
    callbackStarted.current = true
    // 授权码不留在地址栏、浏览器存储或后续导航的 URL 中。
    let from = paths.overview as string
    try { from = loginReturnPath({ from: sessionStorage.getItem(returnPathKey) }); sessionStorage.removeItem(returnPathKey) } catch { /* 存储不可用时返回概览。 */ }
    history.replace({ pathname: paths.login, state: { from } })
    const code = query.get('code'), state = query.get('state')
    void Promise.resolve().then(() => {
      if (query.has('error') || !code || !state || code.length > 2048 || state.length > 512) throw new Error('第三方授权未完成，请重新选择登录方式')
      return completeOAuth(state, code)
    }).then(result => {
      if (!mounted.current) return
      if (result.status === 'binding_required') history.replace({ pathname: paths.oauthBind, state: { from } })
      else return establishSession(result.user)
    }).catch(failure => { if (mounted.current) Message.error(loginErrorMessage(failure)) })
      .finally(() => { if (mounted.current) setSubmitting(false) })
  }, [location.search, history, establishSession])

  async function sendCode() {
    if (busy || sendingBusy.current || remaining > 0) return
    sendingBusy.current = true
    try { await form.validate(['mobile']) } catch { sendingBusy.current = false; return }
    const mobile = form.getFieldValue('mobile').trim()
    setSending(true)
    try {
      const response = await sendLoginCode(mobile)
      if (!mounted.current) return
      challenge.current = { id: response.challenge_id, mobile, expires: Date.now() + response.expires_in * 1000 }
      setDeadline(Date.now() + response.retry_after * 1000)
      Message.success('请求已受理，账号可验证时将收到短信')
    } catch (failure) { if (mounted.current) reportPcAuthError(failure, fields => form.setFields(fields), ['mobile'], loginErrorMessage) }
    finally { sendingBusy.current = false; if (mounted.current) setSending(false) }
  }
  async function submit(values: LoginFields) {
    if (busy || submitBusy.current || sendingBusy.current) return
    if (method === 'sms' && (!challenge.current || challenge.current.mobile !== values.mobile.trim() || challenge.current.expires <= Date.now())) {
      form.setFields({ code: { error: { message: '请先获取当前手机号的有效验证码' } } }); return
    }
    submitBusy.current = true; setSubmitting(true)
    try {
      if (method === 'password') await login(values.account.trim(), values.password, remember)
      else await establishSession(await loginWithSms(values.mobile.trim(), values.code.trim(), challenge.current!.id, remember))
    } catch (failure) { if (mounted.current) reportPcAuthError(failure, fields => form.setFields(fields), method === 'password' ? ['account', 'password'] : ['mobile', 'code'], loginErrorMessage, { challenge_id: 'code' }) }
    finally { submitBusy.current = false; if (mounted.current) setSubmitting(false) }
  }
  async function authorize(provider: LoginProvider) {
    if (busy) return
    setOAuthLoading(provider)
    try {
      const url = await startOAuth(provider, remember)
      if (!mounted.current) return
      try { sessionStorage.setItem(returnPathKey, loginReturnPath(location.state)) } catch { /* 存储不可用仍可授权，回调默认进入概览。 */ }
      window.location.assign(url)
    } catch (failure) { if (mounted.current) { Message.error(loginErrorMessage(failure)); setOAuthLoading(null) } }
  }

  return <>
    <AuthPageLayout titleId="login-title">
        <div className="console-login-heading">
          <h1 id="login-title">欢迎登录</h1>
          <span>没有账号？<Link to={{ pathname: paths.register, state: location.state }}>立即注册</Link></span>
        </div>
        <p className="console-login-subtitle">登录即可管理您的资源与服务</p>
        <Tabs activeTab={method} onChange={value => {
          if (busy) return
          setMethod(value); form.clearFields(); challenge.current = null
        }} size="large" animation={false} className="console-login-tabs">
          <Tabs.TabPane key="password" title="密码登录" disabled={busy} />
          <Tabs.TabPane key="sms" title="验证码登录" disabled={busy || !options.data?.sms_login} />
        </Tabs>
        <Form<LoginFields> form={form} layout="vertical" size="large" requiredSymbol={false} aria-labelledby="login-title" onSubmit={submit}
          onValuesChange={changed => {
            if (Object.hasOwn(changed, 'mobile')) { challenge.current = null; form.resetFields(['code']) }
          }}>
          {method === 'password' ? <>
            <Form.Item field="account" label="账号" rules={[
              { required: true, message: '请输入手机号或邮箱' },
              { match: /^(?:\+?[0-9]{6,20}|[^\s@]+@[^\s@]+\.[^\s@]+)$/, message: '请输入有效的手机号或邮箱' },
            ]}><Input prefix={<IconUser style={{ fontSize: 16 }} />} placeholder="请输入手机号或邮箱" autoComplete="username" maxLength={254} disabled={busy} /></Form.Item>
            <Form.Item field="password" label="密码" rules={[
              { required: true, message: '请输入密码' }, { maxLength: 72, message: '密码不能超过 72 个字符' },
              { validator: (value: string | undefined, callback: (error?: string) => void) => callback(value && new TextEncoder().encode(value).length > 72 ? '密码最多 72 字节' : undefined) },
            ]}><Input.Password visibilityToggle={false} prefix={<IconLock style={{ fontSize: 16 }} />} placeholder="请输入密码" autoComplete="current-password" disabled={busy} /></Form.Item>
          </> : <>
            <Form.Item field="mobile" label="手机号" rules={[
              { required: true, message: '请输入手机号' }, { match: /^1[3-9][0-9]{9}$/, message: '请输入有效的 11 位手机号' },
            ]}><Input prefix={<IconMobile style={{ fontSize: 16 }} />} placeholder="请输入手机号" autoComplete="tel" inputMode="tel" maxLength={11} disabled={busy} /></Form.Item>
            <div className="console-login-code">
              <Form.Item field="code" label="验证码" rules={[
                { required: true, message: '请输入验证码' }, { match: /^[0-9]{6}$/, message: '请输入 6 位数字验证码' },
              ]}><Input prefix={<IconSafe style={{ fontSize: 16 }} />} placeholder="请输入验证码" autoComplete="one-time-code" inputMode="numeric" maxLength={6} disabled={busy} /></Form.Item>
              <Button className="console-login-send" size="large" style={{ padding: '0 12px' }} htmlType="button" onClick={() => { void sendCode() }} loading={sending}
                disabled={submitting || oauthLoading !== null || remaining > 0}>{remaining > 0 ? `${remaining} 秒后重发` : '获取验证码'}</Button>
            </div>
          </>}
          <div className="console-login-options">
            <Checkbox checked={remember} disabled={busy} onChange={setRemember}>记住我</Checkbox>
            <Button type="text" htmlType="button" disabled={busy} onClick={() => setForgotOpen(true)} style={{ padding: 0, height: 'auto' }}>忘记密码？</Button>
          </div>
          <Button type="primary" size="large" style={{ ...loginButtonStyle, fontWeight: 600 }} htmlType="submit" long loading={submitting} disabled={sending || oauthLoading !== null}>登录</Button>
        </Form>
        <p className="console-login-security"><IconLock />安全连接，保护您的账号与数据</p>
        {Boolean(options.data?.oauth_providers.length) && <><Divider style={{ margin: '16px 0 0' }}><span style={{ color: 'var(--color-text-3)', fontSize: 12, fontWeight: 400 }}>其他登录方式</span></Divider>
        <div className="console-login-social">
          {options.data?.oauth_providers.includes('wechat') && <Button type="outline" long size="large" style={{ padding: '0 10px' }}
            icon={<img className="console-login-provider-icon" src={wechatIcon} alt="" style={{ opacity: busy ? 0.45 : 1 }} />} loading={oauthLoading === 'wechat'}
            disabled={submitting || sending || oauthLoading === 'qq'} onClick={() => { void authorize('wechat') }}>微信登录</Button>}
          {options.data?.oauth_providers.includes('qq') && <Button type="outline" long size="large" style={{ padding: '0 10px' }}
            icon={<img className="console-login-provider-icon" src={qqIcon} alt="" style={{ opacity: busy ? 0.45 : 1 }} />} loading={oauthLoading === 'qq'}
            disabled={submitting || sending || oauthLoading === 'wechat'} onClick={() => { void authorize('qq') }}>QQ 登录</Button>}
        </div></>}
    </AuthPageLayout>
    {forgotOpen && <ForgotPasswordModal onClose={() => setForgotOpen(false)} />}
  </>
}
