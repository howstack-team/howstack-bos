import { useEffect, useState } from 'react'
import { communityRelease } from '../../../packages/community-release'
import { officialPlatformApi, type UpdateNotice } from '../api/officialPlatformApi'
import { useAdminAuth } from '../auth/AuthContext'

const REMIND_LATER_MS = 24 * 60 * 60 * 1000

/** 菜单和顶部提醒共用本机缓存查询；提醒偏好只影响顶部提示。 */
export default function useSoftwareUpdateNotice() {
  const { user, permissions } = useAdminAuth()
  const canView = communityRelease.commercialDelivery && permissions.includes('admin.updates.view')
  const userId = user?.id
  const [snapshot, setSnapshot] = useState<{ userId: number; notice: UpdateNotice; hidden: boolean }>()

  useEffect(() => {
    if (!canView || !userId) return undefined
    let controller: AbortController | undefined
    const load = async () => {
      controller?.abort()
      controller = new AbortController()
      const signal = controller.signal
      try {
        const notice = await officialPlatformApi.updateNotice(signal)
        if (signal.aborted) return
        const key = `howstack-update-reminder:${userId}:${notice.current_version}:${notice.latest_version}`
        let until = 0
        try { until = Number(localStorage.getItem(key)) || 0 } catch { /* 存储不可用仍展示提醒。 */ }
        setSnapshot({ userId, notice, hidden: !notice.force_update && Number.isFinite(until) && until > Date.now() })
      } catch { /* 本机请求失败保留当前提醒，不触发官网检查。 */ }
    }
    void load()
    const timer = setInterval(() => { void load() }, 5 * 60 * 1000)
    const refresh = () => { void load() }
    window.addEventListener('admin:updates-changed', refresh)
    return () => {
      controller?.abort()
      clearInterval(timer)
      window.removeEventListener('admin:updates-changed', refresh)
    }
  }, [canView, userId])

  const current = canView && snapshot?.userId === userId ? snapshot : undefined
  const remindLater = () => {
    if (!current || current.notice.force_update) return
    setSnapshot({ ...current, hidden: true })
    const { notice } = current
    try {
      localStorage.setItem(
        `howstack-update-reminder:${userId}:${notice.current_version}:${notice.latest_version}`,
        String(Date.now() + REMIND_LATER_MS),
      )
    } catch { /* 本次页面仍能隐藏顶部提醒。 */ }
  }

  return { notice: current?.notice, hidden: current?.hidden ?? false, remindLater }
}
