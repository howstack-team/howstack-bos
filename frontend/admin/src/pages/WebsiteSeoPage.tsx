import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Empty, Form, Input, Link, Message, Modal, Radio, Space, Spin, Table, Typography } from '@arco-design/web-react'
import { IconRefresh } from '@arco-design/web-react/icon'
import { useAdminAuth } from '../auth/AuthContext'
import { ApiRequestError } from '../api/httpClient'
import { websiteFieldErrors } from '../api/websiteApi'
import { websiteSeoApi, websiteSeoPermissions, type PageSeoSettings, type SeoPage, type WebsiteSeoCatalog } from '../api/websiteSeoApi'
import './WebsitePage.css'

type Editor = { kind: 'page'; page: SeoPage; value: PageSeoSettings }

/** 当前主题的真实页面SEO；轻量编辑使用窄单列Modal，无内部表单滚动。 */
export default function WebsiteSeoPage() {
  const { permissions, user } = useAdminAuth()
  if (!permissions.includes(websiteSeoPermissions.view)) return <Empty description="暂无页面查看权限" />
  return <WebsiteSeoEditor key={`${user?.id}:${permissions.join(',')}`} editable={permissions.includes(websiteSeoPermissions.update)} />
}

function WebsiteSeoEditor({ editable }: { editable: boolean }) {
  const [catalog, setCatalog] = useState<WebsiteSeoCatalog | null>(null), [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(''), [attempt, setAttempt] = useState(0)
  const [editor, setEditor] = useState<Editor | null>(null), [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({}), [saveError, setSaveError] = useState(''), [conflict, setConflict] = useState(false)
  const lock = useRef(false), generation = useRef(0), lifetime = useRef<AbortController | null>(null)
  useEffect(() => {
    const controller = new AbortController(); lifetime.current = controller
    void websiteSeoApi.read(controller.signal).then(value => { if (!controller.signal.aborted) { setCatalog(value); setLoadError('') } })
      .catch(error => { if (!controller.signal.aborted) setLoadError(error instanceof Error ? error.message : '加载失败') })
      .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [attempt])
  function reload() { setLoading(true); setLoadError(''); setAttempt(value => value + 1) }
  function open(next: Editor) { generation.current++; setEditor(next); setErrors({}); setSaveError(''); setConflict(false) }
  function close() { generation.current++; setEditor(null) }
  function change(field: string, value: string | boolean | null) {
    if (!editor) return
    setEditor({ ...editor, value: { ...editor.value, [field]: value } } as Editor)
    setErrors(previous => ({ ...previous, [`settings.${field}`]: '' }))
  }
  async function save() {
    if (!catalog || !editor || !editable || lock.current || conflict) return
    const captured = generation.current, signal = lifetime.current?.signal
    lock.current = true; setSaving(true); setSaveError(''); setErrors({})
    try {
      const next = await websiteSeoApi.savePage(catalog, editor.page, editor.value, signal)
      if (!signal?.aborted) { setCatalog(next); Message.success('SEO 配置已保存'); if (captured === generation.current) close() }
    } catch (error) {
      if (!signal?.aborted && captured === generation.current) {
        setErrors(websiteFieldErrors(error)); setConflict(error instanceof ApiRequestError && error.status === 409)
        setSaveError(error instanceof Error ? error.message : '保存失败')
      }
    } finally { lock.current = false; if (!signal?.aborted) setSaving(false) }
  }
  function input(field: string, label: string, value: string, length: number, placeholder: string, multiline = false, extra?: string) {
    return <Form.Item label={label} extra={extra} validateStatus={errors[`settings.${field}`] ? 'error' : undefined} help={errors[`settings.${field}`]}>
      {multiline ? <Input.TextArea aria-label={label} value={value} onChange={next => change(field, next)} placeholder={placeholder} maxLength={length} showWordLimit autoSize={{ minRows: 2, maxRows: 3 }} disabled={!editable || saving} />
        : <Input aria-label={label} value={value} onChange={next => change(field, next)} placeholder={placeholder} maxLength={length} allowClear disabled={!editable || saving} />}
    </Form.Item>
  }
  return <section className="page-card website-page">
    <div className="website-theme-summary">
      <Typography.Title heading={4} style={{ margin: 0 }}>页面 SEO</Typography.Title>
      <Space><Button icon={<IconRefresh />} onClick={reload} disabled={loading || saving}>刷新</Button></Space>
    </div>
    {loadError ? <Alert type="error" content={loadError} action={<Button icon={<IconRefresh />} onClick={reload}>重新加载</Button>} />
      : loading ? <Spin loading style={{ minHeight: 240 }} /> : catalog && <>
        <Typography.Text type="secondary">页面自动来自当前官网主题。首页填写副标题，其他页面填写标题；关键词和描述按页面独立维护。</Typography.Text>
        <Table<SeoPage> rowKey="id" data={catalog.pages} pagination={false} scroll={{ x: 960 }} columns={[
          { title: '页面', dataIndex: 'title', width: 140 },
          { title: '路径', dataIndex: 'path', width: 170, ellipsis: true },
          { title: 'SEO 标题', width: 240, ellipsis: true, render: (_, page) => page.effective.title },
          { title: 'SEO 描述', width: 280, ellipsis: true, render: (_, page) => page.effective.description || undefined },
          { title: '索引', width: 90, render: (_, page) => page.effective.indexable ? '允许' : '禁止' },
          { title: '操作', width: 80, fixed: 'right', render: (_, page) => <Link onClick={() => open({ kind: 'page', page, value: { ...page.settings } })}>{editable ? '编辑' : '查看'}</Link> },
        ]} />
      </>}
    <Modal visible={editor !== null} title={editor ? `${editable ? '编辑' : '查看'} SEO - ${editor.page.title}` : '页面 SEO'} maskClosable
      onCancel={close} style={{ width: 560, maxWidth: 'calc(100vw - 32px)' }} footer={<Space><Button onClick={close}>{editable ? '取消' : '关闭'}</Button>
        {editable && <Button type="primary" loading={saving} disabled={conflict} onClick={() => void save()}>保存</Button>}</Space>}>
      {saveError && <Alert style={{ marginBottom: 16 }} type="error" content={conflict ? '配置已更新，输入已保留。请取消后重新加载，再核对并保存。' : saveError} />}
      {editor && <Form layout="vertical" disabled={!editable} onSubmit={() => void save()}>
        <>
          <Typography.Text type="secondary" style={{ display: 'block', marginBottom: 16 }}>{editor.page.path} · 文字留空使用主题声明，无值则不输出</Typography.Text>
          {editor.page.id === 'home'
            ? input('title', '首页副标题', editor.value.title, 120, '请输入首页副标题，留空仅显示网站名称', false, '首页标题显示为「网站名称 - 副标题」。网站名称取自系统设置中的平台名称。')
            : input('title', 'SEO 标题', editor.value.title, 120, '请输入页面标题，留空使用默认标题', false, '自动追加「 - 官网名称」，无需重复填写。官网名称取自系统设置中的平台名称。')}
          {input('keywords', 'SEO 关键词', editor.value.keywords, 255, '多个关键词使用逗号分隔（选填）')}
          {input('description', 'SEO 描述', editor.value.description, 500, '简要描述页面内容（选填）', true)}
          <Form.Item label="搜索引擎索引" validateStatus={errors['settings.indexable'] ? 'error' : undefined} help={errors['settings.indexable']}>
            <Radio.Group value={editor.value.indexable === null ? 'inherit' : editor.value.indexable ? 'allow' : 'deny'} type="button" disabled={!editable || saving}
              onChange={value => change('indexable', value === 'inherit' ? null : value === 'allow')} options={[{ label: '使用默认', value: 'inherit' }, { label: '允许', value: 'allow' }, { label: '禁止', value: 'deny' }]} />
          </Form.Item>
        </>
      </Form>}
    </Modal>
  </section>
}
