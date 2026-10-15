import { useEffect, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useHistory, useLocation } from 'react-router-dom'
import { useMemberAuth } from '../auth/AuthContext'
import { createRecharge, getRecharge, getRechargeOptions, listRecharges, queryRecharge, terminalRecharge } from '../api/rechargeApi'

/** 刷新与网络重试复用充值意图，仅保存金额/通道/随机键，不保存凭据。 */
function intentKey(memberId: string, channel: number, amount: number): string {
  const key = `console.recharge.intent.${memberId}`
  try {
    const old: unknown = JSON.parse(sessionStorage.getItem(key) ?? 'null')
    if (typeof old === 'object' && old !== null && 'channel' in old && 'amount' in old && 'key' in old && 'expires' in old
      && old.channel === channel && old.amount === amount && typeof old.key === 'string' && /^[A-Za-z0-9_-]{16,64}$/.test(old.key) && typeof old.expires === 'number' && old.expires > Date.now()) return old.key
  } catch { /* 存储不可用时当前页面仍保留内存意图。 */ }
  const requestKey = typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : Array.from(crypto.getRandomValues(new Uint8Array(16)), value => value.toString(16).padStart(2, '0')).join('')
  try { sessionStorage.setItem(key, JSON.stringify({ channel, amount, key: requestKey, expires: Date.now() + 7200000 })) } catch { /* 不影响当前页面支付，重复请求仍复用下面的内存键。 */ }
  return requestKey
}
/** PC/Mobile共用充值状态；GET轮询只读，主动查单由明确点击和后台Worker执行。 */
export function useRecharges() {
  const { member } = useMemberAuth(), client = useQueryClient(), history = useHistory(), location = useLocation()
  const [page, setPage] = useState(1), [busy, setBusy] = useState(false), lock = useRef(false), intent = useRef<{ channel: number; amount: number; key: string } | null>(null)
  const rawId = new URLSearchParams(location.search).get('id') ?? ''
  const activeId = /^[1-9][0-9]{0,9}$/.test(rawId) ? Number(rawId) : null
  const options = useQuery({ queryKey: ['console', 'recharges', 'options'], queryFn: ({ signal }) => getRechargeOptions(signal), retry: false })
  const listing = useQuery({ queryKey: ['console', 'recharges', 'list', page], queryFn: ({ signal }) => listRecharges(page, signal), retry: false })
  const detail = useQuery({ queryKey: ['console', 'recharges', 'detail', activeId], queryFn: ({ signal }) => getRecharge(activeId!, signal), enabled: activeId !== null, retry: false,
    refetchInterval: query => query.state.data && !terminalRecharge(query.state.data.status) ? 4000 : false })
  useEffect(() => {
    if (detail.data?.status !== 'paid') return
    try { sessionStorage.removeItem(`console.recharge.intent.${member!.id}`) } catch { /* 不影响到账。 */ }
    intent.current = null
    void client.invalidateQueries({ queryKey: ['console', 'wallet'] }); void client.invalidateQueries({ queryKey: ['console', 'recharges', 'list'] })
  }, [detail.data?.status, client, member])
  function select(id: number | null) {
    const params = new URLSearchParams(location.search)
    if (id === null) params.delete('id'); else params.set('id', String(id))
    history.replace({ pathname: location.pathname, search: params.size ? `?${params}` : '', state: location.state })
  }
  async function create(channel: number, amount: number) {
    if (lock.current) return
    lock.current = true; setBusy(true)
    try {
      if (intent.current?.channel !== channel || intent.current.amount !== amount) intent.current = { channel, amount, key: intentKey(member!.id, channel, amount) }
      const row = await createRecharge(channel, amount, intent.current.key)
      client.setQueryData(['console', 'recharges', 'detail', row.id], row); select(row.id)
      await client.invalidateQueries({ queryKey: ['console', 'recharges', 'list'] })
    } finally { lock.current = false; setBusy(false) }
  }
  async function check(close = false) {
    if (!activeId || lock.current) return
    lock.current = true; setBusy(true)
    try {
      const row = await queryRecharge(activeId, close)
      client.setQueryData(['console', 'recharges', 'detail', activeId], row)
      await client.invalidateQueries({ queryKey: ['console', 'recharges', 'list'] })
    } finally { lock.current = false; setBusy(false) }
  }
  return { options, listing, detail, page, setPage, activeId, select, create, check, busy }
}
