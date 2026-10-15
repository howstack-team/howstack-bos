import { useQuery } from '@tanstack/react-query'
import { getWalletBalance } from '../api/memberApi'
import { queryKeys } from '../api/queryClient'

/** 资金页面共享本人余额查询；退出取消请求并清除缓存。 */
export function useWallet() {
  return useQuery({ queryKey: queryKeys.wallet, queryFn: ({ signal }) => getWalletBalance(signal) })
}
