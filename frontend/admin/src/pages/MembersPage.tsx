import useTableQuery from '../hooks/useTableQuery'
import { IconPlus } from '@arco-design/web-react/icon'
import TableQueryForm from '../components/TableQueryForm'
import { useCompactFormViewport } from '../hooks/useCompactFormLayout'
import FormDialog from '../components/FormDialog'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Button, Form, Input, Message, Modal, Select, Switch, Table } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { memberApi } from '../api/memberApi.ts'
import type { MemberInput, MemberRecord } from '../api/memberApi.ts'
import { memberCatalogApi } from '../api/memberCatalogApi.ts'
import type { MemberOption } from '../api/memberCatalogApi.ts'
import { useAdminAuth } from '../auth/AuthContext.ts'
import EnabledStatus from '../components/EnabledStatus.tsx'
import TableActions from '../components/TableActions.tsx'
import type { TableAction } from '../components/TableActions.tsx'
import { tableCellText } from '../utils/tableCellText.ts'
import { TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

/** 运营后台会员查询与基础资料管理。 */
export default function MembersPage() {
  const searchFields = [
    { key: 'nickname', label: '昵称', maxLength: 64 },
    { key: 'mobile', label: '手机号', maxLength: 20 },
    { key: 'email', label: '邮箱', maxLength: 254 },
  ]
  const { queryParams, sortColumn, filterColumn, onTableChange, draftFields, setField, discard, page, pageSize, setPage, setPageSize, search, reset, refreshKey, status: appliedStatus, draftStatus, setDraftStatus } = useTableQuery({ sortFields: ['created_at', 'last_login_at'], textFields: searchFields.map(field => field.key), statusValues: [0, 1] })
  const status = appliedStatus === undefined ? undefined : Number(appliedStatus) as 0 | 1
  const wideViewport = useCompactFormViewport()
  const { permissions } = useAdminAuth()
  const [form] = Form.useForm<MemberInput>()
  const [rows, setRows] = useState<MemberRecord[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [groups, setGroups] = useState<MemberOption[]>([])
  const [levels, setLevels] = useState<MemberOption[]>([])
  const [editing, setEditing] = useState<MemberRecord | null>(null)
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const can = (action: string) => permissions.includes(`admin.members.${action}`)

  const listRequest = useRef(0)
  const invalidateList = useCallback(() => { listRequest.current++ }, [])
  const load = useCallback(async () => {
    const request = ++listRequest.current
    setLoading(true)
    try {
      const result = await memberApi.list({ ...queryParams, status, page, page_size: pageSize })
      if (request !== listRequest.current) return
      setRows(result.data)
      setTotal(result.total)
    } catch (error) {
      if (request !== listRequest.current) return

      Message.error(error instanceof Error ? error.message : '会员列表加载失败')
    } finally {
      if (request === listRequest.current) setLoading(false)
    }
  }, [page, pageSize,queryParams, status])

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0)
    return () => { invalidateList(); window.clearTimeout(timer) }
  }, [load, refreshKey, invalidateList])
  useEffect(() => {
    void memberCatalogApi.options().then((result) => { setGroups(result.groups); setLevels(result.levels) })
      .catch(() => Message.error('会员分组和等级选项加载失败'))
  }, [])

  function showEditor(row: MemberRecord | null) {
    setEditing(row)
    form.resetFields()
    form.setFieldsValue(row ? {
      nickname: row.nickname,
      group_id: row.group_id,
      level_id: row.level_id,
      mobile: row.mobile ?? '',
      email: row.email ?? '',
      description: row.description,
      status: row.status,
    } : { nickname: '', group_id: null, level_id: null, mobile: '', email: '', description: '', status: 1 })
    setOpen(true)
  }

  async function save() {
    let values: MemberInput
    try {
      values = await form.validate()
    } catch {
      return
    }
    try {
      setSaving(true)
      await memberApi.save({
        ...values,
        group_id: values.group_id ?? null,
        level_id: values.level_id ?? null,
        mobile: values.mobile?.trim() || null,
        email: values.email?.trim() || null,
        description: values.description?.trim() || '',
        password: values.password || undefined,
      }, editing?.id)
      Message.success('保存成功')
      setOpen(false)
      await load()
    } catch (error) {
      if (error instanceof Error) Message.error(error.message)
    } finally {
      setSaving(false)
    }
  }

  const columns: TableColumnProps<MemberRecord>[] = [
    { title: '序号', width: 80, render: (_, __, index) => (page - 1) * pageSize + index + 1 },
    { title: '会员ID', dataIndex: 'public_id', width: 120, render: tableCellText },
    { title: '昵称', dataIndex: 'nickname', width: 150, ellipsis: true, render: tableCellText },
    { title: '会员分组', dataIndex: 'group_name', width: 140, ellipsis: true, render: tableCellText },
    { title: '会员等级', dataIndex: 'level_name', width: 140, ellipsis: true, render: tableCellText },
    { title: '手机号', dataIndex: 'mobile', width: 160, ellipsis: true, render: tableCellText },
    { title: '邮箱', dataIndex: 'email', width: 220, ellipsis: true, render: tableCellText },
    { ...filterColumn('status', { '0': '禁用', '1': '启用' }), title: '状态', width: 90, render: (_, row) => <EnabledStatus status={row.status} /> },
    { ...sortColumn('created_at'), title: '注册时间', dataIndex: 'created_at', width: 180, render: tableCellText },
    { ...sortColumn('last_login_at'), title: '最近登录', dataIndex: 'last_login_at', width: 180, render: tableCellText },
    { title: '描述', dataIndex: 'description', width: 220, ellipsis: true, render: tableCellText },
    { title: '操作', width: 170, fixed: 'right', render: (_, row) => {
      const actions: TableAction[] = []
      if (can('update')) actions.push({ key: 'edit', label: '编辑', onClick: () => showEditor(row) })
      if (can('delete')) actions.push({ key: 'delete', label: '删除', sensitive: true, danger: true, onClick: () => Modal.confirm({ maskClosable: true,
        title: '删除会员？',
        content: '删除后该会员将无法继续使用账号。',
        onOk: async () => { await memberApi.delete(row.id); Message.success('已删除'); await load() },
      }) })
      return <TableActions actions={actions} />
    } },
  ]

  return <section className="page-card">
    <TableQueryForm fields={searchFields} values={draftFields} onFieldChange={setField}
      appliedCount={Object.keys(queryParams).filter(key => !['sort_by', 'sort_order'].includes(key)).length} onCancelFilters={discard}
      onSearch={search} onReset={reset} loading={loading}>

        <Form.Item label="状态" className="table-query-field" style={{ marginBottom: 0, marginRight: 0, minWidth: 0, maxWidth: '100%' }}><Select value={draftStatus === undefined ? undefined : Number(draftStatus)} allowClear placeholder="全部状态" style={{ width: 130 }} onChange={setDraftStatus}
          options={[{ label: '启用', value: 1 }, { label: '禁用', value: 0 }]} /></Form.Item>
      </TableQueryForm>
    <div className="page-toolbar">{can('create') && <Button icon={<IconPlus />} type="primary" onClick={() => showEditor(null)}>新建会员</Button>}</div>
    <div className="table-scroll"><Table onChange={onTableChange} rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: 1790 }}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: setPageSize }} /></div>
    <FormDialog width={640} mode="drawer" title={editing ? '编辑会员' : '新建会员'} visible={open} onCancel={() => setOpen(false)} onOk={() => void save()}
      confirmLoading={saving}>
      <Form form={form} layout={wideViewport ? 'horizontal' : 'vertical'}
        labelCol={wideViewport ? { flex: '112px' } : undefined}
        wrapperCol={wideViewport ? { flex: 'auto' } : undefined}>
        <Form.Item field="nickname" label="昵称" rules={[{ required: true, message: '请输入昵称' },
          { minLength: 2, message: '昵称至少 2 个字符' }, { maxLength: 64, message: '昵称不能超过 64 个字符' }]}><Input placeholder="请输入昵称" /></Form.Item>
        <Form.Item field="mobile" label="手机号" dependencies={['email']} tooltip="会员使用此手机号登录；6–20 位数字，可带 + 前缀。" rules={[{ validator: (value, callback) => callback(value || form.getFieldValue('email') ? undefined : '手机号和邮箱至少填写一项') },
          { match: /^\+?[0-9]{6,20}$/, message: '请输入 6–20 位数字，可带 + 前缀' }]}>
          <Input placeholder="请输入会员登录手机号" maxLength={21} />
        </Form.Item>
        <Form.Item field="group_id" label="会员分组"><Select allowClear placeholder="请选择会员分组（选填）" options={groups.map((item) => ({ label: item.name, value: item.id }))} /></Form.Item>
        <Form.Item field="level_id" label="会员等级"><Select allowClear placeholder="请选择会员等级（选填）" options={levels.map((item) => ({ label: item.name, value: item.id }))} /></Form.Item>
        <Form.Item field="email" label="邮箱" dependencies={['mobile']} rules={[{ validator: (value, callback) => callback(value || form.getFieldValue('mobile') ? undefined : '手机号和邮箱至少填写一项') },{ type: 'email', message: '邮箱格式不正确' },
          { maxLength: 254, message: '邮箱不能超过 254 个字符' }]}><Input placeholder="请输入邮箱（选填）" maxLength={254} /></Form.Item>
        <Form.Item field="password" label={editing ? '重设密码' : '密码'} tooltip="至少 8 个字符，最多 72 字节。" extra={editing ? '留空保持原密码。' : undefined}
          rules={[...(editing ? [{ minLength: 8, message: '密码至少 8 个字符' }]
            : [{ required: true, message: '请输入密码' }, { minLength: 8, message: '密码至少 8 个字符' }]),
            { validator: (value: string, callback: (error?: string) => void) => callback(value && new TextEncoder().encode(value).length > 72 ? '密码最多 72 字节' : undefined) }]}>
          <Input.Password placeholder={editing ? '留空保持原密码' : '请输入密码'} autoComplete="new-password" maxLength={72} />
        </Form.Item>
        <Form.Item field="description" label="描述" rules={[{ maxLength: 255, message: '描述不能超过 255 个字符' }]}>
          <Input.TextArea placeholder="请输入描述（选填）" maxLength={255} />
        </Form.Item>
        <Form.Item field="status" label="状态" triggerPropName="checked" formatter={(value) => value === 1}
          normalize={(value) => value === true ? 1 : 0}><Switch type="round" checkedText="启用" uncheckedText="禁用" /></Form.Item>
      </Form>
    </FormDialog>
  </section>
}
