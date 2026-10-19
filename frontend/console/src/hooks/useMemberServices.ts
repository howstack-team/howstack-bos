import { useQuery } from '@tanstack/react-query'
import { useMemberAuth } from '../auth/AuthContext'
import { getMemberServices } from '../api/serviceApi'
/** 按会员隔离查询，开通期间轮询，页面隐藏时暂停。 */
export function useMemberServices(page: number) {
  const { member } = useMemberAuth()
  return useQuery({ queryKey: ['console', 'services', member?.id, page], queryFn: ({ signal }) => getMemberServices(page, signal), enabled: Boolean(member), retry: false,
    refetchInterval: query => query.state.data?.data.some(row => ['queued', 'processing', 'waiting'].includes(row.provisioning_status ?? '') || ['queued', 'processing', 'waiting'].includes(row.lifecycle_status ?? '')) ? 5000 : false })
}
