import { useCallback, useEffect, useState } from 'react'
import { Button, Form, Input, Message, Modal, Select, Space, Switch, Table, Tag, TreeSelect } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { accessApi } from '../access/accessApi.ts'
import type { AdministratorInput, AdministratorRecord, DepartmentRecord, RoleRecord } from '../access/accessApi.ts'
import { departmentTreeData } from '../access/departmentTree.ts'
import { useAdminAuth } from '../auth/AuthContext.ts'
import TableActions from '../components/TableActions.tsx'
import type { TableAction } from '../components/TableActions.tsx'
import EnabledStatus from '../components/EnabledStatus.tsx'
import UserAvatar from '../components/UserAvatar.tsx'
import { tableCellText } from '../utils/tableCellText.ts'
import { DEFAULT_TABLE_PAGE_SIZE, TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

/** 系统用户列表与 Webman 管理员 CRUD。 */
export default function AdministratorsPage() {
  const { user, permissions } = useAdminAuth()
  const [form] = Form.useForm<AdministratorInput>()
  const [rows, setRows] = useState<AdministratorRecord[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_TABLE_PAGE_SIZE)
  const [loading, setLoading] = useState(false)
  const [roles, setRoles] = useState<RoleRecord[]>([])
  const [departments, setDepartments] = useState<DepartmentRecord[]>([])
  const [editing, setEditing] = useState<AdministratorRecord | null>(null)
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const can = (action: string) => permissions.includes(`admin.administrators.${action}`)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await accessApi.administrators({ page, page_size: pageSize })
      setRows(result.data)
      setTotal(result.total)
    } catch (error) {
      Message.error(error instanceof Error ? error.message : '用户列表加载失败')
    } finally {
      setLoading(false)
    }
  }, [page, pageSize])

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0)
    return () => window.clearTimeout(timer)
  }, [load])
  useEffect(() => {
    if (permissions.includes('admin.roles.view')) void accessApi.roles().then(setRoles).catch(() => Message.error('角色列表加载失败'))
    if (permissions.includes('admin.departments.view')) void accessApi.departments().then(setDepartments).catch(() => Message.error('部门列表加载失败'))
  }, [permissions])

  function edit(row: AdministratorRecord | null) {
    setEditing(row)
    form.resetFields()
    form.setFieldsValue(row ? {
      username: row.username, nickname: row.nickname, employee_no: row.employee_no ?? '',
      mobile: row.mobile ?? '', email: row.email ?? '', description: row.description,
      department_id: row.department_id,
      role_ids: row.role_ids, status: row.status,
    } : { username: '', nickname: '', employee_no: '', mobile: '', email: '', description: '', department_id: null, role_ids: [], status: 1 })
    setOpen(true)
  }

  async function save() {
    try {
      const values = await form.validate()
      setSaving(true)
      await accessApi.saveAdministrator({ ...values, employee_no: values.employee_no?.trim() || null,
        mobile: values.mobile?.trim() || null, email: values.email?.trim() || null,
        description: values.description?.trim() || '', department_id: values.department_id ?? null,
        password: values.password || undefined }, editing?.id)
      Message.success('保存成功')
      setOpen(false)
      await load()
    } catch (error) {
      if (error instanceof Error) Message.error(error.message)
    } finally {
      setSaving(false)
    }
  }

  const columns: TableColumnProps<AdministratorRecord>[] = [
    { title: '用户账号', dataIndex: 'username', width: 170, ellipsis: true, render: tableCellText },
    { title: '昵称', dataIndex: 'nickname', width: 170, ellipsis: true, render: (_, row) =>
      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8, maxWidth: '100%', verticalAlign: 'middle' }}>
        <UserAvatar name={row.nickname} size={24} style={{ flexShrink: 0 }} />
        <span title={row.nickname} style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{tableCellText(row.nickname)}</span>
      </span> },
    { title: '工号', dataIndex: 'employee_no', width: 140, ellipsis: true, render: tableCellText },
    { title: '手机号', dataIndex: 'mobile', width: 160, ellipsis: true, render: tableCellText },
    { title: '邮箱', dataIndex: 'email', width: 220, ellipsis: true, render: tableCellText },
    { title: '描述', dataIndex: 'description', width: 220, ellipsis: true, render: tableCellText },
    { title: '角色', width: 230, render: (_, row) => row.role_ids.length
      ? <Space wrap size={4}>{row.role_ids.map((id) => <Tag key={id}>{roles.find((role) => role.id === id)?.name ?? `#${id}`}</Tag>)}</Space>
      : '-' },
    { title: '部门', width: 150, render: (_, row) => tableCellText(departments.find((node) => node.id === row.department_id)?.name) },
    { title: '状态', width: 90, render: (_, row) => <EnabledStatus status={row.status} /> },
    { title: '最近登录', dataIndex: 'last_login_at', width: 180, render: tableCellText },
    { title: '操作', width: 130, fixed: 'right', render: (_, row) => {
      if (row.is_founder || row.id === user?.id) return null
      const actions: TableAction[] = []
      if (can('update')) actions.push({ key: 'edit', label: '编辑', onClick: () => edit(row) })
      if (can('delete')) actions.push({ key: 'delete', label: '删除', sensitive: true, danger: true, onClick: () => Modal.confirm({
        title: '删除系统用户？', content: '删除后该账号将无法登录。',
        onOk: async () => { await accessApi.deleteAdministrator(row.id); Message.success('已删除'); await load() },
      }) })
      return <TableActions actions={actions} />
    } },
  ]

  return <section className="page-card">
    <div className="page-toolbar">{can('create') && <Button type="primary" onClick={() => edit(null)}>新建用户</Button>}</div>
    <div className="table-scroll"><Table rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: 1860 }}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: (size) => { setPage(1); setPageSize(size) } }} /></div>
    <Modal title={editing ? '编辑系统用户' : '新建系统用户'} visible={open} onCancel={() => setOpen(false)} onOk={() => void save()} confirmLoading={saving} style={{ width: 620, maxWidth: 'calc(100vw - 24px)' }}>
      <Form form={form} layout="vertical" style={{ maxHeight: 'calc(100vh - 180px)', overflowY: 'auto' }}>
        <Form.Item field="username" label="用户账号" rules={[{ required: true, message: '请输入用户账号' }, { match: /^[A-Za-z][A-Za-z0-9_]{5,31}$/, message: '须为 6–32 位字母、数字或下划线，且以字母开头' }]}><Input /></Form.Item>
        <Form.Item field="nickname" label="昵称" rules={[{ required: true, message: '请输入昵称' }, { minLength: 2, message: '昵称至少 2 个字符' }, { maxLength: 32, message: '昵称不能超过 32 个字符' }]}><Input /></Form.Item>
        <Form.Item field="employee_no" label="工号" rules={[{ maxLength: 32, message: '工号不能超过 32 个字符' }]}><Input placeholder="选填" maxLength={32} /></Form.Item>
        <Form.Item field="mobile" label="手机号" rules={[{ match: /^\+?[0-9]{6,20}$/, message: '请输入 6–20 位数字，可带 + 前缀' }]}><Input placeholder="选填" maxLength={21} /></Form.Item>
        <Form.Item field="email" label="邮箱" rules={[{ type: 'email', message: '邮箱格式不正确' }, { maxLength: 254, message: '邮箱不能超过 254 个字符' }]}><Input placeholder="选填" maxLength={254} /></Form.Item>
        <Form.Item field="description" label="描述" rules={[{ maxLength: 255, message: '描述不能超过 255 个字符' }]}><Input.TextArea placeholder="选填" maxLength={255} /></Form.Item>
        <Form.Item field="password" label={editing ? '重设密码（留空不变）' : '密码'} rules={editing ? [{ minLength: 8, message: '密码至少 8 个字符' }] : [{ required: true, message: '请输入密码' }, { minLength: 8, message: '密码至少 8 个字符' }]}><Input.Password autoComplete="new-password" /></Form.Item>
        <Form.Item field="department_id" label="所属部门" formatter={(value) => value == null ? undefined : String(value)} normalize={(value) => value ? Number(value) : null}>
          <TreeSelect allowClear showSearch placeholder="请选择部门" treeData={departmentTreeData(departments)} />
        </Form.Item>
        <Form.Item field="role_ids" label="角色" rules={[{ required: true, message: '请选择角色' }]}><Select mode="multiple" options={roles.filter((role) => role.status === 1 && (!role.is_system || user?.is_founder)).map((role) => ({ label: role.name, value: role.id }))} /></Form.Item>
        <Form.Item field="status" label="状态" triggerPropName="checked" formatter={(value) => value === 1} normalize={(value) => value === true ? 1 : 0}><Switch checkedText="启用" uncheckedText="禁用" /></Form.Item>
      </Form>
    </Modal>
  </section>
}
