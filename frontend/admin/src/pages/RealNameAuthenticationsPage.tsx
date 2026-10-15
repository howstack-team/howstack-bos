import { StatusBadge } from '../components/TableEnum'
import { AUTHENTICATION_TYPES, AUTHENTICATION_STATUSES as statusMeta } from '../utils/businessEnums'
import { enumLabels, enumMeta, enumText } from '../utils/tableEnum'
import useTableQuery from '../hooks/useTableQuery'
import TableQueryForm from '../components/TableQueryForm'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Form, Descriptions, Drawer, Input, Link, Message, Modal, Select, Table } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { realNameAuthenticationApi } from '../api/realNameAuthenticationApi.ts'
import type { AuthenticationStatus, AuthenticationType, RealNameAuthenticationRecord } from '../api/realNameAuthenticationApi.ts'
import { useAdminAuth } from '../auth/AuthContext.ts'
import TableActions from '../components/TableActions.tsx'
import type { TableAction } from '../components/TableActions.tsx'
import { tableCellText } from '../utils/tableCellText.ts'
import { TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

const DESCRIPTION_COLUMNS = { xs: 1, sm: 1, md: 2, lg: 2, xl: 2, xxl: 2, xxxl: 2 }
const typeText = (type: AuthenticationType) => enumMeta(AUTHENTICATION_TYPES, type)?.text ?? type

/** 实名认证历史、升级关系及人工审核页面。 */
export default function RealNameAuthenticationsPage() {
  const searchFields = [
    { key: 'mobile', label: '会员手机号', maxLength: 20 },
    { key: 'subject', label: '认证主体', maxLength: 128 },
    { key: 'nickname', label: '会员昵称', maxLength: 64 },
  ]
  const { queryParams, sortColumn, filterColumn, onTableChange, draftFields, setField, discard, page, pageSize, setPage, setPageSize, search, reset, refreshKey, status: appliedStatus, draftStatus, setDraftStatus, type: appliedType, draftType, setDraftType } = useTableQuery({ sortFields: ['submitted_at', 'reviewed_at'], textFields: searchFields.map(field => field.key), statusValues: Object.keys(statusMeta), typeValues: ['personal', 'enterprise'] })
  const status = appliedStatus as AuthenticationStatus | undefined
  const type = appliedType as AuthenticationType | undefined
  const { permissions } = useAdminAuth()
  const [rows, setRows] = useState<RealNameAuthenticationRecord[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [detail, setDetail] = useState<RealNameAuthenticationRecord | null>(null)
  const [rejecting, setRejecting] = useState<RealNameAuthenticationRecord | null>(null)
  const [reason, setReason] = useState('')
  const [reviewing, setReviewing] = useState(false)
  const canReview = permissions.includes('admin.real-name-authentications.review')

  const listRequest = useRef(0)
  const invalidateList = useCallback(() => { listRequest.current++ }, [])
  const load = useCallback(async () => {
    const request = ++listRequest.current
    setLoading(true)
    try {
      const result = await realNameAuthenticationApi.list({ ...queryParams, type, status, page, page_size: pageSize })
      if (request !== listRequest.current) return
      setRows(result.data); setTotal(result.total)
    } catch (error) {
      if (request !== listRequest.current) return
      Message.error(error instanceof Error ? error.message : '实名认证记录加载失败') } finally { if (request === listRequest.current) setLoading(false) }
  }, [queryParams, page, pageSize, status, type])

  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => { invalidateList(); window.clearTimeout(timer) } }, [load, refreshKey, invalidateList])

  async function openDetail(id: number) {
    try { setDetail(await realNameAuthenticationApi.get(id)) } catch (error) { Message.error(error instanceof Error ? error.message : '详情加载失败') }
  }

  async function review(row: RealNameAuthenticationRecord, decision: 'approved' | 'rejected', reviewReason = '') {
    setReviewing(true)
    try {
      await realNameAuthenticationApi.review(row.id, decision, reviewReason)
      Message.success(decision === 'approved' ? '认证已通过' : '认证已拒绝')
      setRejecting(null); setReason(''); if (detail?.id === row.id) setDetail(null); await load()
    } catch (error) { Message.error(error instanceof Error ? error.message : '审核失败') } finally { setReviewing(false) }
  }

  const columns: TableColumnProps<RealNameAuthenticationRecord>[] = [
    { title: '序号', width: 76, render: (_, __, index) => (page - 1) * pageSize + index + 1 },
    { ...sortColumn('submitted_at'), title: '提交时间', dataIndex: 'submitted_at', width: 180, render: (_, row) => <Link href="#" onClick={(event) => { event.preventDefault(); void openDetail(row.id) }}>{row.submitted_at}</Link> },
    { title: '会员手机号', dataIndex: 'mobile', width: 150, ellipsis: true, render: tableCellText },
    { title: '会员昵称', dataIndex: 'nickname', width: 140, ellipsis: true, render: tableCellText },
    { ...filterColumn('type', enumLabels(AUTHENTICATION_TYPES)), title: '认证类型', width: 110, render: (_, row) => enumText(AUTHENTICATION_TYPES, row.authentication_type) },
    { title: '认证主体', width: 180, ellipsis: true, render: (_, row) => tableCellText(row.company_name || row.subject_name) },
    { title: '证件号码', dataIndex: 'id_number_masked', width: 180, render: tableCellText },
    { ...filterColumn('status', enumLabels(statusMeta)), title: '状态', width: 100, render: (_, row) => <StatusBadge value={row.status} mapping={statusMeta} /> },
    { ...sortColumn('reviewed_at'), title: '审核时间', dataIndex: 'reviewed_at', width: 180, render: tableCellText },
    { title: '操作', width: 220, fixed: 'right', render: (_, row) => {
      const actions: TableAction[] = [{ key: 'detail', label: '详情', onClick: () => void openDetail(row.id) }]
      if (canReview && row.status === 'pending') {
        actions.push({ key: 'approve', label: '通过', onClick: () => Modal.confirm({ maskClosable: true, title: '通过实名认证？', content: '通过后该记录将成为会员有效认证历史。', onOk: () => review(row, 'approved') }) })
        actions.push({ key: 'reject', label: '拒绝', sensitive: true, danger: true, onClick: () => { setReason(''); setRejecting(row) } })
      }
      return <TableActions actions={actions} />
    } },
  ]

  const descriptions = detail ? [
    { label: '会员手机号', value: detail.mobile }, { label: '会员昵称', value: detail.nickname },
    { label: '认证类型', value: typeText(detail.authentication_type) }, { label: '认证状态', value: enumMeta(statusMeta, detail.status)?.text ?? '未知状态' },
    { label: detail.authentication_type === 'personal' ? '真实姓名' : '企业名称', value: detail.company_name || detail.subject_name },
    { label: '证件类型', value: detail.id_type === 'identity_card' ? '居民身份证' : '护照' },
    { label: '证件号码', value: detail.id_number_masked }, { label: '法定代表人', value: detail.legal_representative },
    { label: '统一社会信用代码', value: detail.registration_code_masked },
    { label: '升级来源记录', value: detail.upgrade_from_id ? `#${detail.upgrade_from_id}（个人认证升级）` : '-' },
    { label: '提交时间', value: detail.submitted_at }, { label: '审核时间', value: detail.reviewed_at },
    { label: '审核人 ID', value: detail.reviewed_by }, { label: '拒绝原因', value: detail.rejection_reason },
  ].map((item, index) => ({ key: String(index), label: item.label, value: tableCellText(item.value) })) : []

  return <section className="page-card">
    <TableQueryForm fields={searchFields} values={draftFields} onFieldChange={setField}
      appliedCount={Object.keys(queryParams).filter(key => !['sort_by', 'sort_order'].includes(key)).length} onCancelFilters={discard}
      onSearch={search} onReset={reset} loading={loading}>

      <Form.Item label="类型" className="table-query-field" style={{ marginBottom: 0, marginRight: 0, minWidth: 0, maxWidth: '100%' }}><Select value={draftType} allowClear placeholder="全部类型" style={{ width: 130 }} onChange={setDraftType}
        options={Object.entries(AUTHENTICATION_TYPES).map(([value, item]) => ({ label: item.text, value }))} /></Form.Item>
      <Form.Item label="状态" className="table-query-field" style={{ marginBottom: 0, marginRight: 0, minWidth: 0, maxWidth: '100%' }}><Select value={draftStatus} allowClear placeholder="全部状态" style={{ width: 130 }} onChange={setDraftStatus}
        options={Object.entries(statusMeta).map(([value, item]) => ({ label: item.text, value }))} /></Form.Item>
    </TableQueryForm>
    <div className="table-scroll"><Table onChange={onTableChange} rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: 1480 }}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: setPageSize }} /></div>
    <Drawer maskClosable title="实名认证详情" visible={detail !== null} width="min(760px, calc(100vw - 24px))" onCancel={() => setDetail(null)} footer={null}>
      {detail && <Descriptions title="认证信息" column={DESCRIPTION_COLUMNS} border labelStyle={{ width: 132 }} data={descriptions} />}
    </Drawer>
    <Modal maskClosable title="拒绝实名认证" visible={rejecting !== null} onCancel={() => setRejecting(null)} confirmLoading={reviewing}
      onOk={() => { if (!reason.trim()) { Message.warning('请输入拒绝原因'); return } if (rejecting) void review(rejecting, 'rejected', reason.trim()) }}
      style={{ width: 520, maxWidth: 'calc(100vw - 24px)' }}>
      <Input.TextArea value={reason} onChange={setReason} placeholder="请输入拒绝原因" maxLength={255} showWordLimit />
    </Modal>
  </section>
}
