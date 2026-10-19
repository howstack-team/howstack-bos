import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemberAuth } from '../auth/AuthContext'
import { getMemberOrder, getMemberOrders, payMemberOrder, cancelMemberOrder, type MemberOrder } from '../api/purchaseApi'

/** 本人订单查询按会员隔离，退出时由 console 查询前缀统一清理。 */
export function useMemberOrders(page: number, id?: number) {
  const { member } = useMemberAuth(), client = useQueryClient()
  const query = useQuery<MemberOrder | { data: MemberOrder[]; total: number }>({ queryKey: ['console', 'orders', member?.id, page, id],
    queryFn: ({ signal }) => id ? getMemberOrder(id, signal) : getMemberOrders(page, signal), enabled: Boolean(member), retry: false, refetchInterval: query => { const data = query.state.data; return data && 'order_no' in data && ['paid', 'fulfilling'].includes(data.status) ? 5000 : false } })
  const pay = async (orderId: number) => { await payMemberOrder(orderId); await client.invalidateQueries({ queryKey: ['console'] }) }
  const cancel = async (orderId: number) => { await cancelMemberOrder(orderId); await client.invalidateQueries({ queryKey: ['console'] }) }
  return { ...query, pay, cancel }
}
