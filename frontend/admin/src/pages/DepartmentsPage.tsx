import { useCallback, useEffect, useState } from 'react'
import { Button, Form, Input, InputNumber, Message, Modal, Switch, Table, TreeSelect } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { accessApi } from '../access/accessApi.ts'
import type { DepartmentInput, DepartmentRecord } from '../access/accessApi.ts'
import { departmentTreeData } from '../access/departmentTree.ts'
import { useAdminAuth } from '../auth/AuthContext.ts'
import TableActions from '../components/TableActions.tsx'
import type { TableAction } from '../components/TableActions.tsx'
import EnabledStatus from '../components/EnabledStatus.tsx'
import { tableCellText } from '../utils/tableCellText.ts'

type TreeRow = DepartmentRecord & { children?: TreeRow[] }

function tree(rows: DepartmentRecord[]): TreeRow[] {
  const byId = new Map(rows.map((row) => [row.id, { ...row } as TreeRow]))
  const roots: TreeRow[] = []
  for (const row of rows) {
    const current = byId.get(row.id)!
    const parent = row.parent_id === null ? undefined : byId.get(row.parent_id)
    if (!parent || parent.id === current.id) roots.push(current)
    else { parent.children ??= []; parent.children.push(current) }
  }
  return roots
}

export default function DepartmentsPage() {
  const { permissions } = useAdminAuth()
  const [form] = Form.useForm<DepartmentInput>()
  const [rows, setRows] = useState<DepartmentRecord[]>([])
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [editing, setEditing] = useState<DepartmentRecord | null>(null)
  const [open, setOpen] = useState(false)
  const can = (action: string) => permissions.includes(`admin.departments.${action}`)

  const load = useCallback(async () => {
    setLoading(true)
    try { setRows(await accessApi.departments()) }
    catch (error) { Message.error(error instanceof Error ? error.message : '部门列表加载失败') }
    finally { setLoading(false) }
  }, [])
  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0)
    return () => window.clearTimeout(timer)
  }, [load])

  function edit(row: DepartmentRecord | null) {
    setEditing(row)
    form.resetFields()
    form.setFieldsValue(row ? { name: row.name, sort_order: row.sort_order, description: row.description, parent_id: row.parent_id, status: row.status } :
      { name: '', sort_order: 0, description: '', parent_id: rows.find((node) => node.is_root)?.id ?? null, status: 1 })
    setOpen(true)
  }

  async function save() {
    try {
      const values = await form.validate()
      setSaving(true)
      await accessApi.saveDepartment({ ...values, description: values.description?.trim() ?? '',
        parent_id: editing?.is_root ? null : (values.parent_id ?? null), status: editing?.is_root ? 1 : values.status }, editing?.id)
      Message.success('保存成功')
      setOpen(false)
      await load()
    } catch (error) { if (error instanceof Error) Message.error(error.message) }
    finally { setSaving(false) }
  }

  const columns: TableColumnProps<TreeRow>[] = [
    { title: '部门名称', dataIndex: 'name', width: 260, ellipsis: true, render: tableCellText },
    { title: '描述', dataIndex: 'description', width: 250, ellipsis: true, render: tableCellText },
    { title: '状态', width: 110, render: (_, row) => <EnabledStatus status={row.status} /> },
    { title: '操作', width: 130, fixed: 'right', render: (_, row) => {
      const actions: TableAction[] = []
      if (can('update')) actions.push({ key: 'edit', label: '编辑', onClick: () => edit(row) })
      if (!row.is_root && can('delete')) actions.push({ key: 'delete', label: '删除', sensitive: true, danger: true, onClick: () => Modal.confirm({
        title: '删除部门？', content: '有关联成员、子部门或角色授权时不能删除。',
        onOk: async () => { await accessApi.deleteDepartment(row.id); Message.success('已删除'); await load() },
      }) })
      return <TableActions actions={actions} />
    } },
  ]

  return <section className="page-card">
    <div className="page-toolbar">{can('create') && <Button type="primary" onClick={() => edit(null)}>新建部门</Button>}</div>
    <div className="table-scroll"><Table rowKey="id" columns={columns} data={tree(rows)} loading={loading} pagination={false} scroll={{ x: 750 }} /></div>
    <Modal title={editing ? '编辑部门' : '新建部门'} visible={open} onCancel={() => setOpen(false)} onOk={() => void save()} confirmLoading={saving} style={{ width: 560, maxWidth: 'calc(100vw - 24px)' }}>
      <Form form={form} layout="vertical" style={{ maxHeight: 'calc(100vh - 180px)', overflowY: 'auto' }}>
        <Form.Item field="name" label="部门名称" rules={[{ required: true, message: '请输入部门名称' }, { minLength: 2, message: '部门名称至少 2 个字符' }, { maxLength: 64, message: '部门名称不能超过 64 个字符' }]}><Input /></Form.Item>
        <Form.Item field="sort_order" label="排序" rules={[{ type: 'number', min: 0, max: 9999, message: '排序须为 0–9999 的整数' }]}><InputNumber min={0} max={9999} precision={0} style={{ width: '100%' }} /></Form.Item>
        <Form.Item field="description" label="描述" rules={[{ maxLength: 255, message: '描述不能超过 255 个字符' }]}><Input.TextArea placeholder="选填" maxLength={255} /></Form.Item>
        {!editing?.is_root && <Form.Item field="parent_id" label="上级部门" rules={editing ? [] : [{ required: true, message: '请选择上级部门' }]} formatter={(value) => value == null ? undefined : String(value)} normalize={(value) => value ? Number(value) : null}>
          <TreeSelect disabled={editing !== null} showSearch placeholder="请选择上级部门" treeData={departmentTreeData(rows.filter((node) => node.id !== editing?.id))} />
        </Form.Item>}
        {!editing?.is_root && <Form.Item field="status" label="状态" triggerPropName="checked" formatter={(value) => value === 1} normalize={(value) => value === true ? 1 : 0}><Switch checkedText="启用" uncheckedText="禁用" /></Form.Item>}
      </Form>
    </Modal>
  </section>
}
