import { useEffect, useState } from 'react'
import { getBusinessAnalysis, type BusinessAnalysis, type BusinessAnalysisQuery } from '../api/businessAnalysisApi'
import { ApiRequestError } from '../api/httpClient'
import type { AnalysisQueryErrors } from '../utils/businessAnalysis'

interface AnalysisState { key: string; data?: BusinessAnalysis; error?: string; fieldErrors?: AnalysisQueryErrors }
function fieldErrors(value: unknown): AnalysisQueryErrors {
  if (typeof value !== 'object' || value === null || !('errors' in value) || typeof value.errors !== 'object' || value.errors === null) return {}
  return Object.fromEntries(Object.entries(value.errors).filter(([key, errors]) => ['start_date', 'end_date', 'granularity'].includes(key) && Array.isArray(errors))
    .map(([key, errors]) => [key, (errors as unknown[]).filter(item => typeof item === 'string').join('；')]))
}
/** 查询或身份变化立即隐藏旧统计；取消旧请求，失败保留条件并提供重试。 */
export function useBusinessAnalysis(query: BusinessAnalysisQuery, userId: number | undefined, enabled: boolean, revision: number) {
  const queryKey = JSON.stringify(query)
  const key = `${userId ?? ''}:${queryKey}:${revision}`
  const [state, setState] = useState<AnalysisState>({ key: '' })
  useEffect(() => {
    if (!enabled) return
    const controller = new AbortController()
    void getBusinessAnalysis(JSON.parse(queryKey) as BusinessAnalysisQuery, controller.signal)
      .then(data => { if (!controller.signal.aborted) setState({ key, data }) })
      .catch((reason: unknown) => {
        if (controller.signal.aborted) return
        setState({ key, error: reason instanceof Error ? reason.message : '经营分析加载失败，请重试',
          fieldErrors: reason instanceof ApiRequestError && reason.status === 422 ? fieldErrors(reason.data) : {} })
      })
    return () => controller.abort()
  }, [queryKey, key, enabled])
  const current = enabled && state.key === key ? state : undefined
  return { data: current?.data, error: current?.error, fieldErrors: current?.fieldErrors ?? {}, loading: enabled && !current }
}
