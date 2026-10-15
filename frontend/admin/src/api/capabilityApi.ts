import { request } from './httpClient'

/** 服务端按操作判断权益，前端结果仅用于展示。 */
export type EntitlementOperation = 'read_existing' | 'configure' | 'start_new' | 'complete_existing' | 'maintain_existing' | 'download_update'

export interface CapabilityState {
  installed: boolean
  status: string
  operations: Partial<Record<EntitlementOperation, boolean>>
  reasons: Partial<Record<EntitlementOperation, string>>
}

/** 登录主体的最小能力投影，不含授权编号或原证书。 */
export interface CapabilitySnapshot {
  capabilities: Record<string, CapabilityState>
  entitlement_revision: number
  next_check_at: string | null
  next_check_in_seconds: number | null
}

const operations = new Set<string>(['read_existing', 'configure', 'start_new', 'complete_existing', 'maintain_existing', 'download_update'])
const record = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value)

/** 严格校验服务端能力数据，缺失或畸形数据不能解锁操作。 */
export function parseCapabilitySnapshot(value: unknown): CapabilitySnapshot {
  if (!record(value) || !record(value.capabilities) || !Number.isSafeInteger(value.entitlement_revision)
    || (value.entitlement_revision as number) < 0
    || (value.next_check_at !== null && typeof value.next_check_at !== 'string')
    || (value.next_check_in_seconds !== null && (!Number.isSafeInteger(value.next_check_in_seconds) || (value.next_check_in_seconds as number) < 0))) {
    throw new Error('功能权益响应格式无效')
  }
  const capabilities: Record<string, CapabilityState> = Object.create(null)
  for (const [id, item] of Object.entries(value.capabilities)) {
    if (!/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/.test(id) || !record(item) || typeof item.installed !== 'boolean'
      || typeof item.status !== 'string' || !record(item.operations) || !record(item.reasons)) throw new Error('功能权益响应格式无效')
    const allowed: CapabilityState['operations'] = {}
    const reasons: CapabilityState['reasons'] = {}
    for (const [operation, permitted] of Object.entries(item.operations)) {
      if (!operations.has(operation) || typeof permitted !== 'boolean' || typeof item.reasons[operation] !== 'string') throw new Error('功能权益响应格式无效')
      allowed[operation as EntitlementOperation] = permitted
      reasons[operation as EntitlementOperation] = item.reasons[operation] as string
    }
    capabilities[id] = { installed: item.installed, status: item.status, operations: allowed, reasons }
  }
  return {
    capabilities, entitlement_revision: value.entitlement_revision as number,
    next_check_at: value.next_check_at as string | null,
    next_check_in_seconds: value.next_check_in_seconds as number | null,
  }
}

/** 只读本机状态，不触发官网刷新。 */
export const capabilityApi = {
  read: async (signal?: AbortSignal): Promise<CapabilitySnapshot> => parseCapabilitySnapshot(await request<unknown>({ url: '/official-platform/capabilities', signal })),
}
