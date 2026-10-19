import { useQuery } from '@tanstack/react-query'
import { useHistory, useLocation } from 'react-router-dom'
import { useMemberAuth } from '../auth/AuthContext'
import { getWalletEntries, walletEntryPagination } from '../api/walletEntriesApi'

/** 本人查询按身份及 URL 分页隔离；支持刷新、前进后退与请求取消。 */
export function useWalletEntries() {
  const { member } = useMemberAuth(), location = useLocation(), history = useHistory()
  const { page, pageSize } = walletEntryPagination(location.search)
  const query = useQuery({ queryKey: ['console', 'wallet-entries', member?.id, page, pageSize],
    queryFn: ({ signal }) => getWalletEntries(page, pageSize, signal), enabled: Boolean(member), retry: false, staleTime: 0 })
  function paginate(nextPage: number, nextSize = pageSize) {
    const params = new URLSearchParams(location.search)
    params.set('page', String(nextSize === pageSize ? nextPage : 1)); params.set('page_size', String(nextSize))
    history.push({ pathname: location.pathname, search: params.toString() })
  }
  return { ...query, page, pageSize, paginate }
}
