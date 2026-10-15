import { useEffect, useRef } from 'react'
import { Button, Card, Form, Input, Link, Message, Modal, Radio, Space, Table, Tag, Typography } from '@arco-design/web-react'
import { IconRefresh } from '@arco-design/web-react/icon'
import { useHistory, useLocation } from 'react-router-dom'
import PageHeading from '../components/PageHeading'
import { useRecharges } from '../hooks/useRecharges'
import { usePaymentQr } from '../hooks/usePaymentQr'
import { rechargeAmount, rechargeLabels, terminalRecharge, type Recharge } from '../api/rechargeApi'
import { minorToYuanText } from '../utils/money'
import { reportPcAuthError } from '../utils/pcAuthFeedback'
import { loginReturnPath } from '../utils/loginReturnPath'
import { paths } from '../routePaths'

interface Fields { amount: string; channel_id: number }
export default function RechargePage() {
  const [form] = Form.useForm<Fields>(), state = useRecharges(), history = useHistory(), location = useLocation()
  const error = state.options.error || state.listing.error || state.detail.error, reported = useRef<unknown>(null)
  useEffect(() => { if (error && error !== reported.current) Message.error(error.message); reported.current = error }, [error])
  const row = state.detail.data, qr = usePaymentQr(row?.qr_code)
  async function submit(values: Fields) {
    const amount = rechargeAmount(values.amount)
    if (amount === null) return
    try { await state.create(values.channel_id, amount) }
    catch (error) { reportPcAuthError(error, fields => form.setFields(fields), ['amount', 'channel_id'], error => error instanceof Error ? error.message : '充值未完成', { amount_minor: 'amount' }) }
  }
  async function check(close = false) { try { await state.check(close) } catch (error) { Message.error(error instanceof Error ? error.message : '支付核查未完成') } }
  const returnPath = location.state && typeof location.state === 'object' && 'from' in location.state ? loginReturnPath(location.state) : paths.wallet
  return <div className="page-stack"><PageHeading title="充值中心" group="财务管理" description="在线充值到账后，可使用余额支付订单。" action={<Button icon={<IconRefresh />} onClick={() => { void state.listing.refetch(); void state.options.refetch() }}>刷新</Button>} />
    <Card title="在线充值" bordered={false} className="console-page-card">
      {state.options.isPending ? <Typography.Text>正在加载支付方式…</Typography.Text> : !state.options.data?.enabled ? <Typography.Text type="secondary">在线充值暂不可用，请联系平台。</Typography.Text> :
        <Form<Fields> form={form} layout="vertical" style={{ maxWidth: 480 }} onSubmit={submit} disabled={state.busy}>
          <Form.Item field="amount" label="充值金额" rules={[{ required: true, message: '请输入充值金额' }, { validator: (value: string | undefined, callback) => { if (rechargeAmount(value ?? '') === null) callback('请输入0.01至1000000元，最多两位小数') } }]}><Input prefix="¥" suffix="元" maxLength={10} placeholder="请输入充值金额" /></Form.Item>
          <Form.Item field="channel_id" label="支付方式" rules={[{ required: true, message: '请选择支付方式' }]}><Radio.Group>{state.options.data.channels.map(channel => <Radio key={channel.id} value={channel.id}>{channel.name}</Radio>)}</Radio.Group></Form.Item>
          <Button type="primary" htmlType="submit" loading={state.busy}>前往付款</Button>
        </Form>}
    </Card>
    <Card title="在线充值记录" bordered={false} className="console-page-card"><Table<Recharge> rowKey="id" loading={state.listing.isFetching} data={state.listing.data?.data ?? []}
      pagination={{ current: state.page, pageSize: 20, total: state.listing.data?.total ?? 0, onChange: state.setPage }} columns={[
        { title: '充值单号', dataIndex: 'recharge_no', ellipsis: true }, { title: '金额', width: 130, render: (_, r) => `¥${minorToYuanText(r.amount_minor)}` },
        { title: '状态', width: 110, render: (_, r) => <Tag color={r.status === 'paid' ? 'green' : r.status === 'unknown' ? 'orange' : undefined}>{rechargeLabels[r.status]}</Tag> },
        { title: '创建时间', dataIndex: 'created_at', width: 180 }, { title: '操作', width: 110, render: (_, r) => <Link onClick={() => state.select(r.id)}>查看付款</Link> },
      ]} /></Card>
    <Modal title="充值付款" visible={state.activeId !== null} maskClosable onCancel={() => state.select(null)} style={{ width: 440, maxWidth: 'calc(100vw - 32px)' }}
      footer={<Space wrap><Button onClick={() => state.select(null)}>关闭</Button>{row && !terminalRecharge(row.status) && <><Button disabled={state.busy} loading={state.busy} onClick={() => { void check(true) }}>取消付款</Button><Button type="primary" disabled={state.busy} loading={state.busy} onClick={() => { void check() }}>查询支付结果</Button></>}{row?.status === 'paid' && <Button type="primary" onClick={() => history.push(returnPath)}>返回继续</Button>}</Space>}>
      {!row ? state.detail.isError ? <Button onClick={() => { void state.detail.refetch() }}>重新加载付款信息</Button> : <Typography.Text>正在读取付款信息…</Typography.Text> : <Space direction="vertical" style={{ width: '100%', alignItems: 'center' }}>
        <Typography.Title heading={3}>¥{minorToYuanText(row.amount_minor)}</Typography.Title><Tag color={row.status === 'paid' ? 'green' : undefined}>{rechargeLabels[row.status]}</Tag>
        {qr && <><img src={qr} alt="支付二维码" width={240} height={240} /><Typography.Text>使用{row.plugin_id === 'wechat_native' ? '微信' : row.plugin_id === 'alipay_order_code' ? '支付宝' : '支付应用'}扫码支付</Typography.Text><a href={qr} download="payment-qr.png">保存二维码</a></>}
        {row.message && <Typography.Text type="secondary">{row.message}</Typography.Text>}<Typography.Text type="secondary">充值单号：{row.recharge_no}</Typography.Text>
      </Space>}
    </Modal>
  </div>
}
