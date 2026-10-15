import { useState } from 'react'
import { Avatar, Card, Descriptions, Space, Switch, Typography } from '@arco-design/web-react'
import { useMemberAuth } from '../auth/AuthContext'
import PageHeading from '../components/PageHeading'
import { maskEmail, maskMobile } from '../utils/memberDisplay'

/** 展示本人已公开的资料；手机号显隐状态仅保留在当前页面。 */
export default function ProfilePage() {
  const { member } = useMemberAuth()
  const [showMobile, setShowMobile] = useState(false)
  return <div className="page-stack">
    <PageHeading title="账户设置" group="账户管理" description="查看您的会员身份与登录信息。" />
    <Card className="console-page-card" title="基本资料" bordered={false}>
      <Space className="profile-intro" size="large">
        <Avatar size={56}>{(member?.nickname || '会员').slice(0, 1)}</Avatar>
        <div><Typography.Title heading={5} style={{ margin: '0 0 4px' }}>{member?.nickname || '会员'}</Typography.Title>
          <Typography.Text type="secondary">会员编号：{member?.id}</Typography.Text></div>
      </Space>
      <Descriptions column={1} data={[
        { label: '会员昵称', value: member?.nickname || '-' },
        { label: '会员编号', value: member?.id ?? '-' },
        { label: '登录手机号', value: showMobile ? member?.mobile ?? '-' : maskMobile(member?.mobile ?? '') },
        { label: '登录邮箱', value: showMobile ? member?.email ?? '-' : maskEmail(member?.email ?? '') },
      ]} />
      <div className="profile-privacy"><span id="mobile-visibility-label">显示完整联系方式</span>
        <Switch type="round" checked={showMobile} onChange={setShowMobile} aria-labelledby="mobile-visibility-label" /></div>
    </Card>
    <Card className="console-page-card" title="账号说明" bordered={false}>
      <Typography.Paragraph style={{ marginBottom: 0 }}>当前支持查看会员资料。需要修改联系方式、昵称或找回密码时，请联系服务方。</Typography.Paragraph>
    </Card>
  </div>
}
