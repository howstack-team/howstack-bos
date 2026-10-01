import { useCallback, useEffect, useState } from 'react'
import { Button, Drawer, Form, Input, InputNumber, Message, Modal, Select, Switch, Table, TreeSelect, Typography } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { accessApi } from '../access/accessApi.ts'
import type { DataScopeMode, DepartmentRecord, RoleInput, RoleRecord } from '../access/accessApi.ts'
import { departmentTreeData } from '../access/departmentTree.ts'
import RolePermissionEditor from '../access/RolePermissionEditor.tsx'
import { useAdminAuth } from '../auth/AuthContext.ts'
import TableActions from '../components/TableActions.tsx'
import type { TableAction } from '../components/TableActions.tsx'
import EnabledStatus from '../components/EnabledStatus.tsx'
import { tableCellText } from '../utils/tableCellText.ts'
import { DEFAULT_TABLE_PAGE_SIZE, TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

const scopeLabels: Record<DataScopeMode, string> = {
  all: '全部数据', department_and_children: '本部门及下级',
  department: '本部门', self: '本人数据', custom: '自定义部门',
}
type RoleFields = Pick<RoleInput, 'name' | 'code' | 'sort_order' | 'description' | 'status' | 'data_scope' | 'department_ids'>

export default function RolesPage() {
  const { user, permissions } = useAdminAuth()
  const [roleForm] = Form.useForm<RoleFields>()
  const [rows, setRows] = useState<RoleRecord[]>([])
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [editing, setEditing] = useState<RoleRecord | null>(null)
  const [basicOpen, setBasicOpen] = useState(false)
  const [target, setTarget] = useState<RoleRecord | null>(null)
  const [permissionOpen, setPermissionOpen] = useState(false)
  const [checked, setChecked] = useState<string[]>([])
  const [scopeMode, setScopeMode] = useState<DataScopeMode>('self')
  const [catalog, setCatalog] = useState<Record<string, { label: string; group: string }>>({})
  const [departments, setDepartments] = useState<DepartmentRecord[]>([])
  const can = (action: string) => permissions.includes(`admin.roles.${action}`)

  const load = useCallback(async () => {
    setLoading(true)
    try { setRows(await accessApi.roles()) }
    catch (error) { Message.error(error instanceof Error ? error.message : '角色列表加载失败') }
    finally { setLoading(false) }
  }, [])
  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0)
    return () => window.clearTimeout(timer)
  }, [load])
  useEffect(() => {
    void accessApi.permissions().then(setCatalog).catch(() => Message.error('权限目录加载失败'))
    if (permissions.includes('admin.departments.view')) void accessApi.departments().then(setDepartments).catch(() => Message.error('部门列表加载失败'))
  }, [permissions])

  async function edit(row: RoleRecord | null) {
    if (row) {
      try { row = await accessApi.role(row.id) }
      catch (error) { Message.error(error instanceof Error ? error.message : '读取角色资料失败'); return }
    }
    setEditing(row)
    roleForm.resetFields()
    roleForm.setFieldsValue(row ? {
      name: row.name, code: row.code, sort_order: row.sort_order, description: row.description, status: row.status,
      data_scope: row.data_scope, department_ids: row.department_ids,
    } : { name: '', code: '', sort_order: 0, description: '', status: 1, data_scope: 'self', department_ids: [] })
    setScopeMode(row?.data_scope ?? 'self')
    setBasicOpen(true)
  }

  async function saveRole() {
    try {
      const values = await roleForm.validate()
      setSaving(true)
      const latest = editing ? await accessApi.role(editing.id) : null
      await accessApi.saveRole({
        ...values, description: values.description?.trim() ?? '',
        department_ids: values.data_scope === 'custom' ? (values.department_ids ?? []) : [],
        permissions: latest?.permissions ?? [],
      }, editing?.id)
      Message.success(editing ? '角色已保存' : '角色已创建，请分配权限')
      setBasicOpen(false)
      await load()
    } catch (error) { if (error instanceof Error) Message.error(error.message) }
    finally { setSaving(false) }
  }

  async function openPermissions(row: RoleRecord) {
    try {
      const latest = await accessApi.role(row.id)
      setTarget(latest)
      setChecked(latest.permissions)
      setPermissionOpen(true)
    } catch (error) { Message.error(error instanceof Error ? error.message : '读取角色权限失败') }
  }

  async function savePermissions() {
    if (!target) return
    try {
      setSaving(true)
      const latest = await accessApi.role(target.id)
      await accessApi.saveRole({
        name: latest.name, code: latest.code, sort_order: latest.sort_order,
        description: latest.description, status: latest.status,
        data_scope: latest.data_scope,
        department_ids: latest.department_ids,
        permissions: checked,
      }, target.id)
      Message.success('权限已保存')
      setPermissionOpen(false)
      await load()
    } catch (error) { if (error instanceof Error) Message.error(error.message) }
    finally { setSaving(false) }
  }

  const columns: TableColumnProps<RoleRecord>[] = [
    { title: '角色名称', dataIndex: 'name', width: 190, ellipsis: true, render: tableCellText },
    { title: '描述', dataIndex: 'description', width: 230, ellipsis: true, render: tableCellText },
    { title: '数据范围', width: 150, render: (_, row) => tableCellText(scopeLabels[row.data_scope]) },
    { title: '状态', width: 100, render: (_, row) => <EnabledStatus status={row.status} /> },
    { title: '操作', width: 190, fixed: 'right', render: (_, row) => {
      if (row.is_system) return <Typography.Text type="secondary">不可编辑</Typography.Text>
      const actions: TableAction[] = []
      if (can('update')) {
        actions.push({ key: 'edit', label: '编辑', onClick: () => void edit(row) })
        actions.push({ key: 'permissions', label: '分配权限', sensitive: true, onClick: () => void openPermissions(row) })
      }
      if (can('delete')) actions.push({ key: 'delete', label: '删除', sensitive: true, danger: true, onClick: () => Modal.confirm({
        title: '删除角色？', content: '仅能删除未分配给管理员的角色。',
        onOk: async () => { await accessApi.deleteRole(row.id); Message.success('已删除'); await load() },
      }) })
      return <TableActions actions={actions} />
    } },
  ]

  return <section className="page-card">
    <div className="page-toolbar">{can('create') && <Button type="primary" onClick={() => void edit(null)}>新建角色</Button>}</div>
    <div className="table-scroll"><Table rowKey="id" data={rows} columns={columns} loading={loading}
      pagination={{ defaultPageSize: DEFAULT_TABLE_PAGE_SIZE, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS, showTotal: true }} scroll={{ x: 860 }} /></div>
    <Modal title={editing ? '编辑角色' : '新建角色'} visible={basicOpen} onCancel={() => { if (!saving) setBasicOpen(false) }} onOk={() => void saveRole()} confirmLoading={saving} style={{ width: 560, maxWidth: 'calc(100vw - 24px)' }}>
      <Form form={roleForm} layout="vertical" style={{ maxHeight: 'calc(100vh - 180px)', overflowY: 'auto' }}>
        <Form.Item field="name" label="角色名称" rules={[{ required: true, message: '请输入角色名称' }, { minLength: 2, message: '角色名称至少 2 个字符' }, { maxLength: 64, message: '角色名称不能超过 64 个字符' }]}><Input /></Form.Item>
        <Form.Item field="code" label="角色标识" rules={[{ required: true, message: '请输入角色标识' }, { match: /^[a-z][a-z0-9_]{1,63}$/, message: '须以小写字母开头，且只能包含小写字母、数字和下划线' }]}><Input /></Form.Item>
        <Form.Item field="sort_order" label="排序" rules={[{ type: 'number', min: 0, max: 9999, message: '排序须为 0–9999 的整数' }]}><InputNumber min={0} max={9999} precision={0} style={{ width: '100%' }} /></Form.Item>
        <Form.Item field="description" label="描述" rules={[{ maxLength: 255, message: '描述不能超过 255 个字符' }]}><Input.TextArea placeholder="选填" maxLength={255} /></Form.Item>
        <Form.Item field="status" label="状态" triggerPropName="checked" formatter={(value) => value === 1} normalize={(value) => value === true ? 1 : 0}><Switch checkedText="启用" uncheckedText="禁用" /></Form.Item>
        <Form.Item field="data_scope" label="数据权限" rules={[{ required: true, message: '请选择数据范围' }]}>
          <Select onChange={(value) => setScopeMode(value as DataScopeMode)}
            options={Object.entries(scopeLabels).filter(([value]) => user?.is_founder || value === 'self' || value === 'custom').map(([value, label]) => ({ value, label }))} />
        </Form.Item>
        {scopeMode === 'custom' && <Form.Item field="department_ids" label="允许查看的部门"
          formatter={(value) => (value ?? []).map(String)} normalize={(value) => (value ?? []).map(Number)}>
          <TreeSelect multiple treeCheckable treeCheckStrictly showSearch placeholder="请选择部门" treeData={departmentTreeData(departments)} />
        </Form.Item>}
      </Form>
    </Modal>
    <Drawer title={target ? `分配权限 · ${target.name}` : '分配权限'} visible={permissionOpen} width="min(1100px, 100vw)" onCancel={() => { if (!saving) setPermissionOpen(false) }}
      footer={<div className="drawer-actions"><Button onClick={() => setPermissionOpen(false)}>取消</Button><Button type="primary" loading={saving} onClick={() => void savePermissions()}>保存</Button></div>}>
      {target && <>
        <RolePermissionEditor catalog={catalog} value={checked} grantable={permissions} isFounder={Boolean(user?.is_founder)} onChange={setChecked} />
      </>}
    </Drawer>
  </section>
}
