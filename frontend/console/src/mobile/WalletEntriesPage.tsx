import { useEffect, useRef } from 'react'
import Button from '@arco-design/mobile-react/esm/button'
import { useWalletEntries } from '../hooks/useWalletEntries'
import { walletBusinessLabels, walletEntryAmount, walletEntryLabels } from '../api/walletEntriesApi'
import { minorToYuanText } from '../utils/money'
import { useFeedback } from './feedback'
import { Busy, EmptyState } from './ui'

/** 移动只读卡片与 PC 读取同一账本；单号换行，不压缩默认控件。 */
export default function MobileWalletEntriesPage() {
  const query = useWalletEntries(), feedback = useFeedback(), reported = useRef<unknown>(null)
  useEffect(() => {
    if (query.error && query.error !== reported.current) feedback(query.error.message)
    reported.current = query.error
  }, [query.error, feedback])
  return <>
    <section className="m-panel"><div className="m-row"><h2>资金明细</h2><Button type="default" inline size="small" loading={query.isFetching} onClick={() => void query.refetch()}>刷新明细</Button></div>
      <p className="m-muted">冻结资金打款不再扣减可用余额，该笔可用余额变动为 0。</p></section>
    {query.isError ? <section className="m-panel"><p>资金明细暂时无法加载</p><Button type="default" onClick={() => void query.refetch()}>重新加载</Button></section> : query.isPending ? <Busy /> :
      !query.data?.data.length ? <section className="m-panel"><EmptyState text="暂无资金明细" /></section> : query.data.data.map(row => <article className="m-panel" key={row.entry_no}>
        <div className="m-row"><strong>{walletEntryLabels[row.entry_type] ?? '其他变动'}</strong><strong style={{ whiteSpace: 'nowrap' }}>¥{walletEntryAmount(row.amount_minor)}</strong></div>
        <p className="m-muted">{row.created_at}</p>
        <dl className="m-wallet-entry-fields">
          <dt>流水号</dt><dd>{row.entry_no}</dd>
          <dt>变动后可用余额</dt><dd>¥{minorToYuanText(row.available_after_minor)}</dd>
          <dt>业务类型</dt><dd>{walletBusinessLabels[row.business_type]}</dd>
          <dt>业务单号</dt><dd>{row.business_no ?? '-'}</dd>
        </dl>
      </article>)}
    {!query.isError && !query.isPending && <section className="m-panel"><div className="m-pagination">
      <Button type="default" inline size="small" disabled={query.page <= 1 || query.isFetching} onClick={() => query.paginate(query.page - 1)}>上一页</Button>
      <span>第 {query.page} 页 · 共 {query.data?.total ?? 0} 条</span>
      <Button type="default" inline size="small" disabled={query.page * query.pageSize >= (query.data?.total ?? 0) || query.isFetching} onClick={() => query.paginate(query.page + 1)}>下一页</Button>
    </div></section>}
  </>
}
