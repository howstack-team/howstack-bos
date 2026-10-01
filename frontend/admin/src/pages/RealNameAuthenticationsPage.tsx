import { useCallback, useEffect, useState } from 'react'
import { Descriptions, Drawer, Input, Link, Message, Modal, Select, Space, Table, Tag } from '@arco-design/web-react'
import type { TableColumnProps } from '@arco-design/web-react'
import { realNameAuthenticationApi } from '../api/realNameAuthenticationApi.ts'
import type { AuthenticationStatus, AuthenticationType, RealNameAuthenticationRecord } from '../api/realNameAuthenticationApi.ts'
import { useAdminAuth } from '../auth/AuthContext.ts'
import TableActions from '../components/TableActions.tsx'
import type { TableAction } from '../components/TableActions.tsx'
import { tableCellText } from '../utils/tableCellText.ts'
import { DEFAULT_TABLE_PAGE_SIZE, TABLE_PAGE_SIZE_OPTIONS } from '../utils/tablePagination.ts'

const DESCRIPTION_COLUMNS = { xs: 1, sm: 1, md: 2, lg: 2, xl: 2, xxl: 2, xxxl: 2 }
const typeText = (type: AuthenticationType) => type === 'personal' ? '个人认证' : '企业认证'
const statusMeta: Record<AuthenticationStatus, { text: string; color: string }> = {
  pending: { text: '待审核', color: 'orange' }, approved: { text: '已通过', color: 'green' }, rejected: { text: '已拒绝', color: 'red' },
}

/** 实名认证历史、升级关系及人工审核页面。 */
export default function RealNameAuthenticationsPage() {
  const { permissions } = useAdminAuth()
  const [rows, setRows] = useState<RealNameAuthenticationRecord[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_TABLE_PAGE_SIZE)
  const [input, setInput] = useState('')
  const [keyword, setKeyword] = useState('')
  const [type, setType] = useState<AuthenticationType | undefined>()
  const [status, setStatus] = useState<AuthenticationStatus | undefined>()
  const [loading, setLoading] = useState(false)
  const [detail, setDetail] = useState<RealNameAuthenticationRecord | null>(null)
  const [rejecting, setRejecting] = useState<RealNameAuthenticationRecord | null>(null)
  const [reason, setReason] = useState('')
  const [reviewing, setReviewing] = useState(false)
  const canReview = permissions.includes('admin.real-name-authentications.review')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await realNameAuthenticationApi.list({ keyword: keyword || undefined, type, status, page, page_size: pageSize })
      setRows(result.data); setTotal(result.total)
    } catch (error) { Message.error(error instanceof Error ? error.message : '实名认证记录加载失败') } finally { setLoading(false) }
  }, [keyword, page, pageSize, status, type])

  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(timer) }, [load])

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
    { title: '提交时间', dataIndex: 'submitted_at', width: 180, render: (_, row) => <Link href="#" onClick={(event) => { event.preventDefault(); void openDetail(row.id) }}>{row.submitted_at}</Link> },
    { title: '会员手机号', dataIndex: 'mobile', width: 150, ellipsis: true, render: tableCellText },
    { title: '会员昵称', dataIndex: 'nickname', width: 140, ellipsis: true, render: tableCellText },
    { title: '认证类型', width: 110, render: (_, row) => typeText(row.authentication_type) },
    { title: '认证主体', width: 180, ellipsis: true, render: (_, row) => tableCellText(row.company_name || row.subject_name) },
    { title: '证件号码', dataIndex: 'id_number_masked', width: 180, render: tableCellText },
    { title: '状态', width: 100, render: (_, row) => <Tag color={statusMeta[row.status].color}>{statusMeta[row.status].text}</Tag> },
    { title: '审核时间', dataIndex: 'reviewed_at', width: 180, render: tableCellText },
    { title: '操作', width: 180, fixed: 'right', render: (_, row) => {
      const actions: TableAction[] = [{ key: 'detail', label: '详情', onClick: () => void openDetail(row.id) }]
      if (canReview && row.status === 'pending') {
        actions.push({ key: 'approve', label: '通过', onClick: () => Modal.confirm({ title: '通过实名认证？', content: '通过后该记录将成为会员有效认证历史。', onOk: () => review(row, 'approved') }) })
        actions.push({ key: 'reject', label: '拒绝', sensitive: true, danger: true, onClick: () => { setReason(''); setRejecting(row) } })
      }
      return <TableActions actions={actions} />
    } },
  ]

  const descriptions = detail ? [
    { label: '会员手机号', value: detail.mobile }, { label: '会员昵称', value: detail.nickname },
    { label: '认证类型', value: typeText(detail.authentication_type) }, { label: '认证状态', value: statusMeta[detail.status].text },
    { label: detail.authentication_type === 'personal' ? '真实姓名' : '企业名称', value: detail.company_name || detail.subject_name },
    { label: '证件类型', value: detail.id_type === 'identity_card' ? '居民身份证' : '护照' },
    { label: '证件号码', value: detail.id_number_masked }, { label: '法定代表人', value: detail.legal_representative },
    { label: '统一社会信用代码', value: detail.registration_code_masked },
    { label: '升级来源记录', value: detail.upgrade_from_id ? `#${detail.upgrade_from_id}（个人认证升级）` : '-' },
    { label: '提交时间', value: detail.submitted_at }, { label: '审核时间', value: detail.reviewed_at },
    { label: '审核人 ID', value: detail.reviewed_by }, { label: '拒绝原因', value: detail.rejection_reason },
  ].map((item, index) => ({ key: String(index), label: item.label, value: tableCellText(item.value) })) : []

  return <section className="page-card">
    <div className="page-toolbar"><Space wrap>
      <Input.Search value={input} allowClear placeholder="搜索会员或认证主体" style={{ width: 250, maxWidth: '100%' }} onChange={setInput}
        onSearch={() => { setPage(1); setKeyword(input.trim()) }} searchButton="查询" />
      <Select value={type} allowClear placeholder="全部类型" style={{ width: 130 }} onChange={(value) => { setPage(1); setType(value) }}
        options={[{ label: '个人认证', value: 'personal' }, { label: '企业认证', value: 'enterprise' }]} />
      <Select value={status} allowClear placeholder="全部状态" style={{ width: 130 }} onChange={(value) => { setPage(1); setStatus(value) }}
        options={[{ label: '待审核', value: 'pending' }, { label: '已通过', value: 'approved' }, { label: '已拒绝', value: 'rejected' }]} />
    </Space></div>
    <div className="table-scroll"><Table rowKey="id" columns={columns} data={rows} loading={loading} scroll={{ x: 1480 }}
      pagination={{ current: page, pageSize, total, showTotal: true, sizeCanChange: true, sizeOptions: TABLE_PAGE_SIZE_OPTIONS,
        onChange: setPage, onPageSizeChange: (size) => { setPage(1); setPageSize(size) } }} /></div>
    <Drawer title="实名认证详情" visible={detail !== null} width="min(760px, 100vw)" onCancel={() => setDetail(null)} footer={null}>
      {detail && <Descriptions title="认证信息" column={DESCRIPTION_COLUMNS} border labelStyle={{ width: 132 }} data={descriptions} />}
    </Drawer>
    <Modal title="拒绝实名认证" visible={rejecting !== null} onCancel={() => setRejecting(null)} confirmLoading={reviewing}
      onOk={() => { if (!reason.trim()) { Message.warning('请输入拒绝原因'); return } if (rejecting) void review(rejecting, 'rejected', reason.trim()) }}
      style={{ width: 520, maxWidth: 'calc(100vw - 24px)' }}>
      <Input.TextArea value={reason} onChange={setReason} placeholder="请输入拒绝原因" maxLength={255} showWordLimit />
    </Modal>
  </section>
}
