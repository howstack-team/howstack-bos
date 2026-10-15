import { createContext, useContext } from 'react'
import type { MemberProfile } from '../api/memberApi'

/** 会员身份与会话动作，只暴露本人资料。 */
export interface MemberAuth {
  member: MemberProfile | null
  loading: boolean
  error: Error | null
  retry: () => void
  login: (mobile: string, password: string, remember?: boolean) => Promise<void>
  establishSession: (member: MemberProfile) => Promise<void>
  logout: () => Promise<void>
}
/** 由 AuthProvider 提供，禁止页面自行读取 Cookie 或令牌。 */
export const AuthContext = createContext<MemberAuth | null>(null)

/** 读取会员会话，必须在 AuthProvider 内调用。 */
export function useMemberAuth(): MemberAuth {
  const context = useContext(AuthContext)
  if (!context) throw new Error('会员会话组件缺少 AuthProvider')
  return context
}
