import { useEffect, useState } from 'react'
import { Alert, Button, Table, Tabs } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { IconSettings } from '@arco-design/web-react/icon'
import { useHistory, useLocation } from 'react-router-dom'
import { MESSAGE_CHANNEL_LABELS, messageSceneApi, type MessageSceneRow } from '../../api/messageSceneApi'
import { useAdminAuth } from '../../auth/AuthContext'
import TableActions from '../../components/TableActions'
import EnabledStatus from '../../components/EnabledStatus'
import BatchChannelDialog from './BatchChannelDialog'
import CommunityMessageEditor from './CommunityMessageEditor'
import { COMMUNITY_CHANNELS, communityGroupKind, supportsCommunityChannel } from './communityMessageConfiguration'

/** 社区版使用两个受众页签和渠道状态矩阵，固定目录完整展示，不提供搜索、刷新或策略管理。 */
export default function MessageConfigurationsPage() {
  const history = useHistory(), location = useLocation(), { permissions } = useAdminAuth()
  const canView = permissions.includes('admin.message-configurations.view'), canSave = permissions.includes('admin.message-configurations.update')
  const params = new URLSearchParams(location.search), editorCode = params.get('edit')
  const editor = editorCode && /^[a-z][a-z0-9_]{0,63}$/.test(editorCode) ? editorCode : null
  const audience = params.get('tab') === 'admin' ? 'admin' : 'customer'
  const [rows, setRows] = useState<MessageSceneRow[]>([]), [loading, setLoading] = useState(true), [error, setError] = useState(''), [reload, setReload] = useState(0)
  const [batchOpen, setBatchOpen] = useState(false)
  const visibleRows = rows.filter(row => row.metadata[audience])
  useEffect(() => {
    if (!canView) return undefined
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      setLoading(true); setError('')
      void messageSceneApi.list({ page: 1, page_size: 100 }, controller.signal).then(result => {
        if (!controller.signal.aborted) setRows(result.data)
      }).catch(cause => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : '消息配置读取失败') })
        .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    }, 0)
    return () => { controller.abort(); window.clearTimeout(timer) }
  }, [canView, reload])
  const update = (changes: Record<string, string | null>, push = false) => {
    const next = new URLSearchParams(location.search)
    for (const [key, value] of Object.entries(changes)) { if (value === null) next.delete(key); else next.set(key, value) }
    history[push ? 'push' : 'replace']({ ...location, search: next.toString() })
  }
  const close = () => update({ edit: null, group: null, channel: null })
  const columns: TableColumnProps<MessageSceneRow>[] = [
    { title: '消息类型', width: 180, render: (_, row) => row.metadata[audience]?.name },
    { title: '消息描述', width: 320, ellipsis: true, render: (_, row) => row.metadata[audience]?.description },
    ...COMMUNITY_CHANNELS[audience].map(channel => ({
      title: MESSAGE_CHANNEL_LABELS[channel], width: channel === 'wecom' ? 115 : 100,
      render: (_: unknown, row: MessageSceneRow) => {
        if (!supportsCommunityChannel(row, audience, channel)) return null
        const groups = row.groups.filter(group => group.kind === communityGroupKind(audience, channel))
        return <EnabledStatus status={groups.some(group => group.enabled && group.channels.includes(channel)) ? 1 : 0} />
      },
    })),
    { title: '操作', width: 85, fixed: 'right', render: (_, row) => <TableActions actions={[{ key: 'edit', label: canSave ? '编辑' : '查看', onClick: () => update({ edit: row.code, group: null, channel: null }, true) }]} /> },
  ]
  if (!canView) return <section className="page-card"><Alert type="warning" content="你没有查看消息配置的权限。" /></section>
  return <section className="page-card">
    <Tabs style={{ marginBottom: 16 }} activeTab={audience} extra={canSave ? <Button icon={<IconSettings />} disabled={loading || Boolean(error) || !visibleRows.length} onClick={() => setBatchOpen(true)}>批量配置</Button> : undefined} onChange={tab => { setBatchOpen(false); update({ tab, edit: null, group: null, channel: null, page: null, page_size: null }) }}>
      <Tabs.TabPane key="customer" title="发送给客户" />
      <Tabs.TabPane key="admin" title="发送给管理员" />
    </Tabs>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="table-scroll"><Table<MessageSceneRow> rowKey="code" columns={columns} data={visibleRows} loading={loading} scroll={{ x: 1045 }} pagination={false} /></div>
    {editor && <CommunityMessageEditor key={`${audience}:${editor}`} code={editor} audience={audience} activeChannel={params.get('channel') ?? undefined} canSave={canSave}
      onChannelChange={channel => update({ channel })} onClose={close} onSaved={() => { close(); setReload(value => value + 1) }} />}
    {batchOpen && visibleRows.length > 0 && <BatchChannelDialog audience={audience} rows={visibleRows} onClose={() => setBatchOpen(false)} onSaved={() => { setBatchOpen(false); setReload(value => value + 1) }} />}
  </section>
}
