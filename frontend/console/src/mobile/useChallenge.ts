import { useEffect, useRef, useState } from 'react'
import type { LoginChallenge } from '../api/loginApi'

/** 验证码绑定当前用途及账号，仅存内存；冷却时间不因改账号而绕过。 */
export function useChallenge() {
  const record = useRef<{ key: string; id: string; expires: number } | null>(null)
  const mounted = useRef(true)
  const locked = useRef(false)
  const [busy, setBusy] = useState(false)
  const [deadline, setDeadline] = useState(0)
  const [remaining, setRemaining] = useState(0)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; record.current = null } }, [])
  useEffect(() => {
    const update = () => setRemaining(Math.max(0, Math.ceil((deadline - Date.now()) / 1000)))
    update()
    const interval = window.setInterval(update, 500)
    return () => window.clearInterval(interval)
  }, [deadline])
  async function send(key: string, fetch: () => Promise<LoginChallenge>) {
    if (locked.current || remaining > 0) return false
    locked.current = true; setBusy(true)
    try {
      const response = await fetch()
      if (!mounted.current) return false
      record.current = { key, id: response.challenge_id, expires: Date.now() + response.expires_in * 1000 }
      setDeadline(Date.now() + response.retry_after * 1000)
      return true
    } finally { locked.current = false; if (mounted.current) setBusy(false) }
  }
  return { busy, remaining, send, clear: () => { record.current = null },
    getId: (key: string) => record.current?.key === key && record.current.expires > Date.now() ? record.current.id : '',
  }
}
