import { Alert, Button, Descriptions, Message, Modal, Select, Spin } from '@arco-design/web-react'
import { useHistory } from 'react-router-dom'
import { useServiceRenewal } from '../hooks/useServiceRenewal'
import { paths } from '../routePaths'
import { minorToYuanText } from '../utils/money'

/** 创建续费订单后进入现有收银流程，不在弹窗直接扣款。 */
export default function ServiceRenewalModal({ id, close }: { id: number; close: () => void }) {
  const state = useServiceRenewal(id), history = useHistory()
  const error = state.options.error ?? state.quote.error
  return <Modal title="续费云服务器" visible maskClosable={!state.submitting} onCancel={() => { if (!state.submitting) close() }} confirmLoading={state.submitting}
    okText="创建订单并付款" okButtonProps={{ disabled: !state.valid }} onOk={async () => {
      try { const orderId = await state.submit(); if (orderId) { close(); history.push(`${paths.orders}/${orderId}`) } }
      catch (failure) { Message.error(failure instanceof Error ? failure.message : '续费订单创建失败') }
    }}>
    <Spin loading={state.options.isPending} style={{ display: 'block' }}>
      {error && <Alert type="error" content={error.message} action={<Button onClick={() => { if (state.options.error || !state.selected) void state.options.refetch(); else void state.quote.refetch() }}>重试</Button>} />}
      <p>续费延长原实例使用期限，不新增实例。到期后付款从付款时间起算，已暂停实例将在续费成功后恢复。</p>
      <Select aria-label="续费周期" placeholder="请选择续费周期" value={state.selected} disabled={state.submitting} onChange={state.select} options={state.options.data?.durations.map(item => ({ value: item.months, label: item.label }))} />
      <Descriptions column={1} style={{ marginTop: 16 }} data={[
        { label: '当前到期时间', value: state.options.data?.expires_at ?? '—' },
        { label: '续费金额', value: state.quote.isFetching ? '正在计算…' : state.quote.data?.amount_minor ? `¥${minorToYuanText(state.quote.data.amount_minor)}` : '—' },
      ]} />
    </Spin>
  </Modal>
}
