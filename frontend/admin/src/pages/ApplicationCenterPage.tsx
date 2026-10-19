import { communityRelease } from '../../../packages/community-release'
import { applyClientTableQuery } from '../utils/clientTableQuery'
import { StatusBadge } from '../components/TableEnum'
import { APPLICATION_STATUSES, APPLICATION_TYPES } from '../utils/businessEnums'
import { enumLabels, enumText } from '../utils/tableEnum'
import useTableQuery from '../hooks/useTableQuery'
import TableQueryForm from '../components/TableQueryForm'
import { useCallback, useEffect, useState } from 'react'
import { Form, Alert, Button, Link, Message, Modal, Select, Space, Table, Tag, Typography } from '@arco-design/web-react'
import { IconLaunch, IconRefresh } from '@arco-design/web-react/icon'
import type { TableColumnProps } from '@arco-design/web-react'
import { useAdminAuth } from '../auth/AuthContext'
import { officialPlatformApi as api, platformError, type InstallationJob, type OwnedApplication } from '../api/officialPlatformApi'
import InstallationJobsTable from '../components/InstallationJobsTable'
import ReleaseChangelog from '../components/ReleaseChangelog'
import { TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination'
import './SystemMaintenancePage.css'

const reasons: Record<string, string> = {
  binding_unavailable: '实例或商业授权不可用', entitlement_unavailable: '使用权益已失效',
  unlisted: '应用已下架', incompatible_or_package_unavailable: '暂无兼容版本或可用安装包',
}
const localStates = enumLabels(APPLICATION_STATUSES)

/** 只同步当前实例已获取的应用；浏览与获取由官方应用商店负责。 */
export default function ApplicationCenterPage() {
  const searchFields = [
    { key: 'name', label: '应用名称', maxLength: 128 },
    { key: 'code', label: '应用标识', maxLength: 128 },
  ]
  const { queryParams, sortColumn, filterColumn, onTableChange, fields, draftFields, setField, discard, page, pageSize, setPage, setPageSize, search, reset, kind: appliedKind, draftKind, setDraftKind } = useTableQuery({ sortFields: ['use_expires_at'], enumFields: { local_state: Object.keys(localStates) }, textFields: searchFields.map(field => field.key),  kindValues: ['plugin', 'theme'] })
  const kind = appliedKind ?? ''
  const { permissions } = useAdminAuth()
  const [owned, setOwned] = useState<OwnedApplication[]>([])
  const [jobs, setJobs] = useState<InstallationJob[]>([])
  const [refresh, setRefresh] = useState(0)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [storeUrl, setStoreUrl] = useState('')
  const [storeError, setStoreError] = useState('')
  const [busy, setBusy] = useState(false)
  const changed = useCallback(() => setRefresh((value) => value + 1), [])

  useEffect(() => {
    if (!communityRelease.commercialDelivery) return
    const controller = new AbortController()
    void api.storeLink(controller.signal).then((data) => {
      if (!controller.signal.aborted) setStoreUrl(data.store_url)
    }).catch((cause) => {
      if (!controller.signal.aborted) setStoreError(platformError(cause))
    })
    return () => controller.abort()
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    const work = Promise.resolve().then(async () => {
      if (controller.signal.aborted) return
      setLoading(true)
      setError('')
      const data = await api.owned(controller.signal)
      if (!controller.signal.aborted) {
        setOwned(data.items)
        setJobs(data.jobs)
      }
    })
    void work.catch((cause) => {
      if (!controller.signal.aborted) setError(platformError(cause))
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false)
    })
    return () => controller.abort()
  }, [refresh])

  const active = jobs.some((job) => ['pending', 'running', 'awaiting_restart'].includes(job.status))
  useEffect(() => {
    if (!active) return undefined
    const timer = setInterval(changed, 5000)
    return () => clearInterval(timer)
  }, [active, changed])

  const install = async (row: OwnedApplication) => {
    if (!row.available_version_id || busy || active) return
    setBusy(true)
    try {
      const preview = await api.prepare(row.available_version_id)
      const action = ['not_installed', 'uninstalled'].includes(row.local_state) ? 'install' : 'update'
      const requestId = crypto.randomUUID()
      Modal.confirm({ maskClosable: true,
        title: `${action === 'install' ? '安装' : '更新'}「${row.name}」`,
        content: <Space direction="vertical" style={{ width: '100%' }}>
          <Typography.Text>版本：{preview.version} · 大小：{(preview.bytes / 1048576).toFixed(2)} MiB</Typography.Text>
          {preview.force_update && <Alert type="warning" content="此版本要求更新，请先阅读升级注意事项。" />}
          <ReleaseChangelog changelog={preview.changelog} />
          <Typography.Text type="secondary">安装后请由系统管理员完成启用；更新前请停止接单并停用。</Typography.Text>
        </Space>,
        onOk: async () => {
          setBusy(true)
          try {
            await api.install(preview, requestId, action)
            Message.success('安装任务已受理')
            changed()
          } catch (cause) {
            Message.error(platformError(cause))
            throw cause
          } finally { setBusy(false) }
        },
      })
    } catch (cause) { Message.error(platformError(cause)) }
    finally { setBusy(false) }
  }

  const uninstall = (row: OwnedApplication) => Modal.confirm({ maskClosable: true,
    title: `卸载「${row.name}」？`,
    content: '卸载后该插件不能继续使用，保留配置、历史数据及服务器上的插件文件，不取消官网的应用使用权益。有业务引用时无法卸载。',
    onOk: async () => {
      setBusy(true)
      try {
        await api.uninstall(row.code, row.local_revision)
        setOwned((items) => items.map((item) => item.application_id === row.application_id
          ? { ...item, local_state: 'uninstalled', local_version: null, local_revision: item.local_revision + 1 }
          : item))
        Message.success('应用已卸载')
        changed()
      } catch (cause) {
        Message.error(platformError(cause))
        throw cause
      } finally { setBusy(false) }
    },
  })

  const filtered = owned.filter((row) => (!kind || row.kind === kind)
    && row.name.toLowerCase().includes((fields.name ?? '').toLowerCase()) && row.code.toLowerCase().includes((fields.code ?? '').toLowerCase()))
  const visibleRows = applyClientTableQuery(filtered, queryParams, ['local_state'])
  const columns: TableColumnProps<OwnedApplication>[] = [
    { title: '应用名称', dataIndex: 'name', width: 200, ellipsis: true },
    { ...filterColumn('kind', enumLabels(APPLICATION_TYPES)), title: '类型', width: 90, render: (_, row) => enumText(APPLICATION_TYPES, row.kind) },
    { ...sortColumn('use_expires_at'), title: '使用有效期', dataIndex: 'use_expires_at', width: 180, render: (value) => value || '长期有效' },
    { title: '本机版本', dataIndex: 'local_version', width: 110, render: (value) => value || '-' },
    { title: '可用版本', width: 150, render: (_, row) => <Space direction="vertical" size="mini">
      <span>{row.available_version || '-'}</span>
      {row.force_update && row.local_version && row.available_version !== row.local_version && <Tag color="orange">强制更新</Tag>}
    </Space> },
    { ...filterColumn('local_state', localStates), title: '本机状态', width: 120, render: (_, row) => <StatusBadge value={row.local_state} mapping={APPLICATION_STATUSES} /> },
    { title: '可用性', width: 260, ellipsis: true, render: (_, row) => row.kind === 'theme' ? '主题安装暂未开放'
      : row.unavailable_reason ? reasons[row.unavailable_reason] ?? '当前不可用' : '可用' },
    { title: '操作', width: 220, fixed: 'right', render: (_, row) => {
      const missing = ['not_installed', 'uninstalled'].includes(row.local_state)
      const manageable = row.kind === 'plugin' && !missing
      const canInstall = communityRelease.commercialDelivery && row.kind === 'plugin' && row.available_version_id && !row.unavailable_reason
        && (missing || (['installed', 'disabled'].includes(row.local_state) && row.available_version !== row.local_version))
        && permissions.includes(`admin.app-center.${missing ? 'install' : 'update'}`)
      return <Space size="mini">
        {canInstall && <Link href="#" disabled={active || busy || loading} onClick={(event) => { event.preventDefault(); void install(row) }}>{missing ? '安装' : '更新'}</Link>}
        {manageable && ['installed', 'disabled', 'draining'].includes(row.local_state) && permissions.includes('admin.app-center.uninstall')
          && <Link href="#" status="error" disabled={active || busy || loading} onClick={(event) => { event.preventDefault(); uninstall(row) }}>卸载</Link>}
      </Space>
    } },
  ]

  return <section className="page-card system-maintenance-page">
    <div className="system-maintenance-heading system-maintenance-header">
      <div><Typography.Title heading={5}>应用中心</Typography.Title><Typography.Text type="secondary">查看已获取应用与安装记录，按权限处理既有应用。</Typography.Text></div>
      {communityRelease.commercialDelivery && storeUrl && <Link className="system-maintenance-account-entry" href={storeUrl} target="_blank" rel="noopener noreferrer" icon={<IconLaunch />}>官方应用商店</Link>}
    </div>
    {storeError && <Alert type="warning" content={storeError} style={{ marginBottom: 16 }} />}
    <TableQueryForm fields={searchFields} values={draftFields} onFieldChange={setField}
      appliedCount={Object.keys(queryParams).filter(key => !['sort_by', 'sort_order'].includes(key)).length} onCancelFilters={discard}
      onSearch={search} onReset={reset} loading={loading}>

      <Form.Item label="类型" className="table-query-field" style={{ marginBottom: 0, marginRight: 0, minWidth: 0, maxWidth: '100%' }}><Select allowClear placeholder="全部类型" value={draftKind} onChange={setDraftKind} style={{ width: 130 }} options={Object.entries(APPLICATION_TYPES).map(([value, item]) => ({ label: item.text, value }))} /></Form.Item>

    </TableQueryForm>
    <div className="page-toolbar"><Button icon={<IconRefresh />} loading={loading} onClick={changed}>刷新应用</Button></div>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <Table rowKey="application_id" loading={loading} columns={columns} data={visibleRows} scroll={{ x: 1250 }}
      pagination={{ current: page, pageSize, total: visibleRows.length, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS, showTotal: true, onChange: setPage, onPageSizeChange: setPageSize }}
      onChange={onTableChange} />
    <div className="system-maintenance-section"><Typography.Title heading={6}>安装记录</Typography.Title><InstallationJobsTable jobs={jobs} /></div>
  </section>
}
