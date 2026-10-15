import { useAuthOptions } from '../hooks/useAuthOptions'
import { useFeedback } from './feedback'
import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useHistory, useLocation } from 'react-router-dom'
import Button from '@arco-design/mobile-react/esm/button'
import Checkbox from '@arco-design/mobile-react/esm/checkbox'
import Dialog from '@arco-design/mobile-react/esm/dialog'
import Tabs from '@arco-design/mobile-react/esm/tabs'
import Form, { useForm } from '@arco-design/mobile-react/esm/form'
import { ValidatorType } from '@arco-design/mobile-utils'
import { useMemberAuth } from '../auth/AuthContext'
import { completeOAuth, loginErrorMessage, loginWithSms, sendLoginCode, startOAuth, type LoginProvider } from '../api/loginApi'
import { getRegistrationOptions, registerMember, sendRegistrationCode, type RegistrationMethod } from '../api/registrationApi'
import { passwordResetErrorMessage, resetPassword, sendPasswordResetCode } from '../api/passwordResetApi'
import { isOAuthRegistration } from '../utils/oauthNavigation'
import { loginReturnPath } from '../utils/loginReturnPath'
import { paths } from '../routePaths'
import qqIcon from '../assets/qq-login.svg'
import wechatIcon from '../assets/wechat-login.svg'
import { Brand, Busy, Field, Footer, Status } from './ui'
import { useChallenge } from './useChallenge'
import { authFieldErrors } from '../utils/authFieldErrors'

const accountPattern = /^(?:\+?[0-9]{6,20}|[^\s@]+@[^\s@]+\.[^\s@]+)$/
const resetAccountPattern = /^(?:1[3-9][0-9]{9}|[^\s@]+@[^\s@]+\.[^\s@]+)$/
const mobilePattern = /^1[3-9][0-9]{9}$/
const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const codePattern = /^[0-9]{6}$/
const returnPathKey = 'console.oauth.return-path'
type Errors = Partial<Record<'account' | 'password' | 'confirm' | 'code' | 'agreement', string>>
const errorText = (error: unknown) => error instanceof Error ? error.message : '操作失败，请稍后重试'
const passwordValid = (password: string) => password.length >= 8 && new TextEncoder().encode(password).length <= 72

/** 字段错误留在字段，全局 Toast 只处理没有对应控件的失败。 */
function reportMobileAuthError(error: unknown, setErrors: (errors: Errors) => void, feedback: (text: string) => void, fields: readonly string[], format = errorText) {
  const errors = authFieldErrors(error, fields, { mobile: 'account', challenge_id: 'code' })
  if (Object.keys(errors).length) setErrors(errors)
  else feedback(format(error))
}
const oauthProviders = [
  { provider: 'wechat', label: '微信', icon: wechatIcon },
  { provider: 'qq', label: 'QQ', icon: qqIcon },
] satisfies Array<{ provider: LoginProvider; label: string; icon: string }>

/** Mobile Form 的通用字段通过 value 绑定，Checkbox 的显示值使用 checked。 */
type AgreementDocument = 'service' | 'privacy'
function AgreementInput({ value = false, onChange, disabled, onOpen }: { value?: boolean; onChange?: (checked: boolean) => void; disabled?: boolean; onOpen: (document: AgreementDocument) => void }) {
  return <div className="m-agreement-control">
    <Checkbox className="m-agreement-choice" value="agreement" checked={value} onChange={checked => onChange?.(checked)} disabled={disabled}><span>我已阅读并同意</span></Checkbox>
    <span className="m-policy-links">
      <button type="button" className="m-text-button m-policy" onClick={() => onOpen('service')}>《服务协议》</button>
      <span className="m-policy-conjunction">和</span>
      <button type="button" className="m-text-button m-policy" onClick={() => onOpen('privacy')}>《隐私政策》</button>
    </span>
  </div>
}

function useActivity() {
  const mounted = useRef(true), lock = useRef(false)
  const [busy, setBusy] = useState(false)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  return { busy, mounted, begin: () => { if (lock.current) return false; lock.current = true; setBusy(true); return true },
    end: () => { lock.current = false; if (mounted.current) setBusy(false) } }
}

export function AuthShell({ title, subtitle, back = false, children }: { title: string; subtitle: string; back?: boolean; children: React.ReactNode }) {
  const location = useLocation()
  return <div className="m-auth">
    <header className="m-auth-hero">
      <div className="m-auth-header">
        <a className="m-auth-brand" href="/" aria-label="返回官网"><Brand /></a>
        {back ? <Link className="m-auth-back" to={{ pathname: paths.login, state: location.state }}><span aria-hidden="true">‹</span>返回登录</Link> : <a className="m-auth-back" href="/"><span aria-hidden="true">‹</span>返回官网</a>}
      </div>
      <div className="m-auth-intro"><h1>{title}</h1><p>{subtitle}</p></div>
    </header>
    <main className="m-auth-content">{children}</main><Footer />
  </div>
}

/** 三种认证用途共用固定宽度的验证码操作，倒计时不改变输入区宽度。 */
function VerificationButton({ busy, sending, remaining, onSend }: { busy: boolean; sending: boolean; remaining: number; onSend: () => Promise<void> }) {
  return <span className="m-code-action"><Button inline size="medium" className="m-code-button" loading={sending} disabled={busy || remaining > 0} onClick={() => { void onSend() }}>
    {sending ? '发送中…' : remaining > 0 ? `${remaining}s 后重发` : '获取验证码'}
  </Button></span>
}

export function LoginPage() {
  const [form] = useForm()
  const auth = useMemberAuth(), history = useHistory(), location = useLocation(), feedback = useFeedback()
  const establishSession = auth.establishSession
  const options = useAuthOptions(error => { feedback(loginErrorMessage(error)) })
  const [method, setMethod] = useState(0), [account, setAccount] = useState(''), [password, setPassword] = useState(''), [code, setCode] = useState('')
  const [remember, setRemember] = useState(false), [errors, setErrors] = useState<Errors>({})
  const activity = useActivity(), challenge = useChallenge(), callbackStarted = useRef(false)
  const busy = activity.busy || challenge.busy
  useEffect(() => {
    const query = new URLSearchParams(location.search)
    if (callbackStarted.current || !['code', 'state', 'error'].some(key => query.has(key))) return
    callbackStarted.current = true
    let from: string = paths.overview
    try { from = loginReturnPath({ from: sessionStorage.getItem(returnPathKey) }); sessionStorage.removeItem(returnPathKey) } catch { /* 仅保存无敏感信息的返回路径。 */ }
    history.replace({ pathname: paths.login, state: { from } })
    activity.begin()
    const authorizationCode = query.get('code'), state = query.get('state')
    void Promise.resolve().then(() => {
      if (query.has('error') || !authorizationCode || !state || authorizationCode.length > 2048 || state.length > 512) throw new Error('第三方授权未完成，请重新登录')
      return completeOAuth(state, authorizationCode)
    }).then(result => {
      if (!activity.mounted.current) return
      if (result.status === 'binding_required') history.replace({ pathname: paths.oauthBind, state: { from } })
      else return establishSession(result.user)
    }).catch(error => { if (activity.mounted.current) feedback(loginErrorMessage(error)) }).finally(activity.end)
  }, [location.search, history, establishSession, activity, feedback])
  async function send() {
    if (busy) return
    if (!mobilePattern.test(account.trim())) { setErrors(form.getFieldError('手机号').length ? {} : { account: '请输入有效的 11 位手机号' }); return }
    try { if (await challenge.send(account.trim(), () => sendLoginCode(account.trim()))) feedback('请求已受理，账号可验证时将收到验证码', 'success') }
    catch (error) { if (activity.mounted.current) reportMobileAuthError(error, setErrors, feedback, ['account'], loginErrorMessage) }
  }
  async function submit() {
    if (busy) return
    const id = challenge.getId(account.trim())
    setErrors({})
    if (!activity.begin()) return
    try {
      if (method === 0) await auth.login(account.trim(), password, remember)
      else await auth.establishSession(await loginWithSms(account.trim(), code, id, remember))
    } catch (error) { if (activity.mounted.current) reportMobileAuthError(error, setErrors, feedback, method === 0 ? ['account', 'password'] : ['account', 'code'], loginErrorMessage) } finally { activity.end() }
  }
  async function authorize(provider: LoginProvider) {
    if (busy || !activity.begin()) return
    try {
      const url = await startOAuth(provider, remember)
      if (!activity.mounted.current) return
      try { sessionStorage.setItem(returnPathKey, loginReturnPath(location.state)) } catch { /* 存储不可用时回总览。 */ }
      window.location.assign(url)
    } catch (error) { if (activity.mounted.current) feedback(loginErrorMessage(error)); activity.end() }
  }
  return <AuthShell title="欢迎登录" subtitle="使用您的账号访问会员中心">
    <Tabs className="m-auth-tabs" tabBarGutter={28} tabBarArrange="start" tabBarHasDivider={false} tabBarPadding={0} tabs={options.data?.sms_login ? ['密码登录', '验证码登录'] : ['密码登录']} activeTab={method} swipeable={false} disabled={busy} onChange={(_, index) => {
      form.resetFields(); setMethod(index); setAccount(''); setPassword(''); setCode(''); setErrors({}); challenge.clear()
    }} />
    <Form form={form} layout="vertical" onSubmit={() => { void submit() }} onSubmitFailed={() => setErrors({})}>
      <Field label={method === 0 ? '账号' : '手机号'} value={account} onChange={value => { setAccount(value); setCode(''); form.setFieldValue('验证码', ''); challenge.clear(); setErrors({}) }}
        validate={value => (method === 0 ? accountPattern : mobilePattern).test(value.trim()) ? undefined : method === 0 ? '请输入有效的手机号或邮箱' : '请输入有效的 11 位手机号'}
        type={method === 0 ? 'text' : 'tel'} autoComplete={method === 0 ? 'username' : 'tel'} maxLength={method === 0 ? 254 : 11}
        placeholder={method === 0 ? '请输入手机号或邮箱' : '请输入手机号'} disabled={busy} error={errors.account} />
      {method === 0 ? <Field key="login-password" label="密码" type="password" value={password} onChange={value => { setPassword(value); setErrors(current => ({ ...current, password: undefined, confirm: undefined })) }} validate={value => value && new TextEncoder().encode(value).length <= 72 ? undefined : '请输入密码，最多 72 字节'} placeholder="请输入密码" autoComplete="current-password" disabled={busy} error={errors.password} /> :
        <Field label="验证码" value={code} onChange={value => { setCode(value); setErrors(current => ({ ...current, code: undefined })) }} placeholder="请输入验证码" autoComplete="one-time-code" maxLength={6} disabled={busy} error={errors.code}
          validate={value => !challenge.getId(account.trim()) ? '请先获取当前手机号的有效验证码' : codePattern.test(value) ? undefined : '请输入 6 位数字验证码'}
          suffix={<VerificationButton busy={busy} sending={challenge.busy} remaining={challenge.remaining} onSend={send} />} />}
      <div className="m-form-options"><Checkbox value="remember" checked={remember} onChange={setRemember} disabled={busy}>记住我</Checkbox><Link to="/forgot-password">忘记密码</Link></div>
      <Button className="m-auth-submit" size="huge" loading={activity.busy} disabled={challenge.busy} onClick={() => form.submit()}>登录</Button>
    </Form>
    <p className="m-auth-registration">还没有账号？ <Link to={{ pathname: paths.register, state: location.state }}>立即注册</Link></p>
    {Boolean(options.data?.oauth_providers.length) && <><div className="m-social-heading">其他登录方式</div><div className="m-social">
      {oauthProviders.filter(item => options.data?.oauth_providers.includes(item.provider)).map(({ provider, label, icon }) => <div className="m-social-item" key={provider}>
        <Button type="default" shape="round" size="huge" inline disabled={busy} style={{ width: 'var(--button-huge-height)', padding: 0 }}
          icon={<img className="m-social-icon" src={icon} alt={`${label}登录`} />} onClick={() => { void authorize(provider) }} />
        <span className="m-social-label">{label}</span>
      </div>)}
    </div></>}
  </AuthShell>
}

export function RegisterPage() {
  const [form] = useForm()
  const history = useHistory(), location = useLocation(), feedback = useFeedback(), activity = useActivity(), challenge = useChallenge()
  const options = useQuery({ queryKey: ['public', 'registration'], queryFn: ({ signal }) => getRegistrationOptions(signal), retry: false })
  const reportedOptionsError = useRef<unknown>(null)
  useEffect(() => {
    if (options.error && reportedOptionsError.current !== options.error) feedback(errorText(options.error))
    reportedOptionsError.current = options.error
  }, [options.error, feedback])
  const [choice, setChoice] = useState<RegistrationMethod>('mobile')
  const [account, setAccount] = useState(''), [password, setPassword] = useState(''), [confirm, setConfirm] = useState(''), [code, setCode] = useState('')
  const [errors, setErrors] = useState<Errors>({}), [terms, setTerms] = useState<AgreementDocument | null>(null)
  const methods: RegistrationMethod[] = []
  if (options.data?.mobile_enabled) methods.push('mobile')
  if (options.data?.email_enabled) methods.push('email')
  const method = methods.includes(choice) ? choice : methods[0]
  const verification = method === 'mobile' ? options.data?.mobile_verification : options.data?.email_verification
  const key = `${method}:${account.trim()}`, busy = activity.busy || challenge.busy
  const validAccount = () => (method === 'mobile' ? mobilePattern : emailPattern).test(account.trim())
  async function send() {
    if (busy || !method) return
    if (!validAccount()) { setErrors(form.getFieldError(method === 'mobile' ? '手机号' : '邮箱').length ? {} : { account: `请输入有效的${method === 'mobile' ? '手机号' : '邮箱'}` }); return }
    try { if (await challenge.send(key, () => sendRegistrationCode(method, account.trim()))) feedback('请求已受理，账号可验证时将收到验证码', 'success') }
    catch (error) { if (activity.mounted.current) reportMobileAuthError(error, setErrors, feedback, ['account']) }
  }
  async function submit() {
    if (busy || !method) return
    const id = challenge.getId(key)
    setErrors({})
    if (!activity.begin()) return
    try {
      await registerMember({ method, account: account.trim(), password, code: verification ? code : '', challenge_id: verification ? id : '' })
      if (activity.mounted.current) { feedback(isOAuthRegistration(location.state) ? '注册成功，请完成账号关联' : '注册成功，请登录', 'success'); history.replace({ pathname: isOAuthRegistration(location.state) ? paths.oauthBind : paths.login, state: location.state }) }
    } catch (error) { if (activity.mounted.current) reportMobileAuthError(error, setErrors, feedback, verification ? ['account', 'password', 'code'] : ['account', 'password']) } finally { activity.end() }
  }
  return <AuthShell title="创建账号" subtitle="填写信息，创建您的会员账号" back>
    {options.isPending ? <Busy /> : options.isError ? <Button type="default" onClick={() => { void options.refetch() }}>重试</Button> : !methods.length ? <Status text="当前平台暂未开放注册" /> : <>
      {methods.length > 1 && <Tabs className="m-auth-tabs" tabBarGutter={28} tabBarArrange="start" tabBarHasDivider={false} tabBarPadding={0} tabs={methods.map(item => item === 'mobile' ? '手机号注册' : '邮箱注册')} activeTab={methods.indexOf(method)} disabled={busy} swipeable={false} onChange={(_, index) => {
        form.resetFields(); setChoice(methods[index]); setAccount(''); setPassword(''); setConfirm(''); setCode(''); setErrors({}); challenge.clear()
      }} />}
      <Form form={form} layout="vertical" onSubmit={() => { void submit() }} onSubmitFailed={() => setErrors({})}>
        <Field label={method === 'mobile' ? '手机号' : '邮箱'} value={account} type={method === 'mobile' ? 'tel' : 'email'} autoComplete={method === 'mobile' ? 'tel' : 'email'}
          validate={value => (method === 'mobile' ? mobilePattern : emailPattern).test(value.trim()) ? undefined : `请输入有效的${method === 'mobile' ? '手机号' : '邮箱'}`}
          maxLength={method === 'mobile' ? 11 : 254} onChange={value => { setAccount(value); setCode(''); form.setFieldValue('验证码', ''); challenge.clear(); setErrors({}) }} placeholder={`请输入${method === 'mobile' ? '手机号' : '邮箱'}`} error={errors.account} disabled={busy} />
        {Boolean(verification) && <Field label="验证码" value={code} onChange={value => { setCode(value); setErrors(current => ({ ...current, code: undefined })) }} placeholder="请输入验证码" autoComplete="one-time-code" maxLength={6} error={errors.code} disabled={busy}
          validate={value => codePattern.test(value) && challenge.getId(key) ? undefined : '请获取并填写当前账号的有效验证码'}
          suffix={<VerificationButton busy={busy} sending={challenge.busy} remaining={challenge.remaining} onSend={send} />} />}
        <Field key={`password-${method}`} label="密码" type="password" value={password} onChange={value => { setPassword(value); setErrors(current => ({ ...current, password: undefined, confirm: undefined })) }} validate={value => passwordValid(value) ? undefined : '密码至少 8 个字符，最多 72 字节'} placeholder="请设置登录密码" autoComplete="new-password" error={errors.password} disabled={busy} />
        <Field key={`confirm-${method}`} label="确认密码" type="password" value={confirm} onChange={value => { setConfirm(value); setErrors(current => ({ ...current, confirm: undefined })) }} validate={value => value === password ? undefined : '两次输入的密码不一致'} placeholder="请再次输入密码" autoComplete="new-password" error={errors.confirm} disabled={busy} />
        <Form.Item field="agreement" className="m-agreement" layout="horizontal" disabled={busy} initialValue={false} rules={[{ type: ValidatorType.Boolean, equal: true, message: '请阅读并同意服务协议和隐私政策' }]}><AgreementInput onOpen={setTerms} /></Form.Item>
        {errors.agreement && <p className="m-field-error" role="alert">{errors.agreement}</p>}
        <Button className="m-auth-submit" size="huge" loading={activity.busy} disabled={challenge.busy} onClick={() => form.submit()}>注册</Button>
      </Form>
    </>}
    <Dialog title={terms === 'service' ? '服务协议' : terms === 'privacy' ? '隐私政策' : undefined} visible={Boolean(terms)} close={() => setTerms(null)} maskClosable animatingClosable maskClass="m-agreement-mask" footer={[{ content: '关闭', onClick: () => setTerms(null) }]}>
      {terms && <div role="document" aria-label={terms === 'service' ? '服务协议' : '隐私政策'}>{terms === 'service' ? '服务协议' : '隐私政策'}尚未发布，请联系平台获取后再确认。</div>}
    </Dialog>
  </AuthShell>
}

export function ResetPasswordPage() {
  const [form] = useForm()
  const feedback = useFeedback(), history = useHistory(), activity = useActivity(), challenge = useChallenge()
  const [account, setAccount] = useState(''), [code, setCode] = useState(''), [password, setPassword] = useState(''), [confirm, setConfirm] = useState(''), [errors, setErrors] = useState<Errors>({})
  const busy = activity.busy || challenge.busy
  async function send() {
    if (busy) return
    if (!resetAccountPattern.test(account.trim())) { setErrors(form.getFieldError('账号').length ? {} : { account: '请输入绑定的手机号或邮箱' }); return }
    try { if (await challenge.send(account.trim(), () => sendPasswordResetCode(account.trim()))) feedback('请求已受理，账号可验证时将收到验证码', 'success') }
    catch (error) { if (activity.mounted.current) reportMobileAuthError(error, setErrors, feedback, ['account'], passwordResetErrorMessage) }
  }
  async function submit() {
    if (busy) return
    const id = challenge.getId(account.trim())
    setErrors({})
    if (!activity.begin()) return
    try {
      await resetPassword(account.trim(), code, id, password)
      if (activity.mounted.current) { feedback('密码已重置，请重新登录', 'success'); history.replace(paths.login) }
    } catch (error) { if (activity.mounted.current) reportMobileAuthError(error, setErrors, feedback, ['account', 'code', 'password'], passwordResetErrorMessage) } finally { activity.end() }
  }
  return <AuthShell title="找回密码" subtitle="通过绑定的手机号或邮箱验证身份" back><Form form={form} layout="vertical" onSubmit={() => { void submit() }} onSubmitFailed={() => setErrors({})}>
    <Field label="账号" value={account} validate={value => resetAccountPattern.test(value.trim()) ? undefined : '请输入绑定的手机号或邮箱'} onChange={value => { setAccount(value); setCode(''); form.setFieldValue('验证码', ''); challenge.clear(); setErrors({}) }} placeholder="请输入绑定的手机号或邮箱" error={errors.account} disabled={busy} />
    <Field label="验证码" value={code} onChange={value => { setCode(value); setErrors(current => ({ ...current, code: undefined })) }} placeholder="请输入验证码" autoComplete="one-time-code" maxLength={6} error={errors.code} disabled={busy}
      validate={value => codePattern.test(value) && challenge.getId(account.trim()) ? undefined : '请获取并填写有效验证码'}
      suffix={<VerificationButton busy={busy} sending={challenge.busy} remaining={challenge.remaining} onSend={send} />} />
    <Field label="新密码" type="password" value={password} onChange={value => { setPassword(value); setErrors(current => ({ ...current, password: undefined, confirm: undefined })) }} validate={value => passwordValid(value) ? undefined : '密码至少 8 个字符，最多 72 字节'} placeholder="请输入新密码" autoComplete="new-password" error={errors.password} disabled={busy} />
    <Field label="确认密码" type="password" value={confirm} onChange={value => { setConfirm(value); setErrors(current => ({ ...current, confirm: undefined })) }} validate={value => value === password ? undefined : '两次输入的密码不一致'} placeholder="请再次输入新密码" autoComplete="new-password" error={errors.confirm} disabled={busy} />
    <Button className="m-auth-submit" size="huge" loading={activity.busy} disabled={challenge.busy} onClick={() => form.submit()}>重置密码</Button>
  </Form></AuthShell>
}
