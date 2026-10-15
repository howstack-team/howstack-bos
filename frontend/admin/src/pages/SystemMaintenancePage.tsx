import SectionTitle from '../components/SectionTitle'
import { useCallback, useEffect, useState } from 'react'
import { Alert, Button, Descriptions, Message, Modal, Space, Spin, Table, Tag, Typography } from '@arco-design/web-react'
import { IconCheckCircleFill, IconExclamationCircleFill, IconMinusCircleFill, IconRefresh, IconUser } from '@arco-design/web-react/icon'
import { useAdminAuth } from '../auth/AuthContext'
import { officialPlatformApi as api, platformError, type CommercialLicense, type LicenseEntitlement, type OfficialAccount, type UpdateStatus } from '../api/officialPlatformApi'
import OfficialAccountModal from '../components/OfficialAccountModal'
import { DESCRIPTION_COLUMNS } from '../utils/descriptionColumns'
import InstallationJobsTable from '../components/InstallationJobsTable'
import ReleaseChangelog from '../components/ReleaseChangelog'
import './SystemMaintenancePage.css'

function LicensePanel({ refresh }: { refresh: number }) {
  const { permissions } = useAdminAuth()
  const [license, setLicense] = useState<CommercialLicense>()
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    const controller = new AbortController()
    const load = () => {
      void api.license(controller.signal).then((data) => { if (!controller.signal.aborted) { setLicense(data); setError('') } }).catch((cause) => { if (!controller.signal.aborted) setError(platformError(cause)) })
    }
    load()
    const timer = setInterval(load, 30000)
    window.addEventListener('focus', load)
    return () => { controller.abort(); clearInterval(timer); window.removeEventListener('focus', load) }
  }, [refresh])
  return <div className="system-maintenance-panel">
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div>
      <Descriptions title={<SectionTitle>授权信息</SectionTitle>} column={DESCRIPTION_COLUMNS} border data={[
        { label: '版本类型', value: license ? license.edition_name : error ? '读取失败' : '-' },
        { label: '授权状态', value: license ? <Tag color={license.status === 'active' ? 'green' : license.status === 'community' ? 'gray' : 'orange'}>{license.status_text}</Tag> : '-' },
        { label: '授权编号', value: license?.license_no || '-' },
        { label: '权益修订', value: license?.entitlement_revision || '不适用' },
        { label: '上次验证', value: license?.verified_at || '-' },
        { label: '联网验证有效至', value: license?.offline_until || '-' },
      ]} />
      {license && <div className="system-maintenance-section">
        <SectionTitle>功能权益</SectionTitle>
        {license.entitlements.length ? <Table<LicenseEntitlement> rowKey="capability" data={license.entitlements} pagination={false} scroll={{ x: 720 }} columns={[
          { title: '功能', dataIndex: 'name' },
          { title: '接入状态', render: (_, item) => item.implemented ? item.installed ? '已接入' : '模块不可用' : '尚未接入' },
          { title: '使用期限', render: (_, item) => item.use_until ?? '永久使用' },
          { title: '更新期限', render: (_, item) => item.updates_until ?? '永久更新' },
          { title: '权益状态', render: (_, item) => <Tag color={item.status === 'active' ? 'green' : 'orange'}>{({ active: '有效', expired: '已到期', revoked: '已撤销', offline_expired: '待联网验证', unverified: '未通过验证', clock_error: '时间异常' } as Record<string, string>)[item.status] ?? '未授予'}</Tag> },
        ]} /> : <Typography.Text type="secondary">暂无商业功能权益，社区版功能可正常使用。</Typography.Text>}
      </div>}
      {permissions.includes('admin.license.manage') && <Space className="system-maintenance-actions"><Button icon={<IconRefresh />} loading={busy} disabled={!license?.account.connected} onClick={async () => {
        setBusy(true); try { setLicense(await api.refreshLicense()); setError(''); window.dispatchEvent(new Event('admin:entitlements-changed')); Message.success('授权状态已核实') } catch (cause) { setError(platformError(cause)) } finally { setBusy(false) }
      }}>验证授权</Button></Space>}
    </div>
  </div>
}

function UpdatePanel({ refresh }: { refresh: number }) {
  const { permissions } = useAdminAuth()
  const [data, setData] = useState<UpdateStatus>()
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const load = useCallback(async (signal?: AbortSignal) => {
    try { const value = await api.updates(signal); if (!signal?.aborted) { setData(value); setError('') } }
    catch (cause) { if (!signal?.aborted) setError(platformError(cause)) }
  }, [])
  useEffect(() => { const controller = new AbortController(); void Promise.resolve().then(() => { if (!controller.signal.aborted) void load(controller.signal) }); return () => controller.abort() }, [load, refresh])
  const active = data?.jobs.some((job) => ['pending', 'running', 'awaiting_restart'].includes(job.status)) ?? false
  useEffect(() => {
    if (!active) return undefined
    const controller = new AbortController()
    const interval = setInterval(() => { void load(controller.signal) }, 5000)
    return () => { clearInterval(interval); controller.abort() }
  }, [active, load])
  const start = () => {
    const update = data?.update
    if (!update?.sha256 || !update.target_version) return
    const id = crypto.randomUUID()
    Modal.confirm({ maskClosable: true, title: `更新到 ${update.target_version}？`, content: <Space direction="vertical" size="small" style={{ width: '100%' }}>
      {update.force_update && <Alert type="warning" content="此版本要求更新，请确认已阅读升级注意事项并安排维护。" />}
      <Typography.Text>系统将下载并校验更新包，进入维护模式，备份数据库和文件，再执行更新与重启。请先排空外部生产等长时间任务。</Typography.Text>
      <Typography.Text type="secondary">请先在版本信息下方阅读更新日志，确认后开始升级。</Typography.Text>
    </Space>,
      onOk: async () => { try { setData(await api.startUpdate(id, update.sha256!, update.target_version!)); setError(''); window.dispatchEvent(new Event('admin:updates-changed')); Message.success('更新任务已受理') } catch (cause) { await load(); setError(platformError(cause)); window.dispatchEvent(new Event('admin:updates-changed')); throw cause } },
    })
  }
  return <div className="system-maintenance-panel">
    {(error || data?.last_error) && <Alert type="warning" content={`${error || data?.last_error}。${data?.update ? '保留上次成功检查结果，请核对检查时间。' : '尚无成功检查结果。'}`} style={{ marginBottom: 16 }} />}
    <Descriptions title={<SectionTitle>版本信息</SectionTitle>} column={DESCRIPTION_COLUMNS} border data={[
      { label: '当前版本', value: data?.current_version ?? <Spin size={14} /> },
      { label: '最新版本', value: data?.update ? data.update.available ? data.update.target_version : data.current_version : '尚未检查' },
      ...(data?.update?.available ? [{ label: '更新要求', value: data.update.force_update
        ? <Space size="mini" className="system-update-status__warning"><IconExclamationCircleFill aria-hidden="true" /><span>强制更新</span></Space>
        : '可选更新' }] : []),
      { label: '检查状态', value: data?.last_error || error ? '检查失败，保留上次结果' : data?.update ? data.update.available ? '有可用更新' : <Space size="mini" className="system-update-status__success"><IconCheckCircleFill aria-hidden="true" /><span>当前版本已最新</span></Space> : '尚未检查' },
      { label: '上次成功检查', value: data?.update?.checked_at || '-' },
      { label: '最近检查时间', value: data?.last_attempt_at || data?.update?.checked_at || '-' },
    ]} />
    <Space wrap size="small" className="system-maintenance-actions">
      {permissions.includes('admin.updates.check') && <Button icon={<IconRefresh />} loading={busy} disabled={active} onClick={async () => {
        setBusy(true); try { setData(await api.checkUpdate()); setError(''); window.dispatchEvent(new Event('admin:updates-changed')) } catch (cause) { await load(); setError(platformError(cause)); window.dispatchEvent(new Event('admin:updates-changed')) } finally { setBusy(false) }
      }}>检查更新</Button>}
      {permissions.includes('admin.updates.execute') && <Button type="primary" disabled={!data?.update?.available || active} onClick={start}>立即更新</Button>}
      <Button onClick={() => { void load() }}>刷新记录</Button>
    </Space>
    {data?.update?.available && <div className="system-maintenance-section">
      {data.update.force_update && <Alert type="warning" content={`版本 ${data.update.target_version} 要求更新。请先阅读下方更新日志并安排维护，由有权限的管理员确认执行。`} style={{ marginBottom: 16 }} />}
      <ReleaseChangelog changelog={data.update.changelog} title={<SectionTitle>更新日志</SectionTitle>} />
    </div>}
    <div className="system-maintenance-section"><Typography.Title heading={6}><SectionTitle>升级记录</SectionTitle></Typography.Title><InstallationJobsTable jobs={data?.jobs ?? []} /></div>
  </div>
}

export default function SystemMaintenancePage() {
  const { permissions } = useAdminAuth()
  const [refresh, setRefresh] = useState(0)
  const [accountVisible, setAccountVisible] = useState(false)
  const [account, setAccount] = useState<OfficialAccount>()
  const [accountError, setAccountError] = useState('')
  const changed = useCallback(() => setRefresh((value) => value + 1), [])
  const license = permissions.includes('admin.license.view')
  const updates = permissions.includes('admin.updates.view')
  useEffect(() => {
    const controller = new AbortController()
    void api.account(controller.signal).then((value) => {
      if (!controller.signal.aborted) {
        setAccount(value)
        setAccountError('')
      }
    }).catch((cause: unknown) => {
      if (!controller.signal.aborted) setAccountError(platformError(cause))
    })
    return () => controller.abort()
  }, [refresh])

  const bindingStatus = accountError ? '状态获取失败'
    : !account ? '正在读取'
    : account.reconnect_required ? '需要核查'
    : account.connection?.license_valid ? '授权有效' : account.connection?.connection_mode === 'licensed' ? '授权待核查' : '社区版'
  const bindingTone = accountError || account?.reconnect_required ? 'warning'
    : account?.connected ? 'success' : 'default'
  return <section className="page-card system-maintenance-page">
    <div className="system-maintenance-heading system-maintenance-header">
      <div><Typography.Title heading={5}>授权与更新</Typography.Title></div>
      <Space wrap className="system-maintenance-account-entry" size="small">
        <Button icon={<IconUser />} onClick={() => setAccountVisible(true)}>授权码激活</Button>
        <span role="status" aria-label="授权激活状态" title={accountError || undefined}>
          {!account && !accountError ? <Space size="mini"><Spin size={14} /><span>{bindingStatus}</span></Space>
            : <Space size="mini" className={`account-binding-status__${bindingTone}`}>
              {bindingTone === 'success'
                ? <IconCheckCircleFill aria-hidden="true" />
                : bindingTone === 'warning'
                  ? <IconExclamationCircleFill aria-hidden="true" />
                  : <IconMinusCircleFill aria-hidden="true" />}
              <span>{bindingStatus}</span>
            </Space>}
        </span>
      </Space>
    </div>
    <div className="system-maintenance-content">
      {license && <LicensePanel refresh={refresh} />}
      {updates && <UpdatePanel refresh={refresh} />}
    </div>
    <OfficialAccountModal visible={accountVisible} onCancel={() => setAccountVisible(false)} onConnected={changed} />
  </section>
}
