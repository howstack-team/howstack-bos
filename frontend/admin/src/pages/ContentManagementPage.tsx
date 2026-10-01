import { useCallback, useEffect, useState } from 'react'
import { Alert, Button, Descriptions, Drawer, Form, Input, InputNumber, Link, Message, Modal, Select, Space, Switch, Table, Tag, TreeSelect } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { Editor, Viewer } from '@bytemd/react'
import gfm from '@bytemd/plugin-gfm'
import zhHans from 'bytemd/locales/zh_Hans.json'
import 'bytemd/dist/index.css'
import './ContentManagementPage.css'
import { contentApi } from '../api/supportContentApi.ts'
import type { ContentCategory, ContentEntry, ContentKind, ContentResource } from '../api/supportContentApi.ts'
import { useAdminAuth } from '../auth/AuthContext.ts'
import EnabledStatus from '../components/EnabledStatus.tsx'
import TableActions from '../components/TableActions.tsx'
import type { TableAction } from '../components/TableActions.tsx'
import { tableCellText } from '../utils/tableCellText.ts'
import { DEFAULT_TABLE_PAGE_SIZE, TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

type Row = ContentCategory | ContentEntry
const markdownPlugins = [gfm()]

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
  const categories = resource === 'categories'
  const title = kind === 'help' ? (categories ? '帮助分类' : '帮助文档') : (categories ? '公告分类' : '新闻公告')
  const { permissions } = useAdminAuth()
  const can = (action: string) => permissions.includes(`admin.content.${kind}-${resource}.${action}`)
  const canListCategories = permissions.includes(`admin.content.${kind}-categories.view`)
  const [form] = Form.useForm<FormValues>()
  const [rows, setRows] = useState<Row[]>([])
  const [categoryOptions, setCategoryOptions] = useState<ContentCategory[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_TABLE_PAGE_SIZE)
  const [input, setInput] = useState('')
  const [keyword, setKeyword] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [editing, setEditing] = useState<Row | null>(null)
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [detailId, setDetailId] = useState<number | null>(null)
  const [detail, setDetail] = useState<ContentEntry | null>(null)

  const load = useCallback(async () => {
    setLoading(true); setError('')
    try {
      const result = await contentApi.list<Row>(kind, resource, { keyword, page, page_size: pageSize })
      setRows(result.data); setTotal(result.total)
    } catch (reason) { setError(reason instanceof Error ? reason.message : `${title}加载失败`) }
    finally { setLoading(false) }
  }, [kind, resource, keyword, page, pageSize, title])

  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(timer) }, [load])
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
    setEditing(row); form.resetFields()
    form.setFieldsValue(row ? { ...row } : categories
      ? { name: '', parent_id: null, sort_order: 0, status: 1 }
      : { title: '', summary: '', body: '', category_id: undefined, sort_order: 0, status: 'draft' })
    setOpen(true)
  }

  async function save() {
    try {
      const values = await form.validate()
      setSaving(true)
      await contentApi.save(kind, resource, categories
        ? { name: values.name?.trim(), parent_id: kind === 'help' ? values.parent_id ?? null : null,
            sort_order: values.sort_order, status: values.status }
        : { category_id: values.category_id, title: values.title?.trim(), summary: values.summary?.trim() ?? '',
            body: values.body?.trim(), sort_order: values.sort_order, status: values.status }, editing?.id)
      Message.success('保存成功'); setOpen(false); await load(); if (canListCategories) await loadCategoryOptions()
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
    { title: '状态', width: 110, render: (_, row) => categories ? <EnabledStatus status={(row as ContentCategory).status} />
      : <Tag color={(row as ContentEntry).status === 'published' ? 'green' : 'gray'}>{(row as ContentEntry).status === 'published' ? '已发布' : '草稿'}</Tag> },
    { title: '创建时间', dataIndex: 'created_at', width: 180, render: tableCellText },
    { title: '操作', width: 140, fixed: 'right', render: (_, row) => {
      const actions: TableAction[] = []
      if (can('update')) actions.push({ key: 'edit', label: '编辑', onClick: () => edit(row) })
      if (can('delete')) actions.push({ key: 'delete', label: '删除', danger: true, sensitive: true, onClick: () => Modal.confirm({
        title: `删除${title}？`, content: categories ? '分类下已有内容或子分类时不能删除。' : '删除后无法恢复。',
        onOk: async () => { await contentApi.delete(kind, resource, row.id); Message.success('已删除'); await load(); if (canListCategories) await loadCategoryOptions() },
      }) })
      return <TableActions actions={actions} />
    } },
  ]

  return <section className="page-card">
    <div className="page-toolbar"><Space wrap><Input.Search value={input} onChange={setInput} allowClear searchButton="查询"
      placeholder={`搜索${categories ? '分类名称' : '标题'}`} onSearch={() => { setPage(1); setKeyword(input.trim()) }}
      style={{ width: 260, maxWidth: '100%' }} /></Space>
      {can('create') && (categories || canListCategories) && <Button type="primary" onClick={() => edit(null)}>新建{title}</Button>}
    </div>
    {!categories && !canListCategories && can('create') && <Alert type="warning" content="新建内容还需要对应分类的查看权限。" style={{ marginBottom: 16 }} />}
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="table-scroll"><Table rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: categories ? 770 : 1240 }}
      pagination={categories && kind === 'help' ? false : { current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: (size) => { setPage(1); setPageSize(size) } }} /></div>
    <Modal title={editing ? `编辑${title}` : `新建${title}`} className="content-markdown-modal" visible={open} onCancel={() => setOpen(false)} onOk={() => void save()}
      confirmLoading={saving} style={{ width: categories ? 600 : 900, maxWidth: 'calc(100vw - 24px)' }}>
      <Form form={form} layout="vertical" style={{ maxHeight: 'calc(100dvh - 190px)', overflowY: 'auto' }}>
        {categories ? <>
          <Form.Item field="name" label="分类名称" rules={[{ required: true, message: '请输入分类名称' }, { maxLength: 64 }]}><Input /></Form.Item>
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
          <Form.Item field="title" label="标题" rules={[{ required: true, message: '请输入标题' }, { maxLength: 200 }]}><Input /></Form.Item>
          <Form.Item field="summary" label="摘要" rules={[{ maxLength: 500 }]}><Input.TextArea autoSize={{ minRows: 2, maxRows: 4 }} /></Form.Item>
          <Form.Item field="body" label="正文（Markdown）" formatter={(value) => value ?? ''}
            rules={[{ required: true, message: '请输入正文' }]}>
            <Editor value="" plugins={markdownPlugins} locale={zhHans} mode="tab" maxLength={100000}
              placeholder="请输入 Markdown 正文" />
          </Form.Item>
        </>}
        <Form.Item field="sort_order" label="排序" rules={[{ required: true }]}><InputNumber min={0} max={9999} /></Form.Item>
        {categories ? <Form.Item field="status" label="状态" triggerPropName="checked" formatter={(value) => value === 1} normalize={(value) => value === true ? 1 : 0}>
          <Switch checkedText="启用" uncheckedText="禁用" />
        </Form.Item> : <Form.Item field="status" label="发布状态" rules={[{ required: true }]}>
          <Select options={[{ value: 'draft', label: '草稿' }, { value: 'published', label: '发布' }]} />
        </Form.Item>}
      </Form>
    </Modal>
    <Drawer title="内容详情" visible={detailId !== null} width="min(760px, 100vw)" footer={null}
      onCancel={() => { setDetailId(null); setDetail(null) }}>
      {detail && <Descriptions title="内容信息" border column={{ xs: 1, sm: 1, md: 2, lg: 2, xl: 2 }} data={[
        { label: '标题', value: detail.title }, { label: '状态', value: detail.status === 'published' ? '已发布' : '草稿' },
        { label: '分类', value: categoryOptions.find((item) => item.id === detail.category_id)?.name ?? String(detail.category_id) },
        { label: '发布时间', value: tableCellText(detail.published_at) },
      ]} />}
      {detail && <div className="content-markdown-viewer"><Viewer value={detail.body} plugins={markdownPlugins} /></div>}
    </Drawer>
  </section>
}
