import VncConsoleModal from '../components/VncConsoleModal'
import { powerLabels } from '../../../packages/service-operations'
import { IconRefresh } from '@arco-design/web-react/icon'
import { lifecycleErrors } from '../../../packages/service-renewal'
import { useHistory } from 'react-router-dom'
import InstanceOperationsModal from '../components/InstanceOperationsModal'
import ServiceRenewalModal from '../components/ServiceRenewalModal'
import { useAdminAuth } from '../auth/AuthContext'
import { StatusBadge } from '../components/TableEnum'
import { SERVICE_STATUSES as STATUSES, PRODUCT_TYPE_LABELS } from '../utils/businessEnums'
import { enumLabels } from '../utils/tableEnum'
import useTableQuery from '../hooks/useTableQuery'
import TableQueryForm from '../components/TableQueryForm'
import { useEffect, useState } from 'react'
import { Form, Button, Alert, Descriptions, Drawer, Link, Select, Table, Message, Modal, Input } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { getServiceInstance, listServiceInstances, retryServiceProvisioning, confirmServiceNotCreated, retryServiceLifecycle, lifecycleLabel } from '../api/serviceInstanceApi.ts'
import type { ServiceInstance, ServiceProductType } from '../api/serviceInstanceApi.ts'
import TableActions from '../components/TableActions.tsx'
import { tableCellText } from '../utils/tableCellText.ts'
import { TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

const TITLES = PRODUCT_TYPE_LABELS

const DESCRIPTION_COLUMNS = { xs: 1, sm: 1, md: 2, lg: 2, xl: 2, xxl: 2, xxxl: 2 }

/** 指定产品类型的已创建服务实例列表。 */
export default function ServiceInstancesPage({ productType }: { productType: ServiceProductType }) {
  const { permissions } = useAdminAuth()
  const history = useHistory()
  const [vnc, setVnc] = useState<number>()
  const [operation, setOperation] = useState<number>()
  const [renewal, setRenewal] = useState<number>()
  const [resolution, setResolution] = useState<number>()
  const [reason, setReason] = useState('')
  const [evidenceReference, setEvidenceReference] = useState('')
  const [resolving, setResolving] = useState(false)
  const [revision, setRevision] = useState(0)
  const deliveryLabels: Record<string, string> = { queued: '等待开通', processing: '开通处理中', waiting: '等待供应商确认', succeeded: '已开通', failed: '开通失败', attention: '待人工核查' }
  const retry = (id: number) => Modal.confirm({ maskClosable: true, title: '重试实例开通？', content: '结果不明确时只查询原请求，不会重复创建实例。', onOk: async () => { try { await retryServiceProvisioning(id); Message.success('已加入处理队列'); setRevision(value => value + 1) } catch (error) { Message.error(error instanceof Error ? error.message : '重试失败'); throw error } } })
  const searchFields = [
    { key: 'instance_no', label: '实例编号', maxLength: 64 },
    { key: 'display_name', label: '实例名称', maxLength: 128 },
    { key: 'member_mobile', label: '会员手机号', maxLength: 20 },
    { key: 'member_nickname', label: '会员昵称', maxLength: 64 },
  ]
  const { queryParams, sortColumn, filterColumn, onTableChange, draftFields, setField, discard, page, pageSize, setPage, setPageSize, search, reset, refreshKey, status, draftStatus, setDraftStatus } = useTableQuery({ sortFields: ['activated_at', 'expires_at', 'created_at'], textFields: searchFields.map(field => field.key), statusValues: Object.keys(STATUSES) })
  const [rows, setRows] = useState<ServiceInstance[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [detail, setDetail] = useState<ServiceInstance | null>(null)
  const [detailError, setDetailError] = useState('')

  useEffect(() => {
    let active = true
    const timer = window.setTimeout(() => {
      setLoading(true)
      setError('')
      void listServiceInstances({ product_type: productType, ...queryParams, status, page, page_size: pageSize })
        .then((result) => { if (active) { setRows(result.data); setTotal(result.total) } })
        .catch((reason: unknown) => {
          if (active) { setRows([]); setTotal(0); setError(reason instanceof Error ? reason.message : '服务实例加载失败') }
        })
        .finally(() => { if (active) setLoading(false) })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [productType,queryParams, status, page, pageSize, refreshKey, revision])

  useEffect(() => {
    if (selectedId === null) return
    let active = true
    const timer = window.setTimeout(() => {
      setDetail(null)
      setDetailError('')
      void getServiceInstance(selectedId)
        .then((result) => { if (active) setDetail(result) })
        .catch((reason: unknown) => { if (active) setDetailError(reason instanceof Error ? reason.message : '详情加载失败') })
    }, 0)
    return () => { active = false; window.clearTimeout(timer) }
  }, [selectedId, revision])

  useEffect(() => {
    if (!rows.some(row => ['queued', 'processing', 'waiting'].includes(row.provisioning_status ?? '') || ['queued', 'processing', 'waiting'].includes(row.lifecycle_status ?? ''))) return
    const timer = window.setInterval(() => { if (document.visibilityState === 'visible') setRevision(value => value + 1) }, 5000)
    return () => window.clearInterval(timer)
  }, [rows])

  const columns: TableColumnProps<ServiceInstance>[] = [
    { title: '序号', width: 76, render: (_, __, index) => (page - 1) * pageSize + index + 1 },
    { title: '实例编号', dataIndex: 'instance_no', width: 210, ellipsis: true, render: (_, row) =>
      <Link title={row.instance_no} onClick={() => setSelectedId(row.id)}>{row.instance_no}</Link> },
    { title: '实例名称', dataIndex: 'display_name', width: 180, ellipsis: true, render: tableCellText },
    { title: '会员昵称', dataIndex: 'member_nickname', width: 150, ellipsis: true, render: tableCellText },
    { title: '会员手机号', dataIndex: 'member_mobile', width: 160, ellipsis: true, render: tableCellText },
    { title: '来源订单', dataIndex: 'order_no', width: 200, ellipsis: true, render: tableCellText },
    { ...filterColumn('status', enumLabels(STATUSES)), title: '状态', width: 110, render: (_, row) => <StatusBadge value={row.status} mapping={STATUSES} /> },
    { title: '开通状态', width: 170, render: (_, row) => row.provisioning_status ? deliveryLabels[row.provisioning_status] ?? row.provisioning_status : '—' },
    { title: '电源状态', width: 130, render: (_, row) => powerLabels[row.power_status ?? 'unknown'] ?? '未知状态' },
    { title: '续费与到期处理', width: 200, render: (_, row) => lifecycleLabel(row.lifecycle_action, row.lifecycle_status) },
    { ...sortColumn('activated_at'), title: '开通时间', dataIndex: 'activated_at', width: 180, render: tableCellText },
    { ...sortColumn('expires_at'), title: '到期时间', dataIndex: 'expires_at', width: 180, render: tableCellText },
    { ...sortColumn('created_at'), title: '创建时间', dataIndex: 'created_at', width: 180, render: tableCellText },
    { title: '操作', width: 114, fixed: 'right', render: (_, row) =>
      <TableActions actions={[...(productType === 'cloud_server' && row.provisioning_status === 'succeeded' ? [{ key: 'operations', label: '管理', onClick: () => setOperation(row.id) }] : []),...(permissions.includes('admin.services.cloud-servers.renew') && row.can_renew ? [{ key: 'renew', label: '续费', onClick: () => setRenewal(row.id) }] : []), ...(row.renewal_order_id && row.renewal_payment_pending && permissions.includes('admin.page.order-list.view') ? [{ key: 'renewal-order', label: '续费订单', onClick: () => history.push(`/orders?detail=${row.renewal_order_id}`) }] : []), ...(row.lifecycle_status === 'attention' && permissions.includes('admin.services.cloud-servers.lifecycle') ? [{ key: 'lifecycle', label: '重试处理', onClick: () => Modal.confirm({ title: '重新查询实例任务？', content: '已受理的操作只查询原请求，不会重复续费、开关机、重启、重装或重置密码。', maskClosable: true, onOk: async () => { try { await retryServiceLifecycle(row.id); Message.success('已重新排队'); setRevision(value => value + 1) } catch (failure) { Message.error(failure instanceof Error ? failure.message : '重试失败'); throw failure } } }) }] : []), { key: 'detail', label: '详情', onClick: () => setSelectedId(row.id) }, ...(permissions.includes('admin.services.cloud-servers.provision') && ['failed', 'attention'].includes(row.provisioning_status ?? '') ? [{ key: 'retry', label: '重试开通', onClick: () => retry(row.id) }] : []), ...(permissions.includes('admin.services.cloud-servers.resolve') && row.can_confirm_not_created ? [{ key: 'resolve', label: '确认未开通', sensitive: true, onClick: () => { setReason(''); setEvidenceReference(''); setResolution(row.id) } }] : [])]} /> },
  ]

  const detailRows = detail ? [
    ['处理说明', ({ provider_result_unknown: '供应商结果尚未确认', awaiting_provider: '等待供应商确认开通结果', provider_rejected: '供应商确认未创建实例', operator_confirmed_not_created: '管理员已核查未创建实例', adapter_changed: '原供应插件已变化，请恢复原插件或核查实例', provider_unavailable: '原供应插件不可用', credential_unavailable: '开通所需凭据不可用', external_identity_conflict: '供应实例标识不一致，需核查', resource_requires_review: '已有供应实例，需在供应商核查' } as Record<string, string>)[detail.provisioning_error ?? ''] ?? detail.provisioning_error],
    ['续费与到期处理', lifecycleLabel(detail.lifecycle_action, detail.lifecycle_status)], ['处理说明', detail.lifecycle_error ? lifecycleErrors[detail.lifecycle_error] ?? '需管理员核查处理结果' : undefined],
    ['开通状态', detail.provisioning_status ? deliveryLabels[detail.provisioning_status] : '—'], ['执行次数', detail.provisioning_attempts?.toString()], ['IP 地址', detail.addresses?.join('、')],
    ['核查凭证', detail.not_created_reference], ['核查说明', detail.not_created_reason], ['核查人 ID', detail.not_created_by?.toString()], ['核查时间', detail.not_created_at],
    ['实例编号', detail.instance_no], ['实例名称', detail.display_name],
    ['产品类型', TITLES[detail.product_type]], ['状态', STATUSES[detail.status]?.text ?? detail.status],
    ['会员昵称', detail.member_nickname], ['会员手机号', detail.member_mobile],
    ['来源订单', detail.order_no], ['开通时间', detail.activated_at],
    ['到期时间', detail.expires_at], ['创建时间', detail.created_at],
  ].map(([label, value], index) => ({ key: String(index), label, value: tableCellText(value) })) : []

  return <section className="page-card">
    {vnc !== undefined && <VncConsoleModal id={vnc} close={() => setVnc(undefined)} />}
    {operation !== undefined && <InstanceOperationsModal id={operation} openVnc={() => { setVnc(operation); setOperation(undefined) }} close={() => setOperation(undefined)} changed={() => setRevision(value => value + 1)} />}
    {renewal !== undefined && <ServiceRenewalModal id={renewal} close={() => setRenewal(undefined)} done={orderId => { setRenewal(undefined); setRevision(value => value + 1); if (permissions.includes('admin.page.order-list.view')) history.push(`/orders?detail=${orderId}`) }} />}
    <Modal title="确认供应商未创建实例" visible={resolution !== undefined} maskClosable={!resolving} confirmLoading={resolving} onCancel={() => { if (!resolving) setResolution(undefined) }} onOk={async () => {
      if (reason.trim().length < 5 || !evidenceReference.trim() || resolution === undefined) return
      setResolving(true)
      try { await confirmServiceNotCreated(resolution, reason.trim(), evidenceReference.trim()); setResolution(undefined); setRevision(value => value + 1); Message.success('已确认未开通，可按原订单重试或申请退款') } catch (error) { Message.error(error instanceof Error ? error.message : '确认失败'); throw error } finally { setResolving(false) }
    }} okButtonProps={{ disabled: reason.trim().length < 5 || !evidenceReference.trim() }}>
      <Alert type="warning" content="请先在资源供应商核实该请求没有创建实例。确认后允许重新开通或申请退款；已有供应实例时请勿确认。" />
      <Form layout="vertical"><Form.Item label="核查凭证编号" required><Input value={evidenceReference} onChange={setEvidenceReference} maxLength={160} placeholder="供应商工单、日志或核查记录编号" /></Form.Item><Form.Item label="核查说明" required validateStatus={reason && reason.trim().length < 5 ? 'error' : undefined} help={reason && reason.trim().length < 5 ? '请填写至少5个字符的核查说明' : undefined}><Input.TextArea maxLength={500} value={reason} onChange={setReason} /></Form.Item></Form>
    </Modal>
    <TableQueryForm fields={searchFields} values={draftFields} onFieldChange={setField}
      appliedCount={Object.keys(queryParams).filter(key => !['sort_by', 'sort_order'].includes(key)).length} onCancelFilters={discard}
      onSearch={search} onReset={reset} loading={loading}>

      <Form.Item label="状态" className="table-query-field" style={{ marginBottom: 0, marginRight: 0, minWidth: 0, maxWidth: '100%' }}><Select placeholder="全部状态" allowClear value={draftStatus} onChange={setDraftStatus}
        options={Object.entries(STATUSES).map(([value, item]) => ({ value, label: item.text }))} style={{ width: 140 }} /></Form.Item>
      </TableQueryForm>
    <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 16 }}><Button icon={<IconRefresh />} onClick={() => setRevision(value => value + 1)} loading={loading}>刷新</Button></div>
    {error && <Alert type="error" content={error} style={{ marginBottom: 16 }} />}
    <div className="table-scroll"><Table<ServiceInstance> onChange={onTableChange} rowKey="id" columns={columns} data={rows} loading={loading}
      scroll={{ x: 1716 }} pagination={{ current: page, pageSize, total, showTotal: true,
        sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: setPageSize }} /></div>
    <Drawer maskClosable title={`${TITLES[productType]}详情`} visible={selectedId !== null} width="min(760px, calc(100vw - 24px))" footer={null}
      onCancel={() => { setSelectedId(null); setDetail(null) }}>
      {detailError && <Alert type="error" content={detailError} />}
      {detail && <Descriptions title="实例信息" data={detailRows} column={DESCRIPTION_COLUMNS} border />}
    </Drawer>
  </section>
}
