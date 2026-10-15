import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { capabilityApi, type CapabilitySnapshot } from '../api/capabilityApi'
import { useAdminAuth } from './AuthContext'
import { CapabilitiesContext, type CapabilitiesValue } from './CapabilitiesContext'

/** 按登录主体隔离能力缓存；退出、切换账号和过期时重新读取本机投影。 */
export function CapabilitiesProvider({ children }: { children: ReactNode }) {
  const { status, user } = useAdminAuth()
  const scope = status === 'authenticated' && user ? `${user.id}:${user.username}` : ''
  const [result, setResult] = useState<{ scope: string, data: CapabilitySnapshot | null, error: string }>()
  const [revision, setRevision] = useState(0)
  const refresh = useCallback(() => setRevision((value) => value + 1), [])
  useEffect(() => {
    if (!scope) return undefined
    let active: AbortController | undefined
    let timer: ReturnType<typeof setTimeout> | undefined
    let disposed = false
    const load = async () => {
      active?.abort()
      const controller = new AbortController()
      active = controller
      if (timer) clearTimeout(timer)
      setResult({ scope, data: null, error: '' })
      try {
        const data = await capabilityApi.read(controller.signal)
        if (controller.signal.aborted || disposed) return
        setResult({ scope, data, error: '' })
        const seconds = Math.min(300, Math.max(1, data.next_check_in_seconds ?? 300))
        timer = setTimeout(() => { void load() }, seconds * 1000)
      } catch (cause) {
        if (controller.signal.aborted || disposed) return
        setResult({ scope, data: null, error: cause instanceof Error ? cause.message : '功能权益读取失败' })
        timer = setTimeout(() => { void load() }, 30000)
      }
    }
    const reload = () => { void load() }
    void load()
    window.addEventListener('focus', reload)
    window.addEventListener('admin:entitlements-changed', reload)
    window.addEventListener('admin:access-changed', reload)
    window.addEventListener('admin:updates-changed', reload)
    return () => {
      disposed = true
      active?.abort()
      if (timer) clearTimeout(timer)
      window.removeEventListener('focus', reload)
      window.removeEventListener('admin:entitlements-changed', reload)
      window.removeEventListener('admin:access-changed', reload)
      window.removeEventListener('admin:updates-changed', reload)
    }
  }, [scope, revision])
  const value = useMemo<CapabilitiesValue>(() => {
    const current = scope && result?.scope === scope ? result : undefined
    return {
      loading: Boolean(scope && (!current || (!current.data && !current.error))), error: current?.error ?? '', refresh,
      can: (id, operation) => Boolean(current?.data?.capabilities[id]?.installed && current.data.capabilities[id]?.operations[operation]),
    }
  }, [scope, result, refresh])
  return <CapabilitiesContext.Provider value={value}>{children}</CapabilitiesContext.Provider>
}
