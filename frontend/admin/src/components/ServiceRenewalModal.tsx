import { useEffect, useRef, useState } from 'react'
import { Alert, Descriptions, Message, Modal, Select, Spin } from '@arco-design/web-react'
import { createServiceRenewal, quoteServiceRenewal } from '../api/serviceInstanceApi'
import { renewalRequestKey, type RenewalQuote } from '../../../packages/service-renewal'
import { formatMoneyMinor } from '../utils/money'

/** 后台只创建原会员续费订单，扣款仍通过订单管理的独立权限及确认。 */
export default function ServiceRenewalModal({ id, close, done }: { id: number; close: () => void; done: (orderId: number) => void }) {
  const [options, setOptions] = useState<RenewalQuote>(), [months, setMonths] = useState<number>(), [quote, setQuote] = useState<RenewalQuote>()
  const [loading, setLoading] = useState(true), [error, setError] = useState(''), [submitting, setSubmitting] = useState(false)
  const key = useRef(renewalRequestKey()), busy = useRef(false)
  useEffect(() => {
    const controller = new AbortController()
    void quoteServiceRenewal(id, 0, controller.signal).then(result => { if (!controller.signal.aborted) { setOptions(result); setMonths(result.durations[0]?.months) } })
      .catch(failure => { if (!controller.signal.aborted) { setError(failure instanceof Error ? failure.message : '续费周期读取失败'); setLoading(false) } })
    return () => controller.abort()
  }, [id])
  useEffect(() => {
    if (!months) return
    const controller = new AbortController()
    void quoteServiceRenewal(id, months, controller.signal).then(result => { if (!controller.signal.aborted) setQuote(result) })
      .catch(failure => { if (!controller.signal.aborted) setError(failure instanceof Error ? failure.message : '续费报价失败') })
      .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [id, months])
  return <Modal title="创建云服务器续费订单" visible maskClosable={!submitting} onCancel={() => { if (!submitting) close() }} confirmLoading={submitting}
    okText="创建续费订单" okButtonProps={{ disabled: loading || !quote?.amount_minor || Boolean(error) }} onOk={async () => {
      if (busy.current || loading || error || !months || !quote?.amount_minor || quote.months !== months) return
      busy.current = true; setSubmitting(true)
      try { done(await createServiceRenewal(id, months, quote.amount_minor, key.current)); Message.success('已创建待付款的续费订单') }
      catch (failure) { Message.error(failure instanceof Error ? failure.message : '订单创建失败') }
      finally { busy.current = false; setSubmitting(false) }
    }}>
    {error && <Alert type="error" content={error} />}
    <p>按实例所在可用区的当前价格续费，不新增实例、不占用新库存。创建订单后仍需完成付款。</p>
    <Select aria-label="续费周期" placeholder="请选择续费周期" disabled={submitting} value={months} onChange={value => { key.current = renewalRequestKey(); setLoading(true); setQuote(undefined); setError(''); setMonths(value) }} options={options?.durations.map(item => ({ value: item.months, label: item.label }))} />
    <Spin loading={loading} style={{ display: 'block', marginTop: 16 }}><Descriptions column={1} data={[
      { label: '当前到期时间', value: options?.expires_at ?? '-' }, { label: '续费金额', value: quote?.amount_minor ? formatMoneyMinor(quote.amount_minor) : '-' },
    ]} /></Spin>
  </Modal>
}
