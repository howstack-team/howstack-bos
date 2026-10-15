import { StatusBadge } from '../components/TableEnum'
import { PUBLICATION_STATUSES } from '../utils/businessEnums'
import { enumLabels } from '../utils/tableEnum'
import useTableQuery from '../hooks/useTableQuery'
import { IconPlus } from '@arco-design/web-react/icon'
import TableQueryForm from '../components/TableQueryForm'
import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Alert, Button, Descriptions, Drawer, Form, Input, InputNumber, Link, Message, Modal, Select, Spin, Switch, Table, TreeSelect } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { Editor, Viewer } from '@bytemd/react'
import { getProcessor, type BytemdEditorContext, type BytemdPlugin } from 'bytemd'
import gfm from '@bytemd/plugin-gfm'
import zhHans from 'bytemd/locales/zh_Hans.json'
import 'bytemd/dist/index.css'
import './ContentManagementPage.css'
import { contentImagesApi } from '../api/contentImagesApi'
import { sanitizeContentHtml } from '../components/rich-text/sanitizeContentHtml'
import { contentApi } from '../api/supportContentApi.ts'
import type { ContentCategory, ContentEntry, ContentKind, ContentResource } from '../api/supportContentApi.ts'
import { useAdminAuth } from '../auth/AuthContext.ts'
import FormDialog from '../components/FormDialog'
import MarkdownImageDialog from '../components/MarkdownImageDialog'
import PageFormEditor from '../components/PageFormEditor'
import useEditorLocation from '../hooks/useEditorLocation'
import EnabledStatus from '../components/EnabledStatus.tsx'
import TableActions from '../components/TableActions.tsx'
import type { TableAction } from '../components/TableActions.tsx'
import { tableCellText } from '../utils/tableCellText.ts'
import { TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

type Row = ContentCategory | ContentEntry
const markdownPlugins = [gfm()]
const RichTextEditor = lazy(() => import('../components/rich-text/RichTextEditor'))
const RichTextViewer = lazy(() => import('../components/rich-text/RichTextViewer'))

interface FormValues {
  name?: string; parent_id?: number | null; title?: string; summary?: string; body?: string
  category_id?: number; sort_order: number; status: number | 'draft' | 'published'
}

function flattenCategories(rows: ContentCategory[]): ContentCategory[] {
  return rows.flatMap((row) => [row, ...flattenCategories(row.children ?? [])])
}

function categoryTreeData(rows: ContentCategory[], parentId: number | null = null): { key: string; title: string; children?: ReturnType<typeof categoryTreeData> }[] {
  return rows.filter((row) => row.parent_id === parentId).map((row) => {
    const children = categoryTreeData(rows, row.id)
    return { key: String(row.id), title: row.name, ...(children.length ? { children } : {}) }
  })
}

export default function ContentManagementPage({ kind, resource }: { kind: ContentKind; resource: ContentResource }) {
  const { editor, openEditor, closeEditor } = useEditorLocation()
  const [editorLoading, setEditorLoading] = useState(false)
  const [editorError, setEditorError] = useState('')
  const [editorReady, setEditorReady] = useState<number | 'new' | null>(null)
  const [bodyFormat, setBodyFormat] = useState<'markdown' | 'html'>('markdown')
  const [imageUploading, setImageUploading] = useState(false)
  const [imageContext, setImageContext] = useState<BytemdEditorContext | null>(null)
  const editorPlugins = useMemo<BytemdPlugin[]>(() => [...markdownPlugins, { actions: [{
    title: '插入图片',
    icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 4-6 5 7"/></svg>',
    handler: { type: 'action', click: context => setImageContext(context) },
  }] }], [])
  const categories = resource === 'categories'
  const searchFields = [{ key: categories ? 'name' : 'title', label: categories ? '分类名称' : '标题', maxLength: categories ? 64 : 128 }]
  const { queryParams, sortColumn, filterColumn, onTableChange, draftFields, setField, discard, page, pageSize, setPage, setPageSize, search, reset, refreshKey } = useTableQuery({ sortFields: ['created_at', 'sort_order'], statusValues: categories ? [0, 1] : ['draft', 'published'], textFields: searchFields.map(field => field.key) })
  const title = kind === 'help' ? (categories ? '帮助分类' : '帮助文档') : (categories ? '公告分类' : '新闻公告')
  const { permissions } = useAdminAuth()
  const can = (action: string) => permissions.includes(`admin.content.${kind}-${resource}.${action}`)
  const canListCategories = permissions.includes(`admin.content.${kind}-categories.view`)
  const [form] = Form.useForm<FormValues>()
  const [rows, setRows] = useState<Row[]>([])
  const [categoryOptions, setCategoryOptions] = useState<ContentCategory[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [editing, setEditing] = useState<Row | null>(null)
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [detailId, setDetailId] = useState<number | null>(null)
  const [detail, setDetail] = useState<ContentEntry | null>(null)

  const listRequest = useRef(0)
  const invalidateList = useCallback(() => { listRequest.current++ }, [])
  const load = useCallback(async () => {
    const request = ++listRequest.current
    setLoading(true); setError('')
    try {
      const result = await contentApi.list<Row>(kind, resource, { ...queryParams, page, page_size: pageSize })
      if (request !== listRequest.current) return
      setRows(result.data); setTotal(result.total)
    } catch (reason) {
      if (request !== listRequest.current) return
      setError(reason instanceof Error ? reason.message : `${title}加载失败`) }
    finally { if (request === listRequest.current) setLoading(false) }
  }, [kind, resource,queryParams, page, pageSize, title])

  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => { invalidateList(); window.clearTimeout(timer) } }, [load, refreshKey, invalidateList])
  const loadCategoryOptions = useCallback(async () => {
    const result = await contentApi.list<ContentCategory>(kind, 'categories', { page: 1, page_size: 100 })
    setCategoryOptions(flattenCategories(result.data))
  }, [kind])
  useEffect(() => {
    if (!canListCategories) return
    const timer = window.setTimeout(() => { void loadCategoryOptions().catch(() => setCategoryOptions([])) }, 0)
    return () => window.clearTimeout(timer)
  }, [canListCategories, loadCategoryOptions])
  useEffect(() => {
    if (detailId === null) return
    let active = true
    const timer = window.setTimeout(() => { void contentApi.detail<ContentEntry>(kind, 'entries', detailId)
      .then((result) => { if (active) setDetail(result) }).catch((reason: unknown) => { if (active) Message.error(reason instanceof Error ? reason.message : '详情加载失败') }) }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [kind, detailId])

  function edit(row: Row | null) {
    if (!categories) { openEditor(row?.id); return }
    setEditing(row); form.resetFields()
    form.setFieldsValue(row ? { ...row } : categories
      ? { name: '', parent_id: null, sort_order: 0, status: 1 }
      : { title: '', summary: '', body: '', category_id: undefined, sort_order: 0, status: 'draft' })
    setOpen(true)
  }

  const canEditPage = editor === 'new' ? can('create') && canListCategories : can('update')
  useEffect(() => {
    if (categories || editor === null) return
    let active = true
    void Promise.resolve().then(async () => {
      if (!active) return
      setEditorError(''); setEditorLoading(true)
      if (!canEditPage) {
        setEditorError('没有编辑权限或分类查看权限'); setEditorLoading(false); return
      }
      try {
        const record = editor === 'new' ? null : await contentApi.detail<ContentEntry>(kind, 'entries', editor)
        if (!active) return
        setEditing(record); form.resetFields()
        setBodyFormat(record?.body_format ?? (record ? 'markdown' : kind === 'news' ? 'html' : 'markdown'))
        setImageContext(null); setImageUploading(false)
        form.setFieldsValue(record ? { ...record } : { title: '', summary: '', body: '', category_id: undefined, sort_order: 0, status: 'draft' })
        setEditorReady(editor)
      } catch (reason) {
        if (active) setEditorError(reason instanceof Error ? reason.message : '内容加载失败')
      } finally { if (active) setEditorLoading(false) }
    })
    return () => { active = false }
  }, [categories, editor, kind, form, canEditPage])

  async function save() {
    if (saving || imageUploading || (!categories && (!canEditPage || editorLoading || editorReady !== editor || editorError))) return
    let values: FormValues
    try {
      values = await form.validate()
    } catch {
      return
    }
    try {
      setSaving(true)
      await contentApi.save(kind, resource, categories
        ? { name: values.name?.trim(), parent_id: kind === 'help' ? values.parent_id ?? null : null,
            sort_order: values.sort_order, status: values.status }
        : { category_id: values.category_id, title: values.title?.trim(), summary: values.summary?.trim() ?? '',
            body: values.body?.trim(), body_format: bodyFormat, sort_order: values.sort_order, status: values.status }, editing?.id)
      Message.success('保存成功'); if (categories) setOpen(false); else closeEditor(); await load(); if (canListCategories) await loadCategoryOptions()
    } catch (reason) { if (reason instanceof Error) Message.error(reason.message) }
    finally { setSaving(false) }
  }

  const columns: TableColumnProps<Row>[] = [
    { title: '序号', width: 76, render: (_, __, index) => (categories && kind === 'help' ? 0 : (page - 1) * pageSize) + index + 1 },
    { title: categories ? '分类名称' : '标题', dataIndex: categories ? 'name' : 'title', width: 260, ellipsis: true, render: (_, row) => categories
      ? tableCellText((row as ContentCategory).name)
      : <Link title={(row as ContentEntry).title} onClick={() => setDetailId(row.id)}>{(row as ContentEntry).title}</Link> },
    ...(!categories ? [{ title: '摘要', width: 320, ellipsis: true, render: (_: unknown, row: Row) => tableCellText((row as ContentEntry).summary) } as TableColumnProps<Row>] : []),
    ...(!categories ? [{ title: '分类', width: 150, ellipsis: true, render: (_: unknown, row: Row) =>
      tableCellText(categoryOptions.find((item) => item.id === (row as ContentEntry).category_id)?.name ?? String((row as ContentEntry).category_id)) } as TableColumnProps<Row>] : []),
    { ...sortColumn('sort_order'), title: '排序', width: 90 },
    { ...filterColumn('status', categories ? { '0': '禁用', '1': '启用' } : enumLabels(PUBLICATION_STATUSES)), title: '状态', width: 110, render: (_, row) => categories ? <EnabledStatus status={(row as ContentCategory).status} />
      : <StatusBadge value={(row as ContentEntry).status} mapping={PUBLICATION_STATUSES} /> },
    { ...sortColumn('created_at'), title: '创建时间', dataIndex: 'created_at', width: 180, render: tableCellText },
    { title: '操作', width: 180, fixed: 'right', render: (_, row) => {
      const actions: TableAction[] = []
      if (can('update')) actions.push({ key: 'edit', label: '编辑', onClick: () => edit(row) })
      if (can('delete')) actions.push({ key: 'delete', label: '删除', danger: true, sensitive: true, onClick: () => Modal.confirm({ maskClosable: true,
        title: `删除${title}？`, content: categories ? '分类下已有内容或子分类时不能删除。' : '删除后无法恢复。',
        onOk: async () => { await contentApi.delete(kind, resource, row.id); Message.success('已删除'); await load(); if (canListCategories) await loadCategoryOptions() },
      }) })
      return <TableActions actions={actions} />
    } },
  ]

  const editorForm = (
    <Form form={form} {...(categories ? { layout: 'vertical' as const } : { layout: 'vertical' as const, className: 'content-editor-fields' })} onSubmit={() => void save()}>
      {categories ? <>
        <Form.Item field="name" label="分类名称" rules={[{ required: true, message: '请输入分类名称' }, { maxLength: 64 }]}><Input placeholder="请输入分类名称" /></Form.Item>
        {kind === 'help' && <Form.Item field="parent_id" label="上级分类" formatter={(value) => value == null ? undefined : String(value)}
          normalize={(value) => value ? Number(value) : null}>
          <TreeSelect allowClear placeholder="不选择则为一级分类" treeData={categoryTreeData(categoryOptions.filter((item) => item.parent_id === null && item.id !== editing?.id))} />
        </Form.Item>}
      </> : <>
        {kind === 'help' ? <Form.Item field="category_id" label="分类" rules={[{ required: true, message: '请选择分类' }]}
          formatter={(value) => value == null ? undefined : String(value)} normalize={(value) => value ? Number(value) : undefined}>
          <TreeSelect showSearch placeholder="选择分类" treeData={categoryTreeData(categoryOptions)} />
        </Form.Item> : <Form.Item field="category_id" label="分类" rules={[{ required: true, message: '请选择分类' }]}>
          <Select options={categoryOptions.filter((item) => item.status === 1 || item.id === (editing as ContentEntry | null)?.category_id)
            .map((item) => ({ value: item.id, label: item.name }))} placeholder="选择分类" />
        </Form.Item>}
        <Form.Item field="title" label="标题" rules={[{ required: true, message: '请输入标题' }, { maxLength: 200 }]}><Input placeholder="请输入标题" /></Form.Item>
        <Form.Item className="form-dialog-wide" field="summary" label="摘要" rules={[{ maxLength: 500 }]}><Input.TextArea placeholder="请输入摘要" autoSize={{ minRows: 2, maxRows: 4 }} /></Form.Item>
        {kind === 'news' && bodyFormat === 'markdown' && <div className="form-dialog-wide">
          <Alert type="info" content="此公告使用 Markdown。可继续编辑，也可转换为富文本后调整排版。"
            action={<Button htmlType="button" type="text" onClick={() => Modal.confirm({ maskClosable: true,
              title: '转换为富文本？', content: '转换后请预览排版再保存；返回列表可放弃本次修改。',
              onOk: () => {
                const markdown = String(form.getFieldValue('body') ?? '')
                const html = String(getProcessor({ plugins: markdownPlugins }).processSync(markdown))
                form.setFieldsValue({ body: sanitizeContentHtml(html) }); setBodyFormat('html')
              },
            })}>转换为富文本</Button>} />
        </div>}
        <Form.Item className="form-dialog-wide" field="body" label={bodyFormat === 'html' ? '正文' : '正文（Markdown）'} formatter={(value) => value ?? ''}
          rules={[{ required: true, message: '请输入正文' }, { maxLength: 100000, message: '正文不能超过100000字符' }]}>
          {bodyFormat === 'html' ? <RichTextEditor value="" kind={kind} onUploadingChange={setImageUploading} />
            : <Editor value="" plugins={editorPlugins} locale={zhHans} mode="auto" maxLength={100000}
                uploadImages={async files => {
                  setImageUploading(true)
                  try {
                    const images = []
                    for (const file of files) images.push(await contentImagesApi.upload(kind, file))
                    return images
                  } catch (error) { Message.error(error instanceof Error ? error.message : '图片上传失败'); return [] }
                  finally { setImageUploading(false) }
                }} placeholder="请输入 Markdown 正文" />}
        </Form.Item>
      </>}
      <Form.Item field="sort_order" label="排序" rules={[{ required: true }]}><InputNumber mode="button" precision={0} placeholder="请输入排序" min={0} max={9999} /></Form.Item>
      {categories ? <Form.Item field="status" label="状态" triggerPropName="checked" formatter={(value) => value === 1} normalize={(value) => value === true ? 1 : 0}>
        <Switch type="round" checkedText="启用" uncheckedText="禁用" />
      </Form.Item> : <Form.Item field="status" label="发布状态" rules={[{ required: true }]}>
        <Select placeholder="请选择发布状态" options={[{ value: 'draft', label: '草稿' }, { value: 'published', label: '发布' }]} />
      </Form.Item>}
    </Form>
  )
  if (!categories && editor !== null) return <section className="page-card content-markdown-editor">
    <PageFormEditor title={`${editor === 'new' ? '新建' : '编辑'}${title}`} onCancel={closeEditor}
      onSave={() => void save()} saving={saving} disabled={imageUploading || editorLoading || editorReady !== editor || Boolean(editorError) || !canEditPage}>
      {editorError ? <Alert type="error" content={editorError} /> : editorLoading || editorReady !== editor ? <Spin /> : <Suspense fallback={<Spin />}>{editorForm}</Suspense>}
    </PageFormEditor>
    {imageContext && <MarkdownImageDialog context={imageContext} onClose={() => setImageContext(null)} />}
  </section>

  return <section className="page-card">
    <TableQueryForm fields={searchFields} values={draftFields} onFieldChange={setField}
      appliedCount={Object.keys(queryParams).filter(key => !['sort_by', 'sort_order'].includes(key)).length} onCancelFilters={discard}
      onSearch={search} onReset={reset} loading={loading}></TableQueryForm>
    <div className="page-toolbar">{can('create') && (categories || canListCategories) && <Button icon={<IconPlus />} type="primary" onClick={() => edit(null)}>新建{title}</Button>}</div>
    {!categories && !canListCategories && can('create') && <Alert type="warning" content="新建内容还需要对应分类的查看权限。" style={{ marginBottom: 16 }} />}
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="table-scroll"><Table onChange={onTableChange} rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: categories ? 770 : 1240 }}
      pagination={categories && kind === 'help' ? false : { current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: setPageSize }} /></div>
    {categories && <FormDialog width={520} title={editing ? `编辑${title}` : `新建${title}`} visible={open}
      onCancel={() => setOpen(false)} onOk={() => void save()} confirmLoading={saving}>
      {editorForm}
    </FormDialog>}
    <Drawer maskClosable title="内容详情" visible={detailId !== null} width="min(760px, calc(100vw - 24px))" footer={null}
      onCancel={() => { setDetailId(null); setDetail(null) }}>
      {detail && <Descriptions title="内容信息" border column={{ xs: 1, sm: 1, md: 2, lg: 2, xl: 2 }} data={[
        { label: '标题', value: detail.title }, { label: '状态', value: detail.status === 'published' ? '已发布' : '草稿' },
        { label: '分类', value: categoryOptions.find((item) => item.id === detail.category_id)?.name ?? String(detail.category_id) },
        { label: '发布时间', value: tableCellText(detail.published_at) },
      ]} />}
      {detail && <div className="content-markdown-viewer">{detail.body_format === 'html' ? <Suspense fallback={<Spin />}><RichTextViewer value={detail.body} /></Suspense> : <Viewer value={detail.body} plugins={markdownPlugins} />}</div>}
    </Drawer>
  </section>
}
