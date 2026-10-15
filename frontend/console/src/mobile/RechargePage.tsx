import { useEffect, useRef, useState } from 'react'
import { useHistory, useLocation } from 'react-router-dom'
import Button from '@arco-design/mobile-react/esm/button'
import { ValidatorType } from '@arco-design/mobile-utils'
import Form, { useForm } from '@arco-design/mobile-react/esm/form'
import Radio from '@arco-design/mobile-react/esm/radio'
import Dialog from '@arco-design/mobile-react/esm/dialog'
import { Field, Busy, EmptyState } from './ui'
import { useFeedback } from './feedback'
import { useRecharges } from '../hooks/useRecharges'
import { usePaymentQr } from '../hooks/usePaymentQr'
import { rechargeAmount, rechargeLabels, terminalRecharge } from '../api/rechargeApi'
import { minorToYuanText } from '../utils/money'
import { authFieldErrors } from '../utils/authFieldErrors'
import { loginReturnPath } from '../utils/loginReturnPath'
import { paths } from '../routePaths'

export default function RechargePage() {
  const [form] = useForm(), state = useRecharges(), feedback = useFeedback(), history = useHistory(), location = useLocation()
  const [amount, setAmount] = useState(''), [channel, setChannel] = useState<number | null>(null), [fields, setFields] = useState<Record<string, string>>({})
  const row = state.detail.data, qr = usePaymentQr(row?.qr_code), error = state.options.error || state.listing.error || state.detail.error, reported = useRef<unknown>(null)
  useEffect(() => { if (error && error !== reported.current) feedback(error.message); reported.current = error }, [error, feedback])
  async function submit() {
    const minor = rechargeAmount(amount)
    if (minor === null || channel === null) return
    setFields({})
    try { await state.create(channel, minor) }
    catch (error) { const errors = authFieldErrors(error, ['amount', 'channel_id'], { amount_minor: 'amount' }); if (Object.keys(errors).length) setFields(errors); else feedback(error instanceof Error ? error.message : '充值未完成') }
  }
  async function check(close = false) { try { await state.check(close) } catch (error) { feedback(error instanceof Error ? error.message : '支付核查未完成') } }
  const returnPath = location.state && typeof location.state === 'object' && 'from' in location.state ? loginReturnPath(location.state) : paths.wallet
  return <><section className="m-panel"><h2>在线充值</h2>
    {state.options.isPending ? <Busy /> : !state.options.data?.enabled ? <p className="m-muted">在线充值暂不可用，请联系平台。</p> :
      <Form form={form} layout="vertical" onSubmit={() => { void submit() }} onSubmitFailed={() => setFields({})}>
        <Field label="充值金额" value={amount} onChange={value => { setAmount(value); setFields({}) }} maxLength={10} placeholder="请输入充值金额" suffix={<span>元</span>} disabled={state.busy} error={fields.amount} validate={value => rechargeAmount(value) === null ? '请输入0.01至1000000元，最多两位小数' : undefined} />
        <Form.Item field="channel" label="支付方式" rules={[{ type: ValidatorType.Number, required: true, message: '请选择支付方式' }]}><Radio.Group disabled={state.busy} value={channel ?? undefined} onChange={value => { setChannel(Number(value)); setFields({}) }}>{state.options.data.channels.map(option => <Radio key={option.id} value={option.id}>{option.name}</Radio>)}</Radio.Group></Form.Item>
        {fields.channel_id && <p className="m-field-error" role="alert">{fields.channel_id}</p>}<Button disabled={state.busy} loading={state.busy} onClick={() => form.submit()}>前往付款</Button>
      </Form>}
  </section><section className="m-panel"><h2>在线充值记录</h2>
    {state.listing.isPending ? <Busy /> : !state.listing.data?.data.length ? <EmptyState text="暂无充值记录" /> : state.listing.data.data.map(item => <div key={item.id} style={{ padding: '12px 0', borderBottom: '1px solid var(--color-border)' }}>
      <div className="m-row"><strong>¥{minorToYuanText(item.amount_minor)}</strong><span>{rechargeLabels[item.status]}</span></div><p className="m-muted">{item.recharge_no}</p><Button type="default" inline size="small" onClick={() => state.select(item.id)}>查看付款</Button>
    </div>)}
    {Boolean(state.listing.data?.total) && <div className="m-row" style={{ marginTop: 16 }}><Button inline type="default" size="small" disabled={state.page === 1} onClick={() => state.setPage(state.page - 1)}>上一页</Button><span>第 {state.page} 页</span><Button inline type="default" size="small" disabled={state.page * 20 >= (state.listing.data?.total ?? 0)} onClick={() => state.setPage(state.page + 1)}>下一页</Button></div>}
  </section>
    <Dialog title="充值付款" visible={state.activeId !== null} close={() => state.select(null)} maskClosable footer={row?.status === 'paid' ? [{ content: '返回继续', onClick: () => { history.push(returnPath); return true } }] : [{ content: '关闭', onClick: () => state.select(null) }]}>
      {!row ? state.detail.isError ? <Button onClick={() => { void state.detail.refetch() }}>重新加载付款信息</Button> : <Busy /> : <div style={{ textAlign: 'center' }}><h2>¥{minorToYuanText(row.amount_minor)}</h2><p>{rechargeLabels[row.status]}</p>
        {qr && <><img src={qr} alt="支付二维码" width={220} height={220} style={{ maxWidth: '100%', height: 'auto' }} /><p>使用{row.plugin_id === 'wechat_native' ? '微信' : row.plugin_id === 'alipay_order_code' ? '支付宝' : '支付应用'}扫码支付</p><a href={qr} download="payment-qr.png">保存二维码</a></>}
        {row.message && <p className="m-muted">{row.message}</p>}<p className="m-muted" style={{ overflowWrap: 'anywhere' }}>{row.recharge_no}</p>
        {!terminalRecharge(row.status) && <div style={{ display: 'grid', gap: 8, marginTop: 16 }}><Button disabled={state.busy} loading={state.busy} onClick={() => { void check() }}>查询支付结果</Button><Button type="default" disabled={state.busy} onClick={() => { void check(true) }}>取消付款</Button></div>}
      </div>}
    </Dialog>
  </>
}
