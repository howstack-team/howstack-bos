import { useCallback, useEffect, useState } from 'react'
import { Button, Form, Input, Message, Modal, Select, Space, Switch, Table } from '@arco-design/web-react'
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
import { DEFAULT_TABLE_PAGE_SIZE, TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

/** 运营后台会员查询与基础资料管理。 */
export default function MembersPage() {
  const { permissions } = useAdminAuth()
  const [form] = Form.useForm<MemberInput>()
  const [rows, setRows] = useState<MemberRecord[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_TABLE_PAGE_SIZE)
  const [keyword, setKeyword] = useState('')
  const [searchKeyword, setSearchKeyword] = useState('')
  const [status, setStatus] = useState<number | undefined>()
  const [loading, setLoading] = useState(false)
  const [groups, setGroups] = useState<MemberOption[]>([])
  const [levels, setLevels] = useState<MemberOption[]>([])
  const [editing, setEditing] = useState<MemberRecord | null>(null)
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const can = (action: string) => permissions.includes(`admin.members.${action}`)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await memberApi.list({ keyword: searchKeyword || undefined, status, page, page_size: pageSize })
      setRows(result.data)
      setTotal(result.total)
    } catch (error) {
      Message.error(error instanceof Error ? error.message : '会员列表加载失败')
    } finally {
      setLoading(false)
    }
  }, [page, pageSize, searchKeyword, status])

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0)
    return () => window.clearTimeout(timer)
  }, [load])
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
      mobile: row.mobile,
      email: row.email ?? '',
      description: row.description,
      status: row.status,
    } : { nickname: '', group_id: null, level_id: null, mobile: '', email: '', description: '', status: 1 })
    setOpen(true)
  }

  async function save() {
    try {
      const values = await form.validate()
      setSaving(true)
      await memberApi.save({
        ...values,
        group_id: values.group_id ?? null,
        level_id: values.level_id ?? null,
        mobile: values.mobile.trim(),
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

  function search() {
    setPage(1)
    setSearchKeyword(keyword.trim())
  }

  const columns: TableColumnProps<MemberRecord>[] = [
    { title: '序号', width: 80, render: (_, __, index) => (page - 1) * pageSize + index + 1 },
    { title: '昵称', dataIndex: 'nickname', width: 150, ellipsis: true, render: tableCellText },
    { title: '会员分组', dataIndex: 'group_name', width: 140, ellipsis: true, render: tableCellText },
    { title: '会员等级', dataIndex: 'level_name', width: 140, ellipsis: true, render: tableCellText },
    { title: '手机号', dataIndex: 'mobile', width: 160, ellipsis: true, render: tableCellText },
    { title: '邮箱', dataIndex: 'email', width: 220, ellipsis: true, render: tableCellText },
    { title: '状态', width: 90, render: (_, row) => <EnabledStatus status={row.status} /> },
    { title: '注册时间', dataIndex: 'created_at', width: 180, render: tableCellText },
    { title: '最近登录', dataIndex: 'last_login_at', width: 180, render: tableCellText },
    { title: '描述', dataIndex: 'description', width: 220, ellipsis: true, render: tableCellText },
    { title: '操作', width: 130, fixed: 'right', render: (_, row) => {
      const actions: TableAction[] = []
      if (can('update')) actions.push({ key: 'edit', label: '编辑', onClick: () => showEditor(row) })
      if (can('delete')) actions.push({ key: 'delete', label: '删除', sensitive: true, danger: true, onClick: () => Modal.confirm({
        title: '删除会员？',
        content: '删除后该会员将无法继续使用账号。',
        onOk: async () => { await memberApi.delete(row.id); Message.success('已删除'); await load() },
      }) })
      return <TableActions actions={actions} />
    } },
  ]

  return <section className="page-card">
    <div className="page-toolbar">
      <Space wrap>
        <Input.Search value={keyword} allowClear placeholder="搜索昵称、手机号或邮箱" style={{ width: 280, maxWidth: '100%' }}
          onChange={setKeyword} onSearch={search} searchButton="查询" />
        <Select value={status} allowClear placeholder="全部状态" style={{ width: 130 }} onChange={(value) => { setPage(1); setStatus(value) }}
          options={[{ label: '启用', value: 1 }, { label: '禁用', value: 0 }]} />
      </Space>
      {can('create') && <Button type="primary" onClick={() => showEditor(null)}>新建会员</Button>}
    </div>
    <div className="table-scroll"><Table rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: 1670 }}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: (size) => { setPage(1); setPageSize(size) } }} /></div>
    <Modal title={editing ? '编辑会员' : '新建会员'} visible={open} onCancel={() => setOpen(false)} onOk={() => void save()}
      confirmLoading={saving} style={{ width: 620, maxWidth: 'calc(100vw - 24px)' }}>
      <Form form={form} layout="vertical" style={{ maxHeight: 'calc(100vh - 180px)', overflowY: 'auto' }}>
        <Form.Item field="nickname" label="昵称" rules={[{ required: true, message: '请输入昵称' },
          { minLength: 2, message: '昵称至少 2 个字符' }, { maxLength: 64, message: '昵称不能超过 64 个字符' }]}><Input /></Form.Item>
        <Form.Item field="group_id" label="会员分组"><Select allowClear placeholder="选填" options={groups.map((item) => ({ label: item.name, value: item.id }))} /></Form.Item>
        <Form.Item field="level_id" label="会员等级"><Select allowClear placeholder="选填" options={levels.map((item) => ({ label: item.name, value: item.id }))} /></Form.Item>
        <Form.Item field="mobile" label="手机号" rules={[{ required: true, message: '请输入手机号' },
          { match: /^\+?[0-9]{6,20}$/, message: '请输入 6–20 位数字，可带 + 前缀' }]}>
          <Input placeholder="会员登录手机号" maxLength={21} />
        </Form.Item>
        <Form.Item field="email" label="邮箱" rules={[{ type: 'email', message: '邮箱格式不正确' },
          { maxLength: 254, message: '邮箱不能超过 254 个字符' }]}><Input placeholder="选填" maxLength={254} /></Form.Item>
        <Form.Item field="description" label="描述" rules={[{ maxLength: 255, message: '描述不能超过 255 个字符' }]}>
          <Input.TextArea placeholder="选填" maxLength={255} />
        </Form.Item>
        <Form.Item field="password" label={editing ? '重设密码（留空不变）' : '密码'}
          rules={editing ? [{ minLength: 8, message: '密码至少 8 个字符' }]
            : [{ required: true, message: '请输入密码' }, { minLength: 8, message: '密码至少 8 个字符' }]}>
          <Input.Password autoComplete="new-password" />
        </Form.Item>
        <Form.Item field="status" label="状态" triggerPropName="checked" formatter={(value) => value === 1}
          normalize={(value) => value === true ? 1 : 0}><Switch checkedText="启用" uncheckedText="禁用" /></Form.Item>
      </Form>
    </Modal>
  </section>
}
