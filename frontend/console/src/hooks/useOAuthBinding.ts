import { useEffect, useRef, useState } from 'react'
import { bindOAuth, getOAuthPending, type OAuthPending } from '../api/loginApi'
import { useMemberAuth } from '../auth/AuthContext'

/** 两端共用关联流程，界面分别保留PC Message和Mobile Toast。 */
export function useOAuthBinding(onError: (error: unknown) => void) {
  const auth = useMemberAuth(), [pending, setPending] = useState<OAuthPending | null>(null), [loading, setLoading] = useState(true), [busy, setBusy] = useState(false)
  const mounted = useRef(true), lock = useRef(false), feedback = useRef(onError)
  useEffect(() => { feedback.current = onError }, [onError])
  useEffect(() => {
    mounted.current = true
    const abort = new AbortController()
    void getOAuthPending(abort.signal).then(value => { if (mounted.current && !abort.signal.aborted) setPending(value) })
      .catch(error => { if (!abort.signal.aborted) feedback.current(error) }).finally(() => { if (mounted.current && !abort.signal.aborted) setLoading(false) })
    return () => { mounted.current = false; abort.abort() }
  }, [])
  async function submit(account: string, password: string) {
    if (!pending || lock.current) return false
    lock.current = true; setBusy(true)
    try { await auth.establishSession(await bindOAuth(account.trim(), password)); return true }
    finally { lock.current = false; if (mounted.current) setBusy(false) }
  }
  return { pending, loading, busy, submit }
}
