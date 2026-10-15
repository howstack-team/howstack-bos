import { Result, Spin } from '@arco-design/web-react'
import type { ReactNode } from 'react'
import type { EntitlementOperation } from '../api/capabilityApi'
import { useCapabilities } from '../auth/CapabilitiesContext'

/** 商业页面守卫；基础社区页面不包裹此组件。 */
export default function CapabilityGuard({ capability, operation, children }: {
  capability: string
  operation: EntitlementOperation
  children: ReactNode
}) {
  const { loading, error, can } = useCapabilities()
  if (loading) return <Spin />
  if (!can(capability, operation)) return <Result status="403" title="此操作暂不可用" subTitle={error || '请核对功能权益、模块状态及操作权限'} />
  return <>{children}</>
}
