import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { getMember, loginMember, logoutMember } from '../api/memberApi'
import { clearMemberSession, queryKeys } from '../api/queryClient'
import { AuthContext } from './AuthContext'
import type { MemberProfile } from '../api/memberApi'
import loadingImage from '../assets/loading.svg?url'

import { channelName, notifyMemberSessionChanged } from './sessionEvents'

/** 身份变化先取消请求、清除本人缓存，再挂载新会员页面；焦点检查覆盖不同开发端口。 */
export default function AuthProvider({ children }: { children: ReactNode }) {
  const client = useQueryClient()
  const session = useQuery({ queryKey: queryKeys.session, queryFn: ({ signal }) => getMember(signal), retry: false })
  const [transitioning, setTransitioning] = useState(false)
  const epoch = useRef(0)
  const mounted = useRef(true)
  useEffect(() => {
    mounted.current = true
    const refresh = () => {
      const current = ++epoch.current
      const previous = client.getQueryData<MemberProfile | null>(queryKeys.session)
      if (previous) setTransitioning(true)
      void getMember().then(async member => {
        if (!mounted.current || current !== epoch.current) return
        if (previous?.id !== member?.id) await clearMemberSession(client)
        if (mounted.current && current === epoch.current) client.setQueryData(queryKeys.session, member)
      }).catch(() => {
        if (mounted.current && current === epoch.current) void client.invalidateQueries({ queryKey: queryKeys.session })
      }).finally(() => { if (mounted.current && current === epoch.current) setTransitioning(false) })
    }
    const onStorage = (event: StorageEvent) => { if (event.key === channelName) refresh() }
    const onVisible = () => { if (document.visibilityState === 'visible') refresh() }
    const channel = typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel(channelName)
    if (channel) channel.onmessage = refresh
    window.addEventListener('storage', onStorage)
    window.addEventListener('focus', refresh)
    window.addEventListener('member:session-changed', refresh)
    document.addEventListener('visibilitychange', onVisible)
    return () => {
      mounted.current = false; epoch.current++
      channel?.close()
      window.removeEventListener('storage', onStorage)
      window.removeEventListener('focus', refresh)
      window.removeEventListener('member:session-changed', refresh)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [client])
  async function establishSession(member: MemberProfile) {
    // Cookie是事实来源；重新读取防止并发标签页登录把迟到资料当成当前身份。
    await clearMemberSession(client)
    const current = await getMember()
    if (current?.id !== member.id) throw new Error('登录状态已变更，请重新登录')
    client.setQueryData(queryKeys.session, current)
    notifyMemberSessionChanged()
  }
  async function login(account: string, password: string, remember = false) {
    await establishSession(await loginMember(account, password, remember))
  }
  async function logout() {
    await logoutMember()
    await clearMemberSession(client)
    notifyMemberSessionChanged()
  }
  return <AuthContext.Provider value={{ member: session.data ?? null, loading: session.isPending,
    error: session.error, retry: () => { void session.refetch() }, login, establishSession, logout }}>
    <div key={session.data?.id ?? 'anonymous'} style={{ display: transitioning ? 'none' : 'contents' }}>{children}</div>
    {transitioning && <div role="status" style={{ minHeight: '60vh', display: 'flex', alignItems: 'center', justifyContent: 'center' }}><img src={loadingImage} width={40} height={40} alt="正在确认登录状态" /></div>}
  </AuthContext.Provider>
}
