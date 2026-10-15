import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, Badge, Button, Collapse, Empty, Message, Modal, Pagination, Space, Spin, Typography } from '@arco-design/web-react'
import { IconNotification } from '@arco-design/web-react/icon'
import { messageApi } from '../api/messageApi'
import { useMemberAuth } from '../auth/AuthContext'

/** 只读取当前会员的站内消息；已读与分页由服务端保存。 */
export default function MessageInbox({ narrow }: { narrow: boolean }) {
  const [visible, setVisible] = useState(false), [page, setPage] = useState(1)
  const { member } = useMemberAuth()
  const client = useQueryClient()
  const key = ['console', 'messages', member?.id] as const
  const summary = useQuery({ queryKey: [...key, 'summary'], queryFn: ({ signal }) => messageApi.summary(signal), enabled: Boolean(member), refetchInterval: 30000 })
  const inbox = useQuery({ queryKey: [...key, 'inbox', page], queryFn: ({ signal }) => messageApi.list(page, signal), enabled: visible && Boolean(member), staleTime: 0 })
  const unread = summary.data ?? 0, rows = inbox.data?.data ?? [], total = inbox.data?.total ?? 0
  const error = inbox.error ?? summary.error
  const read = useMutation({ mutationFn: async (ids?: number[]) => {
    if (ids) await Promise.all(ids.map(id => messageApi.read(id)))
    else await messageApi.readAll()
  }, onSuccess: () => { void client.invalidateQueries({ queryKey: key }) },
    onError: cause => Message.error(cause instanceof Error ? cause.message : '标记已读失败') })
  return <><Badge count={unread} maxCount={99} aria-label={`${unread}条未读消息`}><Button type="secondary" shape="circle" className="navbar-icon-button"
    aria-label="通知" aria-expanded={visible} icon={<IconNotification className="navbar-action-icon" />} onClick={() => { setVisible(true); void client.invalidateQueries({ queryKey: key }) }} /></Badge>
    <Modal title="消息中心" visible={visible} onCancel={() => setVisible(false)} footer={null} style={{ width: 620, maxWidth: 'calc(100vw - 24px)' }}>
      {error && <Alert type="warning" content={error.message} action={<Button onClick={() => { void client.invalidateQueries({ queryKey: key }) }}>重新加载</Button>} style={{ marginBottom: 16 }} />}
      <Spin loading={inbox.isFetching} style={{ width: '100%' }}><div style={{ maxHeight: '55vh', overflowY: 'auto' }}>
        {rows.length ? <Collapse onChange={(_, keys) => { const ids = keys.map(Number).filter(id => rows.some(row => row.id === id && row.unread)); if (ids.length && !read.isPending) read.mutate(ids) }}>
          {rows.map(row => <Collapse.Item name={String(row.id)} key={row.id} header={<Space><Badge dot={row.unread} /><span>{row.title}</span></Space>}>
            <Typography.Paragraph style={{ whiteSpace: 'pre-wrap' }}>{row.body}</Typography.Paragraph><Typography.Text type="secondary">{row.created_at}</Typography.Text>
          </Collapse.Item>)}
        </Collapse> : <Empty description="暂无通知" />}
      </div></Spin>
      <div style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'space-between', alignItems: 'center', gap: 12, marginTop: 16 }}>
        <Button disabled={!unread} loading={read.isPending} onClick={() => read.mutate(undefined)}>全部已读</Button><Pagination total={total} current={page} pageSize={10} simple={narrow} size="small" onChange={setPage} />
      </div>
    </Modal>
  </>
}
