import { useState } from 'react'
import { Link, useHistory, useLocation } from 'react-router-dom'
import Button from '@arco-design/mobile-react/esm/button'
import Form, { useForm } from '@arco-design/mobile-react/esm/form'
import { AuthShell } from './AuthPages'
import { Busy, Field } from './ui'
import { useFeedback } from './feedback'
import { useOAuthBinding } from '../hooks/useOAuthBinding'
import { authFieldErrors } from '../utils/authFieldErrors'
import { loginReturnPath } from '../utils/loginReturnPath'
import { paths } from '../routePaths'

/** 移动端独立关联页，复用会员验证契约与原生Mobile Form。 */
export default function OAuthBindPage() {
  const [form] = useForm(), history = useHistory(), location = useLocation(), feedback = useFeedback()
  const [account, setAccount] = useState(''), [password, setPassword] = useState(''), [errors, setErrors] = useState<Record<string, string>>({})
  const binding = useOAuthBinding(error => { feedback(error instanceof Error ? error.message : '关联请求加载失败') })
  const from = loginReturnPath(location.state)
  async function submit() {
    setErrors({})
    try { if (await binding.submit(account, password)) history.replace(from) }
    catch (error) {
      const fields = authFieldErrors(error, ['account', 'password'])
      if (Object.keys(fields).length) setErrors(fields)
      else feedback(error instanceof Error ? error.message : '账号关联失败')
    }
  }
  return <AuthShell title="关联平台账号" subtitle={`验证平台账号，完成${binding.pending?.provider === 'qq' ? ' QQ ' : '微信'}登录关联`} back>
    {binding.loading ? <Busy /> : !binding.pending ? <Link to={{ pathname: paths.login, state: { from } }}>返回登录并重新授权</Link> : <>
      <Form form={form} layout="vertical" onSubmit={() => { void submit() }} onSubmitFailed={() => setErrors({})}>
        <Field label="账号" value={account} maxLength={254} autoComplete="username" placeholder="请输入手机号或邮箱" disabled={binding.busy} error={errors.account}
          onChange={value => { setAccount(value); setErrors({}) }} validate={value => /^(?:\+?[0-9]{6,20}|[^\s@]+@[^\s@]+\.[^\s@]+)$/.test(value.trim()) ? undefined : '请输入有效的手机号或邮箱'} />
        <Field label="密码" type="password" value={password} maxLength={72} autoComplete="current-password" placeholder="请输入平台账号的密码" disabled={binding.busy} error={errors.password}
          onChange={value => { setPassword(value); setErrors({}) }} validate={value => value && new TextEncoder().encode(value).length <= 72 ? undefined : '请输入密码，最多 72 字节'} />
        <Button className="m-auth-submit" size="huge" loading={binding.busy} onClick={() => form.submit()}>关联并登录</Button>
      </Form>
      <p className="m-auth-registration">没有平台账号？ <Link to={{ pathname: paths.register, state: { from, oauthBinding: true } }}>立即注册</Link></p>
    </>}
  </AuthShell>
}
