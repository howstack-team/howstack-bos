import useTableQuery from '../hooks/useTableQuery'
import { applyClientTableQuery } from '../utils/clientTableQuery'
import { IconRefresh, IconPlus } from '@arco-design/web-react/icon'
import FormDialog from '../components/FormDialog'
import { useCallback, useEffect, useState } from 'react'
import { Alert, Button, Form, Input, InputNumber, Link, Message, Modal, Select, Space, Switch, Table, Typography } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { cloudProductApi, cloudError, type ProviderConnection, type ProviderAdapterDefinition, type ConnectionInput } from '../api/cloudProductApi'
import { useAdminAuth } from '../auth/AuthContext'
import EnabledStatus from '../components/EnabledStatus'
import TableActions, { type TableAction } from '../components/TableActions'
import { tableCellText } from '../utils/tableCellText'
import ProviderMappingsPage from '../components/ProviderMappingsPage'
import useEditorLocation from '../hooks/useEditorLocation'
import { TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination'

/** 供应连接、连接测试与目录映射；不触发生产操作。 */
export default function ProviderConnectionsPage() {
  const { queryParams, sortColumn, filterColumn, onTableChange, page, pageSize, setPage, setPageSize } = useTableQuery({ sortFields: ['tested_at'], statusValues: [0, 1] })
  const { permissions } = useAdminAuth()
  const can = (action: string) => permissions.includes(`admin.provider-connections.${action}`)
  const [rows, setRows] = useState<ProviderConnection[]>([])
  const [adapters, setAdapters] = useState<ProviderAdapterDefinition[]>([])
  const [adapterId, setAdapterId] = useState<string>()
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<ProviderConnection | null>(null)
  const [saving, setSaving] = useState(false)
  const [clearSecrets, setClearSecrets] = useState<string[]>([])
  const [form] = Form.useForm<ConnectionInput>()
  const { editor: mappingEditor, openEditor: openMapping, closeEditor: closeMapping } = useEditorLocation('mapping')
  const mappingConnection = rows.find((row) => row.id === mappingEditor)
  const load = useCallback(async () => {
    setLoading(true); setError('')
    try { const [connections, definitions] = await Promise.all([cloudProductApi.connections(), cloudProductApi.adapters()]); setRows(connections); setAdapters(definitions) } catch (e) { setError(cloudError(e)) } finally { setLoading(false) }
  }, [])
  useEffect(() => { let active = true; void Promise.resolve().then(() => { if (active) void load() }); return () => { active = false } }, [load])
  const edit = (row: ProviderConnection | null) => {
    setClearSecrets([]); setEditing(row); setAdapterId(row?.adapter_id); form.resetFields(); form.setFieldsValue(row ? { name: row.name, adapter_id: row.adapter_id, configuration: { ...row.configuration }, status: row.status, revision: row.revision } : { name: '', configuration: {}, status: 0, revision: 0 }); setOpen(true)
  }
  const selectedAdapter = adapters.find((adapter) => adapter.id === adapterId)
  const save = async () => {
    let values: ConnectionInput
    try { values = await form.validate() } catch { return }
    setSaving(true)
    try { await cloudProductApi.saveConnection(editing?.id ?? null, { clear_secrets: clearSecrets, name: values.name, adapter_id: values.adapter_id, configuration: Object.fromEntries((selectedAdapter?.configuration_fields || []).map((field) => [field.key, values.configuration?.[field.key] ?? field.default ?? (field.type === 'boolean' ? false : '')])), status: values.status, revision: editing?.revision ?? 0 }); setOpen(false); Message.success('已保存'); await load() }
    catch (e) { Message.error(cloudError(e)) } finally { setSaving(false) }
  }
  const run = async (operation: () => Promise<unknown>, success: string) => {
    setLoading(true)
    try { await operation(); Message.success(success); await load() } catch (e) { Message.error(cloudError(e)) } finally { setLoading(false) }
  }
  const columns: TableColumnProps<ProviderConnection>[] = [
    { title: '供应商名称', dataIndex: 'name', width: 160, ellipsis: true },
    { title: '对接平台', dataIndex: 'adapter_name', width: 180, ellipsis: true, render: (_, row) => `${row.adapter_name}${row.adapter_available ? '' : '（不可用）'}` },
    { title: '平台地址', dataIndex: 'api_url', width: 250, ellipsis: true },
    { ...sortColumn('tested_at'), title: '最近测试', dataIndex: 'tested_at', width: 180, render: tableCellText },
    { ...filterColumn('status', { '0': '禁用', '1': '启用' }), title: '状态', width: 100, render: (_, row) => <EnabledStatus status={row.status} /> },
    { title: '操作', width: 250, fixed: 'right', render: (_, row) => {
      const actions: TableAction[] = [{ key: 'map', label: '资源对应', onClick: () => openMapping(row.id) }]
      if (can('update') && row.adapter_accepting) actions.unshift({ key: 'edit', label: '编辑', onClick: () => edit(row) })
      if (can('test') && row.adapter_available) actions.push({ key: 'test', label: '测试连接', onClick: () => void run(() => cloudProductApi.testConnection(row.id), '连接测试成功') })
      if (can('update') && (row.status || (!row.requires_retest && row.adapter_accepting))) actions.push({ key: 'status', label: row.status ? '禁用' : '启用', sensitive: true, onClick: () => Modal.confirm({ maskClosable: true, title: `${row.status ? '禁用' : '启用'}资源供应商？`, onOk: () => run(() => cloudProductApi.connectionStatus(row, row.status ? 0 : 1), '状态已更新') }) })
      return <TableActions actions={actions} />
    } },
  ]
  if (mappingEditor !== null) return <section className="page-card">
    <ProviderMappingsPage connection={mappingConnection} loading={loading}
      error={can('view') ? error : '没有资源供应商查看权限'} onBack={closeMapping} />
  </section>
  return <section className="page-card">
    {!mappingConnection && error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    {!loading && !error && !adapters.length && <Alert type="warning" content="暂无可选的对接平台，请在应用中心安装对应插件，并由系统管理员完成启用。" style={{ marginBottom: 16 }} />}
    <div className="page-toolbar"><Typography.Title heading={6} style={{ margin: 0 }}>资源供应商</Typography.Title><Space><Button icon={<IconRefresh />} loading={loading} onClick={() => void load()}>刷新</Button>{can('create') && <Button icon={<IconPlus />} type="primary" disabled={loading || !adapters.length} onClick={() => edit(null)}>添加供应商</Button>}</Space></div>
    <div className="table-scroll"><Table onChange={onTableChange} rowKey="id" columns={columns} data={applyClientTableQuery(rows, queryParams, ['status'])} loading={loading} scroll={{ x: 1080 }} pagination={{ current: page, pageSize, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS, showTotal: true, onChange: setPage, onPageSizeChange: setPageSize }} /></div>
    <FormDialog width={860} mode="drawer" visible={open} title={editing ? '编辑供应商' : '添加供应商'} onCancel={() => setOpen(false)} onOk={() => void save()} confirmLoading={saving} okDisabled={!selectedAdapter} >
      <Form form={form} layout="vertical">
        <Form.Item field="name" label="供应商名称" rules={[{ required: true, message: '请输入供应商名称' }, { maxLength: 128 }]}><Input placeholder="请输入供应商名称" /></Form.Item>
        <Form.Item field="adapter_id" label="对接平台" rules={[{ required: true, message: '请选择对接平台' }]} extra={editing ? '对接平台创建后不能更换，需要更换时请添加新的供应商。' : '选择供应商使用的系统，例如魔方云。'}>
          <Select placeholder="请选择对接平台" disabled={!!editing} options={adapters.map((adapter) => ({ value: adapter.id, label: adapter.name }))} onChange={(value) => { setAdapterId(value); form.setFieldValue('configuration', {}) }} />
        </Form.Item>
        {selectedAdapter?.configuration_fields.map((field) => <Form.Item key={`${adapterId}:${field.key}`} field={`configuration.${field.key}`} label={field.label} initialValue={field.default} triggerPropName={field.type === 'boolean' ? 'checked' : 'value'} rules={[
          { required: field.required && !(field.type === 'password' && editing?.configured_secrets[field.key]), message: `请输入${field.label}` },
          ...(field.max_length ? [{ maxLength: field.max_length }] : []), ...(field.type === 'integer' ? [{ type: 'number' as const, min: field.minimum, max: field.maximum }] : []), ...(field.type === 'url' ? [{ type: 'url' as const, message: '请输入有效地址' }] : []),
        ]} extra={field.type === 'password' && editing?.configured_secrets[field.key] ? <Space size="mini"><span>{clearSecrets.includes(field.key) ? '保存后将清除该设置。' : '留空保持原设置。'}</span>{!field.required && <Link onClick={() => { setClearSecrets((keys) => [...keys.filter((key) => key !== field.key), field.key]); form.setFieldValue('configuration', { ...form.getFieldsValue().configuration, [field.key]: '' }) }}>清除</Link>}</Space> : field.description}>
          {field.type === 'boolean' ? <Switch type="round" checkedText="启用" uncheckedText="禁用" /> : field.type === 'integer' ? <InputNumber mode="button" precision={0} placeholder={field.placeholder || `请输入${field.label}`} suffix={field.unit} min={field.minimum} max={field.maximum} style={{ width: '100%' }} /> : field.type === 'select' ? <Select options={field.options} placeholder={field.placeholder || `请选择${field.label}`} /> : field.type === 'password' ? <Input.Password placeholder={field.placeholder || (editing?.configured_secrets[field.key] ? '留空保持原设置' : `请输入${field.label}`)} autoComplete="new-password" onChange={(value) => { if (value) setClearSecrets((keys) => keys.filter((key) => key !== field.key)) }} /> : <Input suffix={field.unit} placeholder={field.placeholder || `请输入${field.label}`} autoComplete="off" />}
        </Form.Item>)}
        {selectedAdapter && <Typography.Paragraph type="secondary">保存后先测试连接，测试成功再启用。修改配置后，需要重新测试并设置资源对应关系。</Typography.Paragraph>}
        <Form.Item field="status" label="状态" triggerPropName="checked" formatter={(value) => value === 1} normalize={(value) => value ? 1 : 0}><Switch type="round" checkedText="启用" uncheckedText="禁用" /></Form.Item>
      </Form>
    </FormDialog>

  </section>
}
