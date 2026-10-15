import { Button, Form, Input, Message } from '@arco-design/web-react'
import { Link, useHistory, useLocation } from 'react-router-dom'
import AuthPageLayout from '../components/AuthPageLayout'
import PageLoading from '../components/PageLoading'
import { useOAuthBinding } from '../hooks/useOAuthBinding'
import { paths } from '../routePaths'
import { reportPcAuthError } from '../utils/pcAuthFeedback'
import { loginReturnPath } from '../utils/loginReturnPath'

interface Fields { account: string; password: string }
/** 首次第三方登录，明确验证并关联平台账号。 */
export default function OAuthBindPage() {
  const [form] = Form.useForm<Fields>(), location = useLocation(), history = useHistory()
  const binding = useOAuthBinding(error => { Message.error(error instanceof Error ? error.message : '关联请求加载失败') })
  const from = loginReturnPath(location.state)
  async function submit(values: Fields) {
    try { if (await binding.submit(values.account, values.password)) history.replace(from) }
    catch (error) { reportPcAuthError(error, fields => form.setFields(fields), ['account', 'password'], error => error instanceof Error ? error.message : '账号关联失败') }
  }
  return <AuthPageLayout titleId="oauth-bind-title">
    <div className="console-login-heading"><h1 id="oauth-bind-title">关联平台账号</h1></div>
    <p className="console-login-subtitle">验证您的平台账号，完成{binding.pending?.provider === 'qq' ? ' QQ ' : '微信'}登录关联。</p>
    {binding.loading ? <PageLoading /> : !binding.pending ? <Link to={{ pathname: paths.login, state: { from } }}>返回登录并重新授权</Link> : <>
      <Form<Fields> form={form} layout="vertical" size="large" disabled={binding.busy} onSubmit={submit}>
        <Form.Item field="account" label="手机号或邮箱" rules={[{ required: true, message: '请输入平台账号' }, { match: /^(?:\+?[0-9]{6,20}|[^\s@]+@[^\s@]+\.[^\s@]+)$/, message: '请输入有效的手机号或邮箱' }]}>
          <Input maxLength={254} placeholder="请输入平台账号" autoComplete="username" />
        </Form.Item>
        <Form.Item field="password" label="登录密码" rules={[{ required: true, message: '请输入登录密码' }, { validator: (value: string | undefined, callback) => { if (value && new TextEncoder().encode(value).length > 72) callback('密码最多 72 字节') } }]}>
          <Input.Password visibilityToggle={false} maxLength={72} placeholder="请输入平台账号的密码" autoComplete="current-password" />
        </Form.Item>
        <Button type="primary" htmlType="submit" long loading={binding.busy}>关联并登录</Button>
      </Form>
      <p className="console-login-registration">没有平台账号？ <Link to={{ pathname: paths.register, state: { from, oauthBinding: true } }}>立即注册</Link></p>
      <Link to={{ pathname: paths.login, state: { from } }}>使用其他方式登录</Link>
    </>}
  </AuthPageLayout>
}
