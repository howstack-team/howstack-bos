import { MutationCache, QueryCache, QueryClient } from '@tanstack/react-query'
import { ApiRequestError, rotateMemberRequests } from './httpClient.ts'

/** 所有本人查询统一前缀，退出时同时取消并清理。 */
export const queryKeys = {
  member: ['console'] as const, session: ['console', 'session'] as const, wallet: ['console', 'wallet'] as const,
}

/** 先取消在途查询再清理，防止退出后旧响应重新写入本人数据。 */
export async function clearMemberSession(client: QueryClient): Promise<void> {
  rotateMemberRequests()
  await client.cancelQueries({ queryKey: queryKeys.member })
  client.removeQueries({ queryKey: queryKeys.member, predicate: (query) => query.queryKey[1] !== 'session' })
  client.setQueryData(queryKeys.session, null)
  client.getMutationCache().clear()
}

/** 创建共享请求缓存；业务接口的 401 同步触发登录跳转。 */
export function createMemberQueryClient(): QueryClient {
  let expiring = false
  let identity: string | null = null
  const expire = () => {
    if (expiring) return
    expiring = true
    void clearMemberSession(client).finally(() => { expiring = false })
  }
  const onError = (error: Error) => {
    if (error instanceof ApiRequestError && error.status === 401) expire()
  }
  const client = new QueryClient({
    queryCache: new QueryCache({ onError, onSuccess: (data, query) => {
      if (query.queryKey[0] === 'console' && query.queryKey[1] === 'session') {
        const next = data && typeof data === 'object' && 'id' in data ? String(data.id) : null
        if (identity !== null && identity !== next) {
          rotateMemberRequests()
          void client.cancelQueries({ queryKey: queryKeys.member, predicate: item => item.queryKey[1] !== 'session' })
          client.removeQueries({ queryKey: queryKeys.member, predicate: item => item.queryKey[1] !== 'session' })
          client.getMutationCache().clear()
        }
        identity = next
      }
    } }),
    mutationCache: new MutationCache({ onError }),
    defaultOptions: {
      queries: {
        refetchOnWindowFocus: false, staleTime: 30_000,
        retry: (count, error) => count < 1 && error instanceof ApiRequestError && (error.status === 0 || error.status >= 500),
      },
      mutations: { retry: false },
    },
  })
  return client
}
