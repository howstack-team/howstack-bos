import useTableQuery from '../hooks/useTableQuery'
import { IconPlus } from '@arco-design/web-react/icon'
import { useCompactFormViewport } from '../hooks/useCompactFormLayout'
import FormDialog from '../components/FormDialog'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Button, Form, Input, Message, Modal, Select, Switch, Table, TreeSelect } from '@arco-design/web-react'
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
import { TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

/** 系统用户列表与 Webman 管理员 CRUD。 */
export default function AdministratorsPage() {
  const wideViewport = useCompactFormViewport()
  const { user, permissions } = useAdminAuth()
  const [form] = Form.useForm<AdministratorInput>()
  const [rows, setRows] = useState<AdministratorRecord[]>([])
  const [total, setTotal] = useState(0)
  const { queryParams, sortColumn, filterColumn, onTableChange, page, pageSize, setPage, setPageSize } = useTableQuery({ sortFields: ['last_login_at'], statusValues: [0, 1] })
  const [loading, setLoading] = useState(false)
  const [roles, setRoles] = useState<RoleRecord[]>([])
  const [departments, setDepartments] = useState<DepartmentRecord[]>([])
  const [editing, setEditing] = useState<AdministratorRecord | null>(null)
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const can = (action: string) => permissions.includes(`admin.administrators.${action}`)

  const listRequest = useRef(0)
  const invalidateList = useCallback(() => { listRequest.current++ }, [])
  const load = useCallback(async () => {
    const request = ++listRequest.current
    setLoading(true)
    try {
      const result = await accessApi.administrators({ ...queryParams, page, page_size: pageSize })
      if (request !== listRequest.current) return
      setRows(result.data)
      setTotal(result.total)
    } catch (error) {
      if (request === listRequest.current) Message.error(error instanceof Error ? error.message : '用户列表加载失败')
    } finally {
      if (request === listRequest.current) setLoading(false)
    }
  }, [queryParams, page, pageSize])

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0)
    return () => { invalidateList(); window.clearTimeout(timer) }
  }, [load, invalidateList])
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
    let values: AdministratorInput
    try {
      values = await form.validate()
    } catch {
      return
    }
    try {
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
    { title: '角色', width: 230, ellipsis: true, render: (_, row) => tableCellText(
      row.role_ids.map((id) => roles.find((role) => role.id === id)?.name ?? `#${id}`).join('、')) },
    { title: '部门', width: 150, render: (_, row) => tableCellText(departments.find((node) => node.id === row.department_id)?.name) },
    { ...filterColumn('status', { '0': '禁用', '1': '启用' }), title: '状态', width: 90, render: (_, row) => <EnabledStatus status={row.status} /> },
    { ...sortColumn('last_login_at'), title: '最近登录', dataIndex: 'last_login_at', width: 180, render: tableCellText },
    { title: '操作', width: 170, fixed: 'right', render: (_, row) => {
      if (row.is_founder || row.id === user?.id) return null
      const actions: TableAction[] = []
      if (can('update')) actions.push({ key: 'edit', label: '编辑', onClick: () => edit(row) })
      if (can('delete')) actions.push({ key: 'delete', label: '删除', sensitive: true, danger: true, onClick: () => Modal.confirm({ maskClosable: true,
        title: '删除系统用户？', content: '删除后该账号将无法登录。',
        onOk: async () => { await accessApi.deleteAdministrator(row.id); Message.success('已删除'); await load() },
      }) })
      return <TableActions actions={actions} />
    } },
  ]

  return <section className="page-card">
    <div className="page-toolbar">{can('create') && <Button icon={<IconPlus />} type="primary" onClick={() => edit(null)}>新建用户</Button>}</div>
    <div className="table-scroll"><Table onChange={onTableChange} rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: 1860 }}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: setPageSize }} /></div>
    <FormDialog width={640} mode="drawer" title={editing ? '编辑系统用户' : '新建系统用户'} visible={open} onCancel={() => setOpen(false)} onOk={() => void save()} confirmLoading={saving}>
      <Form form={form} layout={wideViewport ? 'horizontal' : 'vertical'}
        labelCol={wideViewport ? { flex: '112px' } : undefined}
        wrapperCol={wideViewport ? { flex: 'auto' } : undefined}>
        <Form.Item field="username" label="用户账号" tooltip="6–32 位字母、数字或下划线，须以字母开头。" rules={[{ required: true, message: '请输入用户账号' }, { match: /^[A-Za-z][A-Za-z0-9_]{5,31}$/, message: '须为 6–32 位字母、数字或下划线，且以字母开头' }]}><Input placeholder="请输入用户账号" /></Form.Item>
        <Form.Item field="nickname" label="昵称" rules={[{ required: true, message: '请输入昵称' }, { minLength: 2, message: '昵称至少 2 个字符' }, { maxLength: 32, message: '昵称不能超过 32 个字符' }]}><Input placeholder="请输入昵称" /></Form.Item>
        <Form.Item field="mobile" label="手机号" tooltip="6–20 位数字，可带 + 前缀。" rules={[{ match: /^\+?[0-9]{6,20}$/, message: '请输入 6–20 位数字，可带 + 前缀' }]}><Input placeholder="请输入手机号（选填）" maxLength={21} /></Form.Item>
        <Form.Item field="email" label="邮箱" rules={[{ type: 'email', message: '邮箱格式不正确' }, { maxLength: 254, message: '邮箱不能超过 254 个字符' }]}><Input placeholder="请输入邮箱（选填）" maxLength={254} /></Form.Item>
        <Form.Item field="employee_no" label="工号" rules={[{ maxLength: 32, message: '工号不能超过 32 个字符' }]}><Input placeholder="请输入工号（选填）" maxLength={32} /></Form.Item>
        <Form.Item field="password" label={editing ? '重设密码' : '密码'} tooltip="至少 8 个字符。" extra={editing ? '留空保持原密码。' : undefined} rules={editing ? [{ minLength: 8, message: '密码至少 8 个字符' }] : [{ required: true, message: '请输入密码' }, { minLength: 8, message: '密码至少 8 个字符' }]}><Input.Password placeholder={editing ? '留空保持原密码' : '请输入密码'} autoComplete="new-password" /></Form.Item>
        <Form.Item field="department_id" label="所属部门" formatter={(value) => value == null ? undefined : String(value)} normalize={(value) => value ? Number(value) : null}>
          <TreeSelect allowClear showSearch placeholder="请选择部门" treeData={departmentTreeData(departments)} />
        </Form.Item>
        <Form.Item field="role_ids" label="角色" rules={[{ required: true, message: '请选择角色' }]}><Select placeholder="请选择角色" mode="multiple" options={roles.filter((role) => role.status === 1 && (!role.is_system || user?.is_founder)).map((role) => ({ label: role.name, value: role.id }))} /></Form.Item>
        <Form.Item field="description" label="描述" rules={[{ maxLength: 255, message: '描述不能超过 255 个字符' }]}><Input.TextArea placeholder="请输入描述（选填）" maxLength={255} /></Form.Item>
        <Form.Item field="status" label="状态" triggerPropName="checked" formatter={(value) => value === 1} normalize={(value) => value === true ? 1 : 0}><Switch type="round" checkedText="启用" uncheckedText="禁用" /></Form.Item>
      </Form>
    </FormDialog>
  </section>
}
