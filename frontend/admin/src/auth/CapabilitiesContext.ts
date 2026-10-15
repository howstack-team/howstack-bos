import { createContext, useContext } from 'react'
import type { EntitlementOperation } from '../api/capabilityApi'

/** 已登录主体的本机权益读取状态。 */
export interface CapabilitiesValue {
  loading: boolean
  error: string
  can: (capability: string, operation: EntitlementOperation) => boolean
  refresh: () => void
}
export const CapabilitiesContext = createContext<CapabilitiesValue | null>(null)

/** 必须指定操作类型；后端执行前仍重新判断。 */
export function useCapabilities(): CapabilitiesValue {
  const value = useContext(CapabilitiesContext)
  if (!value) throw new Error('useCapabilities 必须在 CapabilitiesProvider 内使用')
  return value
}
