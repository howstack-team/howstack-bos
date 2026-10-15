import { useEffect, useMemo, useState } from 'react'
import { workplaceQueries, type WorkplaceKey, type WorkplaceResult } from '../api/workplaceApi'

/** 各模块独立加载，单项失败不阻止其他结果，范围变化时隐藏旧数据。 */
export interface WorkplaceState { data?: WorkplaceResult; error?: string }
type Records = Partial<Record<WorkplaceKey, WorkplaceState>>

/** 按当前管理员权限读取工作台；组件卸载、账号或权限变化后丢弃旧请求结果。 */
export function useWorkplaceData(userId: number | undefined, permissions: readonly string[]) {
  const permissionKey = JSON.stringify([...permissions].sort())
  const scope = `${userId ?? ''}:${permissionKey}`
  const allowed = useMemo(() => {
    const current = JSON.parse(permissionKey) as string[]
    return workplaceQueries.filter(query => current.includes(query.permission))
  }, [permissionKey])
  const [revision, setRevision] = useState(0)
  const [snapshot, setSnapshot] = useState<{ scope: string; revision: number; records: Records }>({ scope: '', revision: -1, records: {} })
  useEffect(() => {
    let active = true
    // 每个数据源分别交付结果，避免某项失败清空其他可查看的数据。
    for (const query of allowed) {
      void query.load().then(data => ({ data }), (reason: unknown) => ({ error: reason instanceof Error ? reason.message : '加载失败，请重试' }))
        .then(result => {
          if (!active) return
          setSnapshot(previous => ({ scope, revision,
            records: { ...(previous.scope === scope && previous.revision === revision ? previous.records : {}), [query.key]: result } }))
        })
    }
    return () => { active = false }
  }, [allowed, scope, revision])
  const records = snapshot.scope === scope && snapshot.revision === revision ? snapshot.records : {}
  const loading = allowed.some(query => !records[query.key])
  return { records, loading, reload: () => setRevision(value => value + 1) }
}
