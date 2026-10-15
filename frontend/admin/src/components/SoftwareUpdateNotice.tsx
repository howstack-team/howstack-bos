import { Alert, Link, Space, Typography } from '@arco-design/web-react'
import { useHistory } from 'react-router-dom'
import type { UpdateNotice } from '../api/officialPlatformApi'

/** 登录后读取本机的版本摘要；仅暂存提醒偏好，浏览器不访问官网或取得安装凭证。 */
export default function SoftwareUpdateNotice({ notice, hidden, onRemindLater }: {
  notice?: UpdateNotice
  hidden: boolean
  onRemindLater: () => void
}) {
  const history = useHistory()
  if (!notice?.available || !notice.latest_version || (hidden && !notice.force_update)) return null

  return <Alert type={notice.force_update ? 'warning' : 'info'} style={{ marginBottom: 16 }} content={<Space direction="vertical" size="mini">
    <Space wrap size="small">
      <Typography.Text>发现新版本 v{notice.latest_version}</Typography.Text>
      {notice.force_update && <Typography.Text type="warning">此版本要求更新</Typography.Text>}
      <Link href="#" onClick={(event) => { event.preventDefault(); history.push('/maintenance') }}>查看更新</Link>
      {!notice.force_update && <Link href="#" onClick={(event) => {
        event.preventDefault()
        onRemindLater()
      }}>稍后提醒</Link>}
    </Space>
    <Typography.Text type="secondary">上次成功检查：{notice.checked_at || '-'}</Typography.Text>
    {notice.last_error && <Typography.Text type="secondary">最近检查失败：{notice.last_attempt_at || '-'}，当前展示上次成功结果。</Typography.Text>}
  </Space>} />
}
