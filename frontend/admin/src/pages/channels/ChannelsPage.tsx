import { useCallback, useEffect, useRef, useState } from 'react'
import { Alert, Button, Empty, Message, Modal, Space, Table, Typography } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { IconApps, IconRefresh } from '@arco-design/web-react/icon'
import { useHistory, useLocation } from 'react-router-dom'
import { CHANNEL_CATEGORIES, channelApi, channelPermission, type ChannelCategory, type ChannelPlatform, type ChannelRecord } from '../../api/channelApi'
import { useAdminAuth } from '../../auth/AuthContext'
import EnabledStatus from '../../components/EnabledStatus'
import TableActions, { type TableAction } from '../../components/TableActions'
import useTableQuery from '../../hooks/useTableQuery'
import { TABLE_PAGE_SIZE_OPTIONS } from '../../utils/tablePagination'
import { tableCellText } from '../../utils/tableCellText'
import ChannelEditor from './ChannelEditor'
import MailTestDialog from './MailTestDialog'

/** 七类插件的配置入口；应用安装和业务执行记录分别留在所属模块。 */
export default function ChannelsPage({ category }: { category: ChannelCategory }) {
  const history = useHistory()
  const location = useLocation()
  const { permissions } = useAdminAuth()
  const can = (action: 'view' | 'update') => permissions.includes(channelPermission(category, action))
  const canView = can('view')
  const query = useTableQuery({ statusValues: [0, 1],
    sortFields: ['sort_order', 'created_at', 'updated_at'] })
  const { queryParams, page, pageSize, refreshKey } = query
  // 编辑入口变化不重新加载列表，避免销毁已经填写的抽屉表单。
  const filterKey = JSON.stringify(queryParams)
  const [rows, setRows] = useState<ChannelRecord[]>([])
  const [platforms, setPlatforms] = useState<ChannelPlatform[]>([])
  const [total, setTotal] = useState(0)
  const [defaultLabel, setDefaultLabel] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [working, setWorking] = useState(false)
  const [reload, setReload] = useState(0)
  const [editorPageMode, setEditorPageMode] = useState(false)
  const [testChannel, setTestChannel] = useState<ChannelRecord | null>(null)
  const actionLock = useRef(false)
  const editorParams = new URLSearchParams(location.search)
  const editorValue = editorParams.get('configure')
  const editor = editorValue && /^[a-z][a-z0-9_]{0,63}$/.test(editorValue) ? editorValue : null
  const connectionValue = editorParams.get('connection')
  const editorId = connectionValue && /^[1-9]\d*$/.test(connectionValue) && Number.isSafeInteger(Number(connectionValue)) ? Number(connectionValue) : 0
  const openEditor = (row: ChannelRecord) => {
    const params = new URLSearchParams(location.search)
    params.set('configure', row.plugin_id)
    if (row.id) params.set('connection', String(row.id))
    else params.delete('connection')
    history.push({ ...location, search: params.toString() })
  }
  const closeEditor = () => {
    const params = new URLSearchParams(location.search)
    params.delete('configure')
    params.delete('connection')
    history.replace({ ...location, search: params.toString() })
  }
  const categoryTitle = CHANNEL_CATEGORIES[category]

  useEffect(() => {
    if (!canView) return
    const controller = new AbortController()
    // oxlint-disable-next-line react/set-state-in-effect -- URL条件变化时同步远端请求的加载状态。
    setLoading(true)
    // oxlint-disable-next-line react/set-state-in-effect -- 新请求开始时清除上一次请求错误。
    setError('')
    void Promise.all([
      channelApi.list(category, { ...JSON.parse(filterKey), page, page_size: pageSize }, controller.signal),
      channelApi.platforms(category, controller.signal),
    ]).then(([result, available]) => {
      if (controller.signal.aborted) return
      setRows(result.data)
      setTotal(result.total)
      setDefaultLabel(result.defaultLabel)
      setPlatforms(available)
      if (page > 1 && result.data.length === 0) {
        const params = new URLSearchParams(history.location.search)
        params.set('page', String(Math.max(1, Math.ceil(result.total / pageSize))))
        history.replace({ ...history.location, search: `?${params.toString()}` })
      }
    }).catch((cause: unknown) => {
      if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : '通道列表加载失败')
    }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [category, canView, filterKey, page, pageSize, refreshKey, reload, history])

  const run = useCallback(async (operation: () => Promise<unknown>) => {
    if (actionLock.current) return
    actionLock.current = true
    setWorking(true)
    try { await operation(); Message.success('操作成功'); setReload(value => value + 1) }
    catch (cause) { Message.error(cause instanceof Error ? cause.message : '操作失败，请重试'); throw cause }
    finally { actionLock.current = false; setWorking(false) }
  }, [])

  const columns: TableColumnProps<ChannelRecord>[] = [
    { title: '插件名称', dataIndex: 'platform_name', width: 200, ellipsis: true, render: (_, row) => <Space direction="vertical" size={0}>
      <span>{row.platform_name}</span>
      {row.configured && row.name !== row.platform_name && <Typography.Text type="secondary">{row.name}</Typography.Text>}
    </Space> },
    ...(defaultLabel ? [{ title: defaultLabel, dataIndex: 'is_default', width: 100, render: (value: boolean) => value ? '是' : '否' }] : []),
    { title: '配置状态', width: 150, render: (_, row) => !row.configured ? '未配置' : !row.platform_available ? '插件不可用' : row.configuration_current ? '已配置' : '需要重新保存' },
    { ...query.sortColumn('sort_order'), title: '排序', width: 90 },
    { ...query.filterColumn('status', { '0': '禁用', '1': '启用' }), title: '状态', width: 100, render: (_, row) => <EnabledStatus status={row.status} /> },
    { ...query.sortColumn('updated_at'), title: '更新时间', width: 180, render: tableCellText },
    { title: '备注', dataIndex: 'description', width: 180, ellipsis: true, render: tableCellText },
    { title: '操作', width: 180, fixed: 'right', render: (_, row) => {
      const actions: TableAction[] = [{ key: 'configuration', label: can('update') && row.platform_available ? '配置' : '查看配置', onClick: () => { if (!working) openEditor(row) } }]
      if (category === 'mail' && permissions.includes('admin.channels.mail.test') && row.platform_available && row.configuration_current) {
        actions.push({ key: 'test', label: '测试发送', onClick: () => { if (!working) setTestChannel(row) } })
      }
      if (can('update') && !row.configured && row.platform_available) actions.push({ key: 'enable', label: '启用', onClick: () => {
        if (!working) { Message.info('请先完成插件配置，再启用'); openEditor(row) }
      } })
      if (can('update') && row.configured && (row.status === 1 || row.configuration_current)) actions.push({ key: 'status',
        label: row.status === 1 ? '禁用' : '启用', sensitive: true, onClick: () => {
          if (working) return
          Modal.confirm({ maskClosable: true, title: `${row.status === 1 ? '禁用' : '启用'}通道？`,
            content: row.is_default ? `禁用后将取消该类别的${defaultLabel}，不会自动切换到其他通道。` : undefined,
            onOk: () => run(() => channelApi.status(category, row)) })
        } })
      return <TableActions actions={actions} />
    } },
  ]

  if (!canView) return <section className="page-card"><Alert type="warning" content="你没有查看此类通道的权限。" /></section>
  const editorAllowed = editor !== null && (editorId > 0 || platforms.some(platform => platform.id === editor))
  return <section className="page-card">
    <div hidden={editorAllowed && editorPageMode}>
    <Typography.Title heading={6} style={{ margin: '0 0 20px' }}>{categoryTitle}</Typography.Title>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="page-toolbar" style={{ justifyContent: 'flex-end' }}><Space>
      <Button icon={<IconRefresh />} loading={loading} disabled={working} onClick={() => setReload(value => value + 1)}>刷新</Button>
    </Space></div>
    <div className="table-scroll"><Table<ChannelRecord> rowKey={row => `${row.plugin_id}:${row.id}`} columns={columns} data={rows} loading={loading || working}
      onChange={query.onTableChange} scroll={{ x: 1160 }}
      noDataElement={<div><Empty description={!error && !platforms.length ? '暂无通道插件，请安装对应应用。' : '暂无符合条件的插件'} />
        {!error && !platforms.length && permissions.includes('admin.app-center.view') && <Button icon={<IconApps />} onClick={() => history.push('/application-center')}>前往应用中心</Button>}
      </div>}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: query.setPage, onPageSizeChange: query.setPageSize }} /></div>
    </div>
    {testChannel && <MailTestDialog key={`${testChannel.id}:${testChannel.revision}`} channel={testChannel} onClose={() => setTestChannel(null)} />}
    {editorAllowed && !loading && !error && <ChannelEditor key={`${category}:${editor}:${editorId}`} category={category} id={editorId} pluginId={editor!}
      platforms={platforms} defaultLabel={defaultLabel} canSave={can('update')} onClose={closeEditor} onPageMode={setEditorPageMode}
      onSaved={() => { closeEditor(); setReload(value => value + 1) }} />}
  </section>
}
