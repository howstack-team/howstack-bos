import { LockOutlined, MobileOutlined } from '@ant-design/icons'
import { LoginForm, ProConfigProvider, ProFormText } from '@ant-design/pro-components'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, Button, Card, Flex, Spin, Statistic, Typography, message } from 'antd'
import { getMember, getWalletBalance, loginMember, logoutMember } from './memberApi.ts'
import './App.css'

function MemberLogin() {
  const queryClient = useQueryClient()

  return <main className="ds-login-page member-login-page">
    <div className="ds-page-card ds-login-panel member-login-panel">
      <ProConfigProvider hashed={false}>
        <LoginForm<{ mobile: string; password: string }>
          title="皓量云擎"
          subTitle="会员控制台"
          containerStyle={{ height: 'auto', overflow: 'hidden', borderRadius: 2 }}
          contentStyle={{ width: 'min(100%, 328px)', minWidth: 0 }}
          onFinish={async ({ mobile, password }) => {
            try {
              const user = await loginMember(mobile, password)
              queryClient.setQueryData(['member', 'me'], user)
              void queryClient.invalidateQueries({ queryKey: ['member', 'wallet'] })
              return true
            } catch (error) {
              message.error(error instanceof Error ? error.message : '登录失败')
              return false
            }
          }}>
          <ProFormText name="mobile" placeholder="请输入手机号" fieldProps={{ size: 'large', prefix: <MobileOutlined /> }}
            rules={[{ required: true, message: '请输入手机号' }, { pattern: /^\+?[0-9]{6,20}$/, message: '手机号格式不正确' }]} />
          <ProFormText.Password name="password" placeholder="请输入密码" fieldProps={{ size: 'large', prefix: <LockOutlined /> }}
            rules={[{ required: true, message: '请输入密码' }]} />
        </LoginForm>
      </ProConfigProvider>
    </div>
  </main>
}

function MemberHome({ nickname }: { nickname: string }) {
  const queryClient = useQueryClient()
  const wallet = useQuery({ queryKey: ['member', 'wallet'], queryFn: getWalletBalance })

  return <main className="member-home">
    <header className="member-home-header">
      <Typography.Title level={3}>会员控制台</Typography.Title>
      <Flex align="center" gap={16}>
        <Typography.Text>{nickname}</Typography.Text>
        <Button onClick={() => { void logoutMember().then(() => {
          queryClient.setQueryData(['member', 'me'], null)
          queryClient.removeQueries({ queryKey: ['member', 'wallet'] })
        }).catch((error: unknown) => message.error(error instanceof Error ? error.message : '退出失败')) }}>退出登录</Button>
      </Flex>
    </header>
    <Card title="资金账户" className="member-wallet-card">
      {wallet.isLoading && <Spin />}
      {wallet.isError && <Alert type="error" message={wallet.error instanceof Error ? wallet.error.message : '余额加载失败'} />}
      {wallet.data && <div className="member-wallet-values">
        <Statistic title="可用余额" value={wallet.data.available_minor / 100} precision={2} prefix="¥" />
        <Statistic title="冻结余额" value={wallet.data.frozen_minor / 100} precision={2} prefix="¥" />
      </div>}
    </Card>
  </main>
}

/** 会员控制台当前提供手机号密码登录和本人资金余额。 */
export default function App() {
  const member = useQuery({ queryKey: ['member', 'me'], queryFn: getMember, retry: false })

  if (member.isLoading) return <main className="member-loading"><Spin /></main>
  if (member.isError) return <main className="member-loading"><Alert type="error" message="会员服务暂不可用" /></main>
  if (!member.data) return <MemberLogin />
  return <MemberHome nickname={member.data.nickname} />
}
