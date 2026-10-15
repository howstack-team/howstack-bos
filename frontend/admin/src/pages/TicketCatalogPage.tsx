import useTableQuery from '../hooks/useTableQuery'
import { IconPlus } from '@arco-design/web-react/icon'
import TableQueryForm from '../components/TableQueryForm'
import FormDialog from '../components/FormDialog'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Alert, Button, Form, Input, InputNumber, Message, Modal, Switch, Table } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { supportApi } from '../api/supportContentApi.ts'
import type { QuickReply, TicketCatalogKind, TicketType } from '../api/supportContentApi.ts'
import { useAdminAuth } from '../auth/AuthContext.ts'
import EnabledStatus from '../components/EnabledStatus.tsx'
import TableActions from '../components/TableActions.tsx'
import type { TableAction } from '../components/TableActions.tsx'
import { tableCellText } from '../utils/tableCellText.ts'
import { TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

type Row = TicketType | QuickReply
interface FormValues { name?: string; code?: string; title?: string; content?: string; sort_order: number; status: number }

export default function TicketCatalogPage({ kind }: { kind: TicketCatalogKind }) {
  const isType = kind === 'types'
  const searchFields = isType ? [{ key: 'name', label: '名称', maxLength: 64 }, { key: 'code', label: '编码', maxLength: 64 }] : [{ key: 'title', label: '标题', maxLength: 128 }, { key: 'content', label: '回复内容', maxLength: 128 }]
  const { queryParams, sortColumn, filterColumn, onTableChange, draftFields, setField, discard, page, pageSize, setPage, setPageSize, search, reset, refreshKey } = useTableQuery({ sortFields: ['created_at', 'sort_order'], statusValues: [0, 1], textFields: searchFields.map(field => field.key) })
  const title = isType ? '工单类型' : '快捷回复'
  const { permissions } = useAdminAuth()
  const can = (action: string) => permissions.includes(`admin.tickets.${kind}.${action}`)
  const [form] = Form.useForm<FormValues>()
  const [rows, setRows] = useState<Row[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [editing, setEditing] = useState<Row | null>(null)
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)

  const listRequest = useRef(0)
  const invalidateList = useCallback(() => { listRequest.current++ }, [])
  const load = useCallback(async () => {
    const request = ++listRequest.current
    setLoading(true); setError('')
    try {
      const result = await supportApi.catalog<Row>(kind, { ...queryParams, page, page_size: pageSize })
      if (request !== listRequest.current) return
      setRows(result.data); setTotal(result.total)
    } catch (reason) {
      if (request !== listRequest.current) return
      setError(reason instanceof Error ? reason.message : `${title}加载失败`) }
    finally { if (request === listRequest.current) setLoading(false) }
  }, [kind,queryParams, page, pageSize, title])

  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => { invalidateList(); window.clearTimeout(timer) } }, [load, refreshKey, invalidateList])

  function edit(row: Row | null) {
    setEditing(row); form.resetFields()
    form.setFieldsValue(row ? { ...row } : { name: '', code: '', title: '', content: '', sort_order: 0, status: 1 })
    setOpen(true)
  }

  async function save() {
    let values: FormValues
    try {
      values = await form.validate()
    } catch {
      return
    }
    try {
      setSaving(true)
      await supportApi.saveCatalog(kind, isType ? { name: values.name?.trim(), code: values.code?.trim(), sort_order: values.sort_order, status: values.status }
        : { title: values.title?.trim(), content: values.content?.trim(), sort_order: values.sort_order, status: values.status }, editing?.id)
      Message.success('保存成功'); setOpen(false); await load()
    } catch (reason) { if (reason instanceof Error) Message.error(reason.message) }
    finally { setSaving(false) }
  }

  const columns: TableColumnProps<Row>[] = [
    { title: '序号', width: 76, render: (_, __, index) => (page - 1) * pageSize + index + 1 },
    { title: isType ? '名称' : '标题', width: 200, ellipsis: true, render: (_, row) => tableCellText(isType ? (row as TicketType).name : (row as QuickReply).title) },
    isType ? { title: '编码', width: 180, ellipsis: true, render: (_, row) => tableCellText((row as TicketType).code) }
      : { title: '回复内容', width: 380, ellipsis: true, render: (_, row) => tableCellText((row as QuickReply).content) },
    { ...sortColumn('sort_order'), title: '排序', width: 90 },
    { ...filterColumn('status', { '0': '禁用', '1': '启用' }), title: '状态', width: 100, render: (_, row) => <EnabledStatus status={row.status} /> },
    { ...sortColumn('created_at'), title: '创建时间', dataIndex: 'created_at', width: 180, render: tableCellText },
    { title: '操作', width: 180, fixed: 'right', render: (_, row) => {
      const actions: TableAction[] = []
      if (can('update')) actions.push({ key: 'edit', label: '编辑', onClick: () => edit(row) })
      if (can('delete')) actions.push({ key: 'delete', label: '删除', danger: true, sensitive: true, onClick: () => Modal.confirm({ maskClosable: true,
        title: `删除${title}？`, content: isType ? '已有工单引用的类型不能删除。' : '删除后无法恢复。',
        onOk: async () => { await supportApi.deleteCatalog(kind, row.id); Message.success('已删除'); await load() },
      }) })
      return <TableActions actions={actions} />
    } },
  ]

  return <section className="page-card">
    <TableQueryForm fields={searchFields} values={draftFields} onFieldChange={setField}
      appliedCount={Object.keys(queryParams).filter(key => !['sort_by', 'sort_order'].includes(key)).length} onCancelFilters={discard}
      onSearch={search} onReset={reset} loading={loading}></TableQueryForm>
    <div className="page-toolbar">{can('create') && <Button icon={<IconPlus />} type="primary" onClick={() => edit(null)}>新建{title}</Button>}</div>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="table-scroll"><Table onChange={onTableChange} rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: isType ? 880 : 1080 }}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: setPageSize }} /></div>
    <FormDialog width={isType ? 520 : 720} mode={isType ? 'compact' : 'drawer'} title={editing ? `编辑${title}` : `新建${title}`} visible={open} onCancel={() => setOpen(false)} onOk={() => void save()}
      confirmLoading={saving} >
      <Form form={form} layout="vertical">
        {isType ? <>
          <Form.Item field="name" label="类型名称" rules={[{ required: true, message: '请输入名称' }, { maxLength: 64 }]}><Input placeholder="请输入类型名称" /></Form.Item>
          <Form.Item field="code" label="编码" rules={[{ required: true, message: '请输入编码' }, { match: /^[a-z][a-z0-9_]{1,63}$/, message: '以小写字母开头，使用小写字母、数字或下划线' }]}><Input placeholder="请输入编码" /></Form.Item>
        </> : <>
          <Form.Item field="title" label="标题" rules={[{ required: true, message: '请输入标题' }, { maxLength: 100 }]}><Input placeholder="请输入标题" /></Form.Item>
          <Form.Item className="form-dialog-wide" field="content" label="回复内容" rules={[{ required: true, message: '请输入回复内容' }, { maxLength: 5000 }]}><Input.TextArea placeholder="请输入回复内容" autoSize={{ minRows: 4, maxRows: 8 }} /></Form.Item>
        </>}
        <Form.Item field="sort_order" label="排序" rules={[{ required: true }]}><InputNumber mode="button" precision={0} placeholder="请输入排序" min={0} max={9999} /></Form.Item>
        <Form.Item field="status" label="状态" triggerPropName="checked" formatter={(value) => value === 1} normalize={(value) => value === true ? 1 : 0}>
          <Switch type="round" checkedText="启用" uncheckedText="禁用" />
        </Form.Item>
      </Form>
    </FormDialog>
  </section>
}
