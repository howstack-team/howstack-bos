import { createContext, useContext } from 'react'
import type { AdminLoginInput, AdminUser, AdminLoginResponse } from './authApi.ts'

export type AuthenticationStatus = 'loading' | 'authenticated' | 'anonymous'

/**
 * 运营后台认证上下文公开能力。
 */
export interface AuthContextValue {
  status: AuthenticationStatus
  user: AdminUser | null
  permissions: string[]
  bootstrapError: string
  login: (input: AdminLoginInput) => Promise<AdminLoginResponse>
  verifyMfa: (code: string, recovery: boolean) => Promise<void>
  logout: () => Promise<void>
  /** 服务端已清除 Cookie（例如改密成功）后同步清除前端会话。 */
  clearSession: () => void
  refreshAccess: () => Promise<void>
}

export const AuthContext = createContext<AuthContextValue | null>(null)

/**
 * 读取运营后台认证上下文。
 * @returns 当前认证状态和登录操作
 * @throws Error 未位于 AuthProvider 内部时抛出
 */
export function useAdminAuth(): AuthContextValue {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAdminAuth 必须在 AuthProvider 内使用')

  return context
}
