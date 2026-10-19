import { communityRelease } from '../../../packages/community-release'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Alert, Button, Card, Drawer, Empty, Form, Link, Message, Modal, Space, Spin, Switch, Tag, Tooltip, Typography } from '@arco-design/web-react'
import { IconCheckCircle, IconUpload } from '@arco-design/web-react/icon'
import { Prompt } from 'react-router-dom'
import { useAdminAuth } from '../auth/AuthContext'
import { siteThemeApi, themeLabels, type SiteTheme, type ThemeBindings, type ThemeComponent, type ThemeConfiguration } from '../api/siteThemeApi'
import { websiteApi, websiteFieldErrors } from '../api/websiteApi'
import { validateWebsiteValue, type WebsiteObject } from '../utils/websiteSchema'
import WebsiteSchemaFields from '../components/website/WebsiteSchemaFields'
import WebsiteImageInput from '../components/website/WebsiteImageInput'
import WebsiteThemeImage from '../components/website/WebsiteThemeImage'
import SectionTitle from '../components/SectionTitle'
import './WebsitePage.css'
import './ThemeManagementPage.css'

/** 社区版一个主题一条记录；卡片直接启用与保存移动开关，上传同ID包更新。 */
export default function ThemeManagementPage() {
  const { permissions } = useAdminAuth()
  const viewable = permissions.includes('admin.page.website.view')
  const can = (verb: string) => permissions.includes(`admin.page.website.${verb}`)
  const [themes, setThemes] = useState<SiteTheme[]>([]), [bindings, setBindings] = useState<ThemeBindings | null>(null)
  const [loading, setLoading] = useState(true), [error, setError] = useState(''), [busy, setBusy] = useState(false)
  const input = useRef<HTMLInputElement>(null), lock = useRef(false), controller = useRef<AbortController | null>(null)
  const [editing, setEditing] = useState<SiteTheme | null>(null)
  const load = useCallback(async () => {
    controller.current?.abort()
    const current = new AbortController(); controller.current = current
    try {
      const [list, next] = await Promise.all([siteThemeApi.list(current.signal), siteThemeApi.bindings(current.signal)])
      if (!current.signal.aborted) {
        setThemes(list); setBindings(next); setError('')
      }
    } catch (reason) { if (!current.signal.aborted) setError(reason instanceof Error ? reason.message : '主题读取失败') }
    finally { if (!current.signal.aborted) setLoading(false) }
  }, [])
  useEffect(() => {
    let mounted = true
    void Promise.resolve().then(() => { if (mounted && viewable) return load() })
    return () => { mounted = false; controller.current?.abort() }
  }, [load, viewable])
  async function operate(action: () => Promise<unknown>, message: string) {
    if (lock.current) return
    lock.current = true; setBusy(true); setError('')
    try { await action(); Message.success(message); await load() }
    catch (reason) { setError(reason instanceof Error ? reason.message : '操作失败') }
    finally { lock.current = false; setBusy(false) }
  }
  async function install(file?: File) {
    if (!file) return
    if (!/\.zip$/i.test(file.name) || !file.size || file.size > 25 * 1024 * 1024) { setError('请选择25MiB以内的主题ZIP包'); return }
    await operate(() => siteThemeApi.install(file), '主题已安装或更新')
  }
  /** 卡片直接保存绑定；以服务端返回值更新当前状态，失败不提前改变主题或开关。 */
  async function bindTheme(id: number, mobile: boolean) {
    if (!bindings) return
    await operate(async () => {
      const next = await siteThemeApi.bind(bindings.revision, id, mobile)
      setBindings(next)
    }, id !== bindings.release_id ? '主题已启用' : mobile ? '移动端模板已开启' : '移动端模板已关闭')
  }
  function uninstall(theme: SiteTheme) {
    Modal.confirm({ title: '卸载主题', content: `确认卸载「${theme.name} ${theme.version}」？该主题的展示配置将一并删除。`, okButtonProps: { status: 'danger' },
      onOk: () => operate(() => siteThemeApi.uninstall(theme.id), '主题已卸载') })
  }
  if (!can('view')) return <Empty description="暂无主题查看权限" />
  return <div className="website-page">
    {error && <Alert type="error" content={error} action={!busy && <Button onClick={() => void load()}>重新加载</Button>} />}
    <Spin loading={loading} style={{ display: 'block' }}>
      <Card className="website-card">
        <div className="theme-catalog-heading"><SectionTitle>已安装主题</SectionTitle>{communityRelease.themePackages && can('install') && <Button icon={<IconUpload />} disabled={busy} onClick={() => input.current?.click()}>上传主题</Button>}</div>
        <input ref={input} type="file" accept=".zip" hidden aria-label="上传主题安装包" onChange={event => { void install(event.target.files?.[0]); event.target.value = '' }} />
        <div className="theme-catalog">{themes.filter(theme => theme.builtin || bindings?.release_id === theme.id).map(theme => <div key={theme.id} className="theme-package">
          <div className="theme-package-cover"><WebsiteThemeImage name={theme.name} src={theme.cover} /></div>
          <div className="theme-package-content">
            <div className="theme-package-heading">
              <Typography.Text bold className="theme-package-name">{theme.name}</Typography.Text>
              {bindings?.release_id === theme.id && <Tag color="green" icon={<IconCheckCircle />}>使用中</Tag>}
            </div>
            <div className="theme-package-meta">
              <Typography.Text type="secondary">{theme.version}</Typography.Text>
              <div className="theme-package-components">{theme.components.map(type => <Typography.Text type="secondary" key={type}>{themeLabels[type]}</Typography.Text>)}</div>
            </div>
          </div>
          <div className="theme-package-actions">
            {bindings?.release_id === theme.id && <div className="theme-mobile-setting">
              <Tooltip content={bindings.console.mobile_available ? '开启后手机访问使用独立移动端模板；关闭后使用 PC 模板。' : '当前会员中心主题未提供移动端模板，不能开启。'}><span>移动端模板</span></Tooltip>
              <Switch type="round" aria-label="启用独立移动端模板" checked={bindings.mobile_enabled} loading={busy} disabled={!can('activate') || busy || !bindings.console.mobile_available} checkedText="开启" uncheckedText="关闭" onChange={value => void bindTheme(theme.id, value)} />
            </div>}
            <Space>{communityRelease.themePackages && can('activate') && bindings && bindings.release_id !== theme.id && <Link disabled={busy} onClick={() => void bindTheme(theme.id, false)}>启用</Link>}
              {theme.components.includes('website') && <Link disabled={busy} onClick={() => setEditing(theme)}>配置</Link>}
              {communityRelease.themePackages && can('uninstall') && !theme.builtin && theme.active.length === 0 && bindings?.release_id !== theme.id && <Link status="error" disabled={busy} onClick={() => uninstall(theme)}>卸载</Link>}
            </Space>
          </div>
        </div>)}</div>{!loading && !themes.length && <Empty description="暂无主题" />}

      </Card>
    </Spin>
    {editing && <ComponentSettings key={editing.id} theme={editing} component="website" editable={can('update')} onClose={() => setEditing(null)} />}
  </div>
}
/** 当前只开放官网配置；组件参数及 API 契约保留未来会员配置的扩展边界。 */
function ComponentSettings({ theme, component: type, editable, onClose }: { theme: SiteTheme; component: ThemeComponent; editable: boolean; onClose: () => void }) {
  const [config, setConfig] = useState<ThemeConfiguration | null>(null), [value, setValue] = useState<WebsiteObject>({})
  const [loading, setLoading] = useState(true), [busy, setBusy] = useState(false), [error, setError] = useState(''), [fields, setFields] = useState<Record<string, string>>({}), [pending, setPending] = useState(0)
  const [available, setAvailable] = useState(false), lock = useRef(false)
  useEffect(() => {
    const current = new AbortController()
    void siteThemeApi.settings(theme.id, type, current.signal).then(next => { if (!current.signal.aborted) { setConfig(next); setValue(next.settings) } }).catch(reason => { if (!current.signal.aborted) setError(reason instanceof Error ? reason.message : '配置读取失败') }).finally(() => { if (!current.signal.aborted) setLoading(false) })
    void websiteApi.storage(current.signal).then(next => { if (!current.signal.aborted) setAvailable(next.available) }).catch(() => {})
    return () => current.abort()
  }, [theme.id, type])
  const dirty = Boolean(config && JSON.stringify(config.settings) !== JSON.stringify(value))
  function close() { if (dirty) Modal.confirm({ title: '放弃修改', content: '主题配置尚未保存，确认关闭？', maskClosable: true, onOk: onClose }); else onClose() }
  async function save() {
    if (!config || lock.current || pending) return
    const errors = validateWebsiteValue(value, config.schema)
    setFields(errors); if (Object.keys(errors).length) return
    lock.current = true; setBusy(true); setError('')
    try { await siteThemeApi.save(theme.id, type, config.revision, value); const next = await siteThemeApi.settings(theme.id, type); setConfig(next); setValue(next.settings); Message.success('主题配置已保存') }
    catch (reason) { setError(reason instanceof Error ? reason.message : '保存失败'); setFields(websiteFieldErrors(reason)) }
    finally { lock.current = false; setBusy(false) }
  }
  return <Drawer visible width={720} style={{ maxWidth: 'calc(100vw - 24px)' }} title={`配置主题 · ${theme.name} ${theme.version}`} onCancel={close} maskClosable
    footer={<Space><Button onClick={close}>取消</Button>{editable && <Button type="primary" disabled={loading || !dirty || pending > 0} loading={busy} onClick={() => void save()}>保存</Button>}</Space>}>
    <Prompt when={dirty} message="主题配置尚未保存，确认离开？" />
    {error && <Alert type="error" content={error} />}
    <Spin loading={loading} style={{ display: 'block' }}>
      {config && (Object.keys(config.schema.properties ?? {}).length ? <Form layout="vertical"><WebsiteSchemaFields schema={config.schema} value={value} onChange={next => setValue(next as WebsiteObject)} disabled={!editable || busy} errors={fields}
        image={(id, onChange, label, compact) => <WebsiteImageInput value={id} onChange={onChange} label={label} compact={compact} disabled={!editable || busy} available={available} onPending={active => setPending(count => count + (active ? 1 : -1))} />} /></Form> : <Empty description="此模板没有可配置的展示项" />)}
    </Spin>
  </Drawer>
}
