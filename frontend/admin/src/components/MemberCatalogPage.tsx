import { useCallback, useEffect, useState } from 'react'
import { Button, Form, Input, InputNumber, Message, Modal, Select, Space, Switch, Table } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { memberCatalogApi } from '../api/memberCatalogApi.ts'
import type { MemberCatalogInput, MemberGroupRecord, MemberLevelRecord } from '../api/memberCatalogApi.ts'
import { useAdminAuth } from '../auth/AuthContext.ts'
import EnabledStatus from './EnabledStatus.tsx'
import TableActions from './TableActions.tsx'
import type { TableAction } from './TableActions.tsx'
import { tableCellText } from '../utils/tableCellText.ts'
import { DEFAULT_TABLE_PAGE_SIZE, TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

type CatalogRecord = MemberGroupRecord | MemberLevelRecord
interface Props { kind: 'group' | 'level' }

/** 会员分组和等级共用的规范化后台表格。 */
export default function MemberCatalogPage({ kind }: Props) {
  const isGroup = kind === 'group'
  const resource = isGroup ? 'member-groups' : 'member-levels'
  const title = isGroup ? '会员分组' : '会员等级'
  const { permissions } = useAdminAuth()
  const [form] = Form.useForm<MemberCatalogInput>()
  const [rows, setRows] = useState<CatalogRecord[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_TABLE_PAGE_SIZE)
  const [keyword, setKeyword] = useState('')
  const [input, setInput] = useState('')
  const [status, setStatus] = useState<number | undefined>()
  const [loading, setLoading] = useState(false)
  const [editing, setEditing] = useState<CatalogRecord | null>(null)
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const can = (action: string) => permissions.includes(`admin.${resource}.${action}`)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = isGroup
        ? await memberCatalogApi.groups({ keyword: keyword || undefined, status, page, page_size: pageSize })
        : await memberCatalogApi.levels({ keyword: keyword || undefined, status, page, page_size: pageSize })
      setRows(result.data)
      setTotal(result.total)
    } catch (error) { Message.error(error instanceof Error ? error.message : `${title}加载失败`) } finally { setLoading(false) }
  }, [isGroup, keyword, page, pageSize, status, title])

  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(timer) }, [load])

  function edit(row: CatalogRecord | null) {
    setEditing(row)
    form.resetFields()
    form.setFieldsValue(row ? { ...row } : isGroup
      ? { name: '', code: '', sort_order: 0, description: '', status: 1 }
      : { name: '', code: '', level_order: 0, growth_threshold: 0, description: '', status: 1 })
    setOpen(true)
  }

  async function save() {
    try {
      const values = await form.validate()
      setSaving(true)
      if (isGroup) await memberCatalogApi.saveGroup({ ...values, description: values.description?.trim() || '' }, editing?.id)
      else await memberCatalogApi.saveLevel({ ...values, description: values.description?.trim() || '' }, editing?.id)
      Message.success('保存成功'); setOpen(false); await load()
    } catch (error) { if (error instanceof Error) Message.error(error.message) } finally { setSaving(false) }
  }

  const columns: TableColumnProps<CatalogRecord>[] = [
    { title: '名称', dataIndex: 'name', width: 180, ellipsis: true, render: tableCellText },
    { title: '编码', dataIndex: 'code', width: 170, ellipsis: true, render: tableCellText },
    ...(!isGroup ? [{ title: '成长值门槛', dataIndex: 'growth_threshold', width: 130, render: tableCellText } as TableColumnProps<CatalogRecord>] : []),
    { title: '描述', dataIndex: 'description', width: 250, ellipsis: true, render: tableCellText },
    { title: '状态', width: 90, render: (_, row) => <EnabledStatus status={row.status} /> },
    { title: '操作', width: 130, fixed: 'right', render: (_, row) => {
      const actions: TableAction[] = []
      if (can('update')) actions.push({ key: 'edit', label: '编辑', onClick: () => edit(row) })
      if (can('delete')) actions.push({ key: 'delete', label: '删除', sensitive: true, danger: true, onClick: () => Modal.confirm({
        title: `删除${title}？`, content: '有关联会员时系统会阻止删除。', onOk: async () => {
          if (isGroup) await memberCatalogApi.deleteGroup(row.id); else await memberCatalogApi.deleteLevel(row.id)
          Message.success('已删除'); await load()
        },
      }) })
      return <TableActions actions={actions} />
    } },
  ]

  return <section className="page-card">
    <div className="page-toolbar">
      <Space wrap><Input.Search value={input} allowClear placeholder="搜索名称或编码" style={{ width: 240, maxWidth: '100%' }}
        onChange={setInput} onSearch={() => { setPage(1); setKeyword(input.trim()) }} searchButton="查询" />
      <Select value={status} allowClear placeholder="全部状态" style={{ width: 130 }} onChange={(value) => { setPage(1); setStatus(value) }}
        options={[{ label: '启用', value: 1 }, { label: '禁用', value: 0 }]} /></Space>
      {can('create') && <Button type="primary" onClick={() => edit(null)}>新建{title}</Button>}
    </div>
    <div className="table-scroll"><Table rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: isGroup ? 820 : 950 }}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: (size) => { setPage(1); setPageSize(size) } }} /></div>
    <Modal title={editing ? `编辑${title}` : `新建${title}`} visible={open} onCancel={() => setOpen(false)} onOk={() => void save()}
      confirmLoading={saving} style={{ width: 600, maxWidth: 'calc(100vw - 24px)' }}>
      <Form form={form} layout="vertical">
        <Form.Item field="name" label="名称" rules={[{ required: true, message: '请输入名称' }, { maxLength: 64 }]}><Input /></Form.Item>
        <Form.Item field="code" label="编码" rules={[{ required: true, message: '请输入编码' }, { match: /^[a-z][a-z0-9_]{1,63}$/, message: '使用小写字母、数字或下划线，并以字母开头' }]}><Input /></Form.Item>
        {isGroup ? <Form.Item field="sort_order" label="排序" rules={[{ required: true }]}><InputNumber min={0} max={9999} /></Form.Item>
          : <><Form.Item field="level_order" label="等级顺序" rules={[{ required: true }]}><InputNumber min={0} max={9999} /></Form.Item>
            <Form.Item field="growth_threshold" label="成长值门槛" rules={[{ required: true }]}><InputNumber min={0} max={999999999999} /></Form.Item></>}
        <Form.Item field="description" label="描述" rules={[{ maxLength: 255 }]}><Input.TextArea placeholder="选填" maxLength={255} /></Form.Item>
        <Form.Item field="status" label="状态" triggerPropName="checked" formatter={(value) => value === 1} normalize={(value) => value === true ? 1 : 0}>
          <Switch checkedText="启用" uncheckedText="禁用" />
        </Form.Item>
      </Form>
    </Modal>
  </section>
}
