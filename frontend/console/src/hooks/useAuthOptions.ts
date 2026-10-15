import { useEffect, useRef } from 'react'
import { useQuery } from '@tanstack/react-query'
import { getAuthOptions } from '../api/authOptionsApi'
/** 登录能力与验证码Cookie同步获取，配置读取失败只反馈一次。 */
export function useAuthOptions(onError: (error: unknown) => void) {
  const options = useQuery({ queryKey: ['public', 'auth-options'], queryFn: ({ signal }) => getAuthOptions(signal), retry: false, staleTime: 0, refetchOnWindowFocus: true })
  const reported = useRef<unknown>(null)
  useEffect(() => {
    if (options.error && reported.current !== options.error) onError(options.error)
    reported.current = options.error
  }, [options.error, onError])
  return options
}
