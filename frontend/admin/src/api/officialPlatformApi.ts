import { request } from './httpClient'

export interface OfficialConnection {
  connection_id: string
  connection_mode: 'community' | 'licensed'
  edition_code: string
  edition_name: string
  account_name: string
  license_no: string | null
  license_valid: boolean
  license_expires_at: string | null
}
export interface OfficialAccount {
  configured: boolean
  origin: string
  software_code: 'howstack-bos'
  installation_id: string
  connected: boolean
  reconnect_required: boolean
  connection: OfficialConnection | null
  checked_at: string | null
}
export interface CommercialLicense {
  edition: string
  edition_name: string
  status: 'community' | 'active' | 'expired' | 'revoked' | 'offline_expired' | 'unverified' | 'clock_error'
  status_text: string
  license_no: string | null
  offline_until: string | null
  verified_at: string | null
  installation_id: string
  entitlement_revision: number
  entitlements: LicenseEntitlement[]
  last_error: string | null
  account: OfficialAccount
}
/** 已签名的逐项权益，未接入能力不会变成可用模块。 */
export interface LicenseEntitlement {
  capability: string
  name: string
  implemented: boolean
  installed: boolean
  status: string
  use_until: string | null
  updates_until: string | null
  limits: Record<string, number>
}

const isRecord = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value)
const nullableText = (value: unknown) => value === null || typeof value === 'string'

/** 授权读取边界校验；旧字段、缺失权益或无效期限不能默认为有效授权。 */
export function parseCommercialLicense(value: unknown): CommercialLicense {
  if (!isRecord(value) || typeof value.edition !== 'string' || typeof value.edition_name !== 'string' || !value.edition_name.trim()
    || typeof value.status_text !== 'string' || typeof value.installation_id !== 'string'
    || !['community', 'active', 'expired', 'revoked', 'offline_expired', 'unverified', 'clock_error'].includes(String(value.status))
    || !nullableText(value.license_no) || !nullableText(value.offline_until) || !nullableText(value.verified_at) || !nullableText(value.last_error)
    || !Number.isSafeInteger(value.entitlement_revision) || (value.entitlement_revision as number) < 0
    || !Array.isArray(value.entitlements) || !isRecord(value.account) || typeof value.account.connected !== 'boolean'
    || typeof value.account.configured !== 'boolean' || typeof value.account.reconnect_required !== 'boolean'
    || typeof value.account.origin !== 'string' || value.account.software_code !== 'howstack-bos'
    || typeof value.account.installation_id !== 'string' || !nullableText(value.account.checked_at)) throw new Error('授权权益响应格式无效')
  const account = value.account
  if (account.connection !== null && (!isRecord(account.connection) || typeof account.connection.connection_id !== 'string'
    || !['community', 'licensed'].includes(String(account.connection.connection_mode)) || typeof account.connection.edition_code !== 'string'
    || typeof account.connection.edition_name !== 'string' || typeof account.connection.account_name !== 'string'
    || typeof account.connection.license_valid !== 'boolean' || !nullableText(account.connection.license_no)
    || !nullableText(account.connection.license_expires_at))) throw new Error('授权权益响应格式无效')
  const seen = new Set<string>()
  for (const item of value.entitlements) {
    if (!isRecord(item) || typeof item.capability !== 'string' || seen.has(item.capability)
      || typeof item.name !== 'string' || typeof item.implemented !== 'boolean' || typeof item.installed !== 'boolean'
      || typeof item.status !== 'string' || !nullableText(item.use_until) || !nullableText(item.updates_until)
      || !isRecord(item.limits) || Object.values(item.limits).some((limit) => !Number.isSafeInteger(limit) || (limit as number) < 0)) throw new Error('授权权益响应格式无效')
    seen.add(item.capability)
  }
  return value as unknown as CommercialLicense
}
export interface InstallationJob {
  request_id: string
  kind: 'system' | 'application'
  action: 'install' | 'update'
  target_code: string
  target_version: string
  status: 'pending' | 'running' | 'awaiting_restart' | 'succeeded' | 'failed' | 'recovery_required'
  phase: string
  message: string
  error_code: string | null
  report_status: 'none' | 'pending' | 'reported'
  created_at: string
  updated_at: string
}
/** 只含已验签摘要，长期缓存不作为安装凭证。 */
export interface ReleaseChangelog {
  added: string[]
  improved: string[]
  fixed: string[]
  notices: string[]
}
export interface UpdateNotice {
  available: boolean
  current_version: string
  latest_version: string | null
  changelog: ReleaseChangelog
  force_update: boolean
  checked_at: string | null
  last_attempt_at: string | null
  last_error: string | null
}
export interface UpdateStatus {
  current_version: string
  update: null | {
    available: boolean
    target_version: string | null
    checked_at: string
    expires_at: number | null
    bytes: number | null
    sha256: string | null
    changelog: ReleaseChangelog
    force_update: boolean
  }
  last_attempt_at: string | null
  last_error: string | null
  jobs: InstallationJob[]
}
export interface OwnedApplication {
  application_id: number
  name: string
  code: string
  kind: 'plugin' | 'theme'
  entitlement_status: string
  use_expires_at: string | null
  available_version_id: number | null
  available_version: string | null
  force_update: boolean
  local_version: string | null
  local_state: string
  local_revision: number
  local_category: string | null
  reported_status: string
  unavailable_reason: string
}
export interface ApplicationPreview {
  changelog: ReleaseChangelog
  force_update: boolean
  application_code: string
  version_id: number
  version: string
  kind: 'plugin' | 'theme'
  permissions: string[]
  sha256: string
  bytes: number
}
export const officialPlatformApi = {
  account: (signal?: AbortSignal) => request<OfficialAccount>({ url: '/official-platform/connection', signal }),
  activate: async (activation_code: string) => parseCommercialLicense(await request<unknown>({ method: 'POST', url: '/official-platform/license/activate', data: { activation_code } })),
  refreshAccount: () => request<OfficialAccount>({ method: 'POST', url: '/official-platform/connection/refresh' }),
  disconnect: () => request({ method: 'POST', url: '/official-platform/connection/disconnect' }),
  license: async (signal?: AbortSignal) => parseCommercialLicense(await request<unknown>({ url: '/official-platform/license', signal })),
  refreshLicense: async () => parseCommercialLicense(await request<unknown>({ method: 'POST', url: '/official-platform/license/refresh' })),
  updateNotice: (signal?: AbortSignal) => request<UpdateNotice>({ url: '/system-updates/notice', signal }),
  updates: (signal?: AbortSignal) => request<UpdateStatus>({ url: '/system-updates', signal }),
  checkUpdate: () => request<UpdateStatus>({ method: 'POST', url: '/system-updates/check' }),
  startUpdate: (request_id: string, sha256: string, target_version: string) => request<UpdateStatus>({ method: 'POST', url: '/system-updates/start', data: { request_id, sha256, target_version } }),
  owned: (signal?: AbortSignal) => request<{ items: OwnedApplication[]; jobs: InstallationJob[] }>({ url: '/application-center/owned', signal }),
  storeLink: (signal?: AbortSignal) => request<{ store_url: string }>({ url: '/application-center/store-link', signal }),
  uninstall: (code: string, revision: number) => request({ method: 'POST', url: '/application-center/uninstall', data: { code, revision } }),
  prepare: (version_id: number) => request<ApplicationPreview>({ method: 'POST', url: '/application-center/prepare', data: { version_id } }),
  install: (preview: ApplicationPreview, request_id: string, action: 'install' | 'update') => request({ method: 'POST', url: '/application-center/install', data: { request_id, version_id: preview.version_id, sha256: preview.sha256, action } }),
}
export const platformError = (error: unknown) => error instanceof Error ? error.message : '请求未完成，请稍后重试'
