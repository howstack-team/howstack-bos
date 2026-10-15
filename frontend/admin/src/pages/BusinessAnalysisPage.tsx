import { DEFAULT_TABLE_PAGE_SIZE } from '../utils/tablePagination'
import { useMemo, useState } from 'react'
import { Alert, Button, Card, Collapse, DatePicker, Empty, Form, Radio, Skeleton, Space, Statistic, Table, Tooltip, Typography } from '@arco-design/web-react'
import { IconInfoCircle, IconRefresh, IconSearch } from '@arco-design/web-react/icon'
import { useHistory, useLocation } from 'react-router-dom'
import { useAdminAuth } from '../auth/AuthContext'
import type { AnalysisSummary, BusinessAnalysisQuery } from '../api/businessAnalysisApi'
import { useCompactFormViewport } from '../hooks/useCompactFormLayout'
import { useBusinessAnalysis } from '../hooks/useBusinessAnalysis'
import AnalysisChart from '../components/AnalysisChart'
import { StatusBadge } from '../components/TableEnum'
import { ORDER_STATUSES, ORDER_TYPES } from '../utils/businessEnums'
import { analysisToday, analysisChange, validateAnalysisQuery, type AnalysisQueryErrors } from '../utils/businessAnalysis'
import { analysisTrendSpec, analysisStatusSpec } from '../utils/businessAnalysisCharts'
import { formatMoneyMinor, minorToYuanText } from '../utils/money'
import './BusinessAnalysisPage.css'

const metrics: Array<{ key: keyof AnalysisSummary; title: string; money?: boolean; description: string }> = [
  { key: 'paid_amount_minor', title: '订单实付', money: true, description: '按付款时间累计订单实付，后续退款不抹去原付款事实。' },
  { key: 'refund_amount_minor', title: '已完成退款', money: true, description: '按退款完成时间统计，包含更早支付订单的退款；未执行和失败退款不计入。' },
  { key: 'net_paid_amount_minor', title: '净实付', money: true, description: '本期实付减本期已完成退款，允许负数，不代表利润、会计收入或现金流。' },
  { key: 'average_paid_order_minor', title: '平均实付客单价', money: true, description: '本期实付除付费订单数，四舍五入到分；没有付费订单时为零。' },
  { key: 'created_orders', title: '创建订单', description: '按创建时间统计的人民币订单，包含试用、未付款和取消订单。' },
  { key: 'paid_orders', title: '付费订单', description: '按付款时间统计有实付的订单，与创建订单属于不同时间口径，不能直接计算转化率。' },
  { key: 'paid_members', title: '付费会员', description: '在整个统计区间内按会员去重，同一会员跨日多单只计一次。' },
  { key: 'new_members', title: '新增会员', description: '按会员创建时间统计，包含当前已禁用会员。' },
]
function heading(text: string) { return <Typography.Title heading={6} style={{ margin: 0 }}>{text}</Typography.Title> }

/** 只读全站经营分析；独立页面权限，URL保存已应用查询，真实统计按服务端口径展示。 */
export default function BusinessAnalysisPage() {
  const { user, permissions } = useAdminAuth()
  const desktop = useCompactFormViewport()
  const history = useHistory(), location = useLocation()
  const raw = useMemo(() => {
    const params = new URLSearchParams(location.search)
    return { start_date: params.has('start_date') ? params.get('start_date')! : undefined,
      end_date: params.has('end_date') ? params.get('end_date')! : undefined, granularity: params.get('granularity') ?? 'day' }
  }, [location.search])
  const [timezone, setTimezone] = useState('Asia/Shanghai')
  const today = analysisToday(timezone)
  const urlErrors = validateAnalysisQuery(raw, today)
  const [revision, setRevision] = useState(0)
  const query: BusinessAnalysisQuery = { ...raw, granularity: raw.granularity === 'month' ? 'month' : 'day' }
  const { data, error, loading, fieldErrors } = useBusinessAnalysis(query, user?.id,
    permissions.includes('admin.page.analysis.view') && Object.keys(urlErrors).length === 0, revision)
  // 响应时区是统计口径；通过显式查询事件更新，不在渲染阶段写状态。
  const businessTimezone = data?.period.timezone ?? timezone
  const businessToday = analysisToday(businessTimezone)
  const [draft, setDraft] = useState<{ search: string; range: string[]; granularity: string } | null>(null)
  const range = draft?.search === location.search ? draft.range : [raw.start_date ?? data?.period.start_date ?? '', raw.end_date ?? data?.period.end_date ?? '']
  const granularity = draft?.search === location.search ? draft.granularity : raw.granularity
  const [validation, setValidation] = useState<{ search: string; errors: AnalysisQueryErrors } | null>(null)
  const errors = { ...urlErrors, ...fieldErrors, ...(validation?.search === location.search ? validation.errors : {}) }
  const dateError = [errors.start_date, errors.end_date].filter(Boolean).join('；')
  const trendSpec = useMemo(() => data ? analysisTrendSpec(data) : null, [data])
  const statusSpec = useMemo(() => data ? analysisStatusSpec(data) : null, [data])
  function updateRange(next: string[]) { setDraft({ search: location.search, range: next, granularity }); setValidation(null) }
  function submit() {
    const next = { start_date: range[0] ?? '', end_date: range[1] ?? '', granularity }
    const problems = validateAnalysisQuery(next, businessToday)
    setValidation({ search: location.search, errors: problems })
    if (Object.keys(problems).length) return
    setTimezone(businessTimezone)
    const params = new URLSearchParams(location.search)
    Object.entries(next).forEach(([key, value]) => params.set(key, value))
    const search = `?${params.toString()}`
    setDraft(null)
    if (search === location.search) setRevision(value => value + 1)
    else history.push({ ...location, search })
  }
  function reset() {
    const params = new URLSearchParams(location.search)
    for (const key of ['start_date', 'end_date', 'granularity']) params.delete(key)
    const search = params.size ? `?${params}` : ''
    setDraft(null); setValidation(null); setTimezone(businessTimezone)
    if (search === location.search) setRevision(value => value + 1)
    else history.push({ ...location, search })
  }
  return <div className="analysis-page">
    <div className="analysis-heading"><div><Typography.Title heading={4} style={{ margin: '0 0 6px' }}>经营分析</Typography.Title>
      <Typography.Text type="secondary">查看全站订单与会员的经营数据</Typography.Text></div>
      <Button icon={<IconRefresh />} loading={loading} onClick={() => setRevision(value => value + 1)} disabled={Object.keys(urlErrors).length > 0}>刷新数据</Button></div>
    <Card className="analysis-card"><Form layout={desktop ? 'inline' : 'vertical'} className="analysis-query" aria-label="经营分析查询" onSubmit={submit}>
      <Form.Item label="日期范围" validateStatus={dateError ? 'error' : undefined} help={dateError || undefined} style={{ margin: 0, minWidth: 0, maxWidth: '100%' }}>
        {desktop ? <DatePicker.RangePicker value={range} format="YYYY-MM-DD" placeholder={['开始日期', '结束日期']} allowClear disabled={loading}
          onChange={updateRange} disabledDate={date => date.format('YYYY-MM-DD') > businessToday} style={{ width: 300, maxWidth: '100%' }} />
          : <div className="analysis-date-range">{['开始日期', '结束日期'].map((placeholder, index) => <DatePicker key={placeholder}
            value={range[index]} format="YYYY-MM-DD" placeholder={placeholder} allowClear disabled={loading} style={{ width: '100%', minWidth: 0 }}
            disabledDate={date => date.format('YYYY-MM-DD') > businessToday}
            onChange={value => updateRange(index === 0 ? [value, range[1] ?? ''] : [range[0] ?? '', value])} />)}</div>}

      </Form.Item>
      <Form.Item label="趋势粒度" validateStatus={errors.granularity ? 'error' : undefined} help={errors.granularity} style={{ margin: 0 }}>
        <Radio.Group type="button" value={granularity} disabled={loading} options={[{ label: '按日', value: 'day' }, { label: '按月', value: 'month' }]}
          onChange={value => { setDraft({ search: location.search, range, granularity: value }); setValidation(null) }} />
      </Form.Item>
      <Form.Item className="analysis-query-actions" style={{ margin: 0, marginLeft: 'auto' }}><Space>
        <Button type="primary" htmlType="submit" icon={<IconSearch />} loading={loading}>查询</Button><Button icon={<IconRefresh />} onClick={reset} disabled={loading}>重置</Button>
      </Space></Form.Item>
    </Form></Card>
    {error && <Alert type="error" title="经营分析加载失败" content={error} action={<Button size="small" onClick={() => setRevision(value => value + 1)}>重试</Button>} />}
    {Object.keys(urlErrors).length > 0 && <Alert type="warning" content="查询条件无效，请调整日期范围或趋势粒度后重新查询。" />}
    {loading && <Card className="analysis-card" aria-label="经营分析加载中"><Skeleton text={{ rows: 5 }} animation /></Card>}
    {data && <>
      <div className="analysis-period" aria-label="统计范围"><span>统计范围：{data.period.start_date} 至 {data.period.end_date}</span>
        <span>前期：{data.period.previous_start_date} 至 {data.period.previous_end_date}</span><span>业务时区：{data.period.timezone}</span>
        <span>查询时间：{data.period.generated_at}</span></div>
      {data.period.end_date === businessToday && <Alert type="info" content="统计范围包含今天，当天数据尚未完整。" />}
      {data.period.granularity === 'month' && <Alert type="info" content="月度趋势只汇总所选日期覆盖的部分月份，不自动扩大为完整月份。" />}
      <section aria-label="经营指标" className="analysis-metrics">{metrics.map(metric => <Card className="analysis-card" key={metric.key}>
        <div className="analysis-metric-title"><span>{metric.title}</span><Tooltip content={metric.description}><span tabIndex={0} aria-label={`${metric.title}统计口径`}><IconInfoCircle /></span></Tooltip></div>
        <Statistic value={metric.money ? minorToYuanText(data.summary[metric.key]) : data.summary[metric.key]}
          prefix={metric.money ? '¥' : undefined} suffix={metric.money ? '元' : undefined} groupSeparator styleValue={{ fontSize: 26 }} />
        <div className="analysis-change"><Tooltip content={`前期：${metric.money ? formatMoneyMinor(data.previous_summary[metric.key]) : data.previous_summary[metric.key]}。以前期绝对值为基数计算变化。`}>
          <span>较前期 {analysisChange(data.summary[metric.key], data.previous_summary[metric.key])}</span></Tooltip></div>
      </Card>)}</section>
      <section aria-label="实付与退款趋势"><Card className="analysis-card" title={heading('实付与退款趋势')} extra={<Typography.Text type="secondary">{data.period.granularity === 'day' ? '按日' : '按月'} · 人民币元</Typography.Text>}>
        <AnalysisChart spec={trendSpec!} label="订单实付、已完成退款及净实付趋势" />
        <Collapse bordered={false}><Collapse.Item name="trend" header="查看趋势数据"><Table rowKey="date" data={data.trend} scroll={{ x: 980 }} pagination={{ defaultPageSize: DEFAULT_TABLE_PAGE_SIZE }} columns={[
          { title: '日期', dataIndex: 'date', width: 120 },
          { title: '订单实付', width: 150, sorter: (a, b) => a.paid_amount_minor - b.paid_amount_minor, render: (_, row) => formatMoneyMinor(row.paid_amount_minor) },
          { title: '已完成退款', width: 150, sorter: (a, b) => a.refund_amount_minor - b.refund_amount_minor, render: (_, row) => formatMoneyMinor(row.refund_amount_minor) },
          { title: '净实付', width: 150, sorter: (a, b) => a.net_paid_amount_minor - b.net_paid_amount_minor, render: (_, row) => formatMoneyMinor(row.net_paid_amount_minor) },
          { title: '创建订单', dataIndex: 'created_orders', width: 130 }, { title: '付费订单', dataIndex: 'paid_orders', width: 130 }, { title: '新增会员', dataIndex: 'new_members', width: 130 },
        ]} /></Collapse.Item></Collapse>
      </Card></section>
      <div className="analysis-distributions">
        <section aria-label="订单类型"><Card className="analysis-card" title={heading('订单类型')}>
          <Typography.Paragraph type="secondary">创建数按创建时间，付费数与实付按付款时间。</Typography.Paragraph>
          <Table rowKey="order_type" data={data.order_types} pagination={false} scroll={{ x: 550 }} columns={[
            { title: '类型', dataIndex: 'order_type', width: 100, render: value => ORDER_TYPES[value]?.text ?? value },
            { title: '创建订单', dataIndex: 'created_orders', width: 130, sorter: (a, b) => a.created_orders - b.created_orders },
            { title: '付费订单', dataIndex: 'paid_orders', width: 130, sorter: (a, b) => a.paid_orders - b.paid_orders },
            { title: '订单实付', width: 190, sorter: (a, b) => a.paid_amount_minor - b.paid_amount_minor, render: (_, row) => formatMoneyMinor(row.paid_amount_minor) },
          ]} />
        </Card></section>
        <section aria-label="订单状态分布"><Card className="analysis-card" title={heading('订单状态分布')}>
          <Typography.Paragraph type="secondary">本期创建订单的查询时状态，非历史状态快照。</Typography.Paragraph>
          {data.order_statuses.some(row => row.count > 0) ? <AnalysisChart spec={statusSpec!} label="本期创建订单的当前状态分布" /> : <Empty description="暂无订单状态数据" />}
          <Table rowKey="status" data={data.order_statuses} pagination={false} columns={[
            { title: '状态', dataIndex: 'status', render: value => <StatusBadge value={value} mapping={ORDER_STATUSES} /> },
            { title: '订单数量', dataIndex: 'count', width: 130, sorter: (a, b) => a.count - b.count },
          ]} />
        </Card></section>
      </div>
      <Card className="analysis-card"><Typography.Text type="secondary">净实付是本期实付减已完成退款，不代表利润、会计收入或现金流；充值、提现与开票不计入销售额。</Typography.Text></Card>
    </>}
  </div>
}
