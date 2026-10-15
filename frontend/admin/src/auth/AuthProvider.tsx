import { Spin } from '@arco-design/web-react'
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { authApi, type AdminUser } from './authApi.ts'
import { ApiRequestError, rotateAdminRequests } from '../api/httpClient.ts'
import { AuthContext, type AuthenticationStatus, type AuthContextValue } from './AuthContext.ts'

const channelName = 'howstack.admin-session'
/** 后台切换账号必须清空页面及请求，跨标签页只广播事件。 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<AuthenticationStatus>('loading')
  const [user, setUser] = useState<AdminUser | null>(null)
  const [permissions, setPermissions] = useState<string[]>([])
  const [bootstrapError, setBootstrapError] = useState('')
  const [checking, setChecking] = useState(false)
  const currentUserId = useRef<number | null>(null)
  const revision = useRef(0)
  const mounted = useRef(true)
  const clear = useCallback(() => {
    revision.current++
    rotateAdminRequests()
    setChecking(false)
    currentUserId.current = null
    setUser(null); setPermissions([])
    window.dispatchEvent(new Event('admin:session-cleared'))
  }, [])
  const refresh = useCallback(async () => {
    let current = ++revision.current
    setChecking(true)
    try {
      const result = await authApi.profile()
      if (!mounted.current || revision.current !== current) return
      if (currentUserId.current !== null && currentUserId.current !== result.user.id) { clear(); current = revision.current }
      currentUserId.current = result.user.id
      setUser(result.user); setPermissions(result.permissions); setBootstrapError(''); setStatus('authenticated')
    } catch (error) {
      if (!mounted.current || revision.current !== current) return
      clear(); current = revision.current; setStatus('anonymous')
      if (!(error instanceof ApiRequestError) || error.status !== 401) setBootstrapError(error instanceof Error ? error.message : '暂时无法验证登录状态')
    } finally { if (mounted.current && revision.current === current) setChecking(false) }
  }, [clear])
  const broadcast = () => {
    try { localStorage.setItem(channelName, `${Date.now()}:${Math.random()}`) } catch { /* 焦点校验兜底。 */ }
    const channel = typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel(channelName)
    channel?.postMessage('changed'); channel?.close()
  }
  useEffect(() => {
    mounted.current = true
    void refresh()
    const reload = () => { void refresh() }
    const onStorage = (event: StorageEvent) => { if (event.key === channelName) reload() }
    const visible = () => { if (document.visibilityState === 'visible') reload() }
    const channel = typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel(channelName)
    if (channel) channel.onmessage = reload
    window.addEventListener('focus', reload)
    window.addEventListener('storage', onStorage)
    window.addEventListener('admin:access-changed', reload)
    document.addEventListener('visibilitychange', visible)
    return () => {
      mounted.current = false; revision.current++; rotateAdminRequests(); channel?.close()
      window.removeEventListener('focus', reload)
      window.removeEventListener('storage', onStorage)
      window.removeEventListener('admin:access-changed', reload)
      document.removeEventListener('visibilitychange', visible)
    }
  }, [refresh])
  const value = useMemo<AuthContextValue>(() => ({ status, user, permissions, bootstrapError,
    async login(input) {
      const result = await authApi.login(input)
      if (!result.mfa_required) { await refresh(); broadcast() }
      return result
    },
    async verifyMfa(code, recovery) {
      await authApi.verifyMfa(code, recovery)
      await refresh()
      broadcast()
    },
    async logout() {
      await authApi.logout()
      clear(); setStatus('anonymous'); broadcast()
    },
    refreshAccess: refresh,
    clearSession() { clear(); setStatus('anonymous'); broadcast() },
  }), [bootstrapError, status, user, permissions, clear, refresh])
  return <AuthContext.Provider value={value}>
    <div key={user?.id ?? 'anonymous'} style={{ display: checking ? 'none' : 'contents' }}>{children}</div>
    {checking && <div role="status" style={{ minHeight: '60vh', display: 'flex', alignItems: 'center', justifyContent: 'center' }}><Spin /></div>}
  </AuthContext.Provider>
}
