import Dialog from '@arco-design/mobile-react/esm/dialog'
import Radio from '@arco-design/mobile-react/esm/radio'
import Button from '@arco-design/mobile-react/esm/button'
import { useHistory } from 'react-router-dom'
import { useServiceRenewal } from '../hooks/useServiceRenewal'
import { paths } from '../routePaths'
import { minorToYuanText } from '../utils/money'
import { useFeedback } from './feedback'

/** 独立移动组件，使用原生移动控件，不引入PC样式。 */
export default function ServiceRenewalDialog({ id, close }: { id: number; close: () => void }) {
  const state = useServiceRenewal(id), feedback = useFeedback(), history = useHistory()
  const error = state.options.error ?? state.quote.error
  return <Dialog visible title="续费云服务器" maskClosable={!state.submitting} close={() => { if (!state.submitting) close() }} footer={[
    { content: '取消', onClick: () => { if (!state.submitting) close() } },
    { content: state.submitting ? '正在创建…' : '创建续费订单', disabled: !state.valid || state.submitting, onClick: () => {
      void state.submit().then(orderId => { if (orderId) { close(); history.push(`${paths.orders}/${orderId}`) } }).catch(failure => feedback(failure instanceof Error ? failure.message : '续费订单创建失败'))
      return true
    } },
  ]}>
    {state.options.isPending ? <p>正在读取续费周期…</p> : error ? <><p>{error.message}</p><Button type="default" onClick={() => { if (state.options.error || !state.selected) void state.options.refetch(); else void state.quote.refetch() }}>重试</Button></> : <>
      <p>当前到期：{state.options.data?.expires_at}</p>
      <Radio.Group value={state.selected} onChange={value => state.select(Number(value))} options={state.options.data?.durations.map(item => ({ value: item.months, label: item.label, disabled: state.submitting }))} />
      <p>续费金额：{state.quote.isFetching ? '正在计算…' : state.quote.data?.amount_minor ? `¥${minorToYuanText(state.quote.data.amount_minor)}` : '—'}</p>
      <p className="m-muted">延长原实例期限，不新增实例。到期后付款从付款时间起算，已暂停实例在续费成功后恢复。</p>
    </>}
  </Dialog>
}
