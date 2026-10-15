import { useCallback, useEffect, useRef, useState } from 'react'
import { Alert, Button, Empty, Form, Link, Message, Modal, Space, Spin, Typography } from '@arco-design/web-react'
import { IconRefresh } from '@arco-design/web-react/icon'
import { Prompt, useHistory, useLocation } from 'react-router-dom'
import { useAdminAuth } from '../auth/AuthContext'
import { ApiRequestError } from '../api/httpClient'
import { websiteApi, websiteFieldErrors, type WebsiteSection, type WebsiteSectionSettings, type WebsiteStorage } from '../api/websiteApi'
import { validateWebsiteValue, websiteLinkErrors, type WebsiteObject, type WebsiteValue } from '../utils/websiteSchema'
import WebsiteImageInput from '../components/website/WebsiteImageInput'
import WebsiteSchemaFields from '../components/website/WebsiteSchemaFields'
import WebsiteLinksEditor from '../components/website/WebsiteLinksEditor'
import SectionTitle from '../components/SectionTitle'
import './WebsitePage.css'

/** 官网公共资料和导航拥有独立路由、页面权限与提交边界。 */
export default function WebsiteSettingsPage() {
  const { pathname } = useLocation(), { user, permissions } = useAdminAuth()
  const section: WebsiteSection = pathname.endsWith('/navigation') ? 'navigation' : 'base'
  if (!permissions.includes(`admin.website.${section}.view`)) return <Empty description="暂无页面查看权限" />
  return <WebsiteSectionEditor key={`${section}:${user?.id}:${permissions.join(',')}`} section={section} permissions={permissions} />
}

function WebsiteSectionEditor({ section, permissions }: { section: WebsiteSection; permissions: string[] }) {
  const history = useHistory()
  const title = section === 'base' ? '基础设置' : '导航管理'
  const editable = permissions.includes(`admin.website.${section}.update`)
  const [bundle, setBundle] = useState<WebsiteSectionSettings | null>(null), [value, setValue] = useState<WebsiteObject>({})
  const [loading, setLoading] = useState(true), [loadError, setLoadError] = useState(''), [attempt, setAttempt] = useState(0)
  const [errors, setErrors] = useState<Record<string, string>>({}), [saveError, setSaveError] = useState(''), [conflict, setConflict] = useState(false)
  const [saving, setSaving] = useState(false), savingLock = useRef(false)
  const [pending, setPending] = useState(0), pendingLock = useRef(0)
  const [storage, setStorage] = useState<WebsiteStorage | null>(null), [storageError, setStorageError] = useState(''), [storageAttempt, setStorageAttempt] = useState(0)
  const lifecycle = useRef<AbortController | null>(null)
  const dirty = Boolean(bundle && JSON.stringify(value) !== JSON.stringify(bundle.settings))
  const disabled = !editable || loading || saving || pending > 0
  const onPending = useCallback((active: boolean) => { pendingLock.current = Math.max(0, pendingLock.current + (active ? 1 : -1)); setPending(pendingLock.current) }, [])
  useEffect(() => {
    const controller = new AbortController(); lifecycle.current = controller
    void websiteApi.section(section, controller.signal).then(next => {
      if (!controller.signal.aborted) { setBundle(next); setValue(next.settings); setErrors({}); setSaveError(''); setConflict(false) }
    }).catch(error => { if (!controller.signal.aborted) setLoadError(error instanceof Error ? error.message : '配置加载失败') })
      .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [section, attempt])
  useEffect(() => {
    if (!editable || section !== 'base') return
    const controller = new AbortController()
    void websiteApi.storage(controller.signal).then(next => { if (!controller.signal.aborted) { setStorage(next); setStorageError('') } })
      .catch(error => { if (!controller.signal.aborted) setStorageError(error instanceof Error ? error.message : '存储检查失败') })
    return () => controller.abort()
  }, [editable, section, storageAttempt])
  useEffect(() => {
    if (!dirty) return
    const unload = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', unload)
    return () => window.removeEventListener('beforeunload', unload)
  }, [dirty])
  function reload() {
    const run = () => { setLoading(true); setLoadError(''); setAttempt(previous => previous + 1) }
    if (dirty) Modal.confirm({ title: '重新加载配置？', content: '尚未保存的修改将被丢弃。', onOk: run }); else run()
  }
  function change(next: WebsiteObject) { setValue(next); setErrors({}) }
  async function save() {
    if (!bundle || !editable || savingLock.current || pendingLock.current || conflict) return
    const fields = { ...validateWebsiteValue(value, bundle.schema), ...(section === 'navigation' ? websiteLinkErrors(value, bundle.active_theme_id, bundle.pages) : {}) }
    if (Object.keys(fields).length) { setErrors(fields); document.getElementById(`website-field-${Object.keys(fields)[0]}`)?.scrollIntoView({ block: 'center' }); return }
    savingLock.current = true; setSaving(true)
    const signal = lifecycle.current?.signal
    try {
      const next = await websiteApi.save(`/${section}`, bundle, value, signal)
      if (!signal?.aborted) { setBundle({ ...bundle, ...next }); setValue(next.settings); setSaveError(''); setErrors({}); Message.success('配置已保存') }
    } catch (error) {
      if (!signal?.aborted) { setErrors(websiteFieldErrors(error)); setSaveError(error instanceof Error ? error.message : '保存失败'); setConflict(error instanceof ApiRequestError && error.status === 409) }
    } finally { savingLock.current = false; if (!signal?.aborted) setSaving(false) }
  }
  function image(id: string, onChange: (id: string) => void, label: string, compact = false) {
    return <WebsiteImageInput value={id} onChange={onChange} label={label} compact={compact} disabled={disabled} available={storage?.available === true} onPending={onPending} />
  }
  function field(key: string) {
    if (!bundle?.schema.properties?.[key]) return null
    return <WebsiteSchemaFields key={key} schema={bundle.schema.properties[key]} value={value[key]} path={`settings.${key}`} disabled={disabled}
      errors={errors} image={image} onChange={next => change({ ...value, [key]: next })} />
  }
  return <section className="page-card website-page">
    <Prompt when={dirty} message="配置尚未保存，确定离开页面吗？" />
    <Typography.Title heading={4} style={{ margin: 0 }}>{title}</Typography.Title>
    {loadError ? <Alert type="error" content={loadError} action={<Button icon={<IconRefresh />} onClick={reload}>重新加载</Button>} /> : loading ? <Spin loading style={{ minHeight: 240 }} /> : bundle && <>
      {!editable && <Alert type="info" content="当前账号只有查看权限。" />}
      {section === 'base' && editable && (storageError || storage?.available === false) && <Alert type="warning" content={storageError || storage?.message} action={<Space>
        {storageError && <Button icon={<IconRefresh />} onClick={() => setStorageAttempt(previous => previous + 1)}>重试</Button>}
        {permissions.includes('admin.channels.storage.view') && <Link onClick={() => history.push('/channels/storage')}>配置文件存储</Link>}
      </Space>} />}
      {saveError && <Alert type="error" content={conflict ? '配置已被更新，您的输入已保留，请重新加载后再保存。' : saveError}
        action={conflict ? <Button icon={<IconRefresh />} onClick={reload}>重新加载</Button> : undefined} />}
      <Form layout="vertical" onSubmit={() => void save()} aria-label={title}>
        <div className={`website-form-content${section === 'navigation' ? ' website-navigation-content' : ''}`}>
          {section === 'base' ? <>
            <SectionTitle>品牌图片</SectionTitle><div className="website-image-fields">{field('logo_image_id')}{field('favicon_image_id')}</div>
            {field('company')}
          </> : (['navigation', 'footer_links'] as const).map(group => <WebsiteLinksEditor key={group} group={group}
            value={Array.isArray(value[group]) ? value[group] as WebsiteValue[] : []} themeId={bundle.active_theme_id} pages={bundle.pages} disabled={disabled}
            errors={errors} onChange={next => change({ ...value, [group]: next })} />)}
        </div>
        {editable && <div className="website-save"><Button type="primary" htmlType="submit" loading={saving} disabled={disabled || conflict}>保存{title}</Button></div>}
      </Form>
    </>}
  </section>
}
