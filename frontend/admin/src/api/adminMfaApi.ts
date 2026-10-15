import { request, ApiRequestError } from './httpClient.ts'

/** 本人MFA状态，不包含密钥或恢复码。 */
export interface AdminMfaStatus { enabled: boolean; enabled_at: string | null; recovery_remaining: number }
/** 仅在绑定期间存在内存中的一次性密钥。 */
export interface AdminMfaSetup { setup_id: string; secret: string; uri: string; expires_at: number }
export interface MfaProof { current_password: string; code: string; recovery: boolean }
export interface MfaCodes extends AdminMfaStatus { recovery_codes: string[] }
function status(value: unknown): AdminMfaStatus {
  if (typeof value !== 'object' || value === null || !('enabled' in value) || typeof value.enabled !== 'boolean'
    || !('enabled_at' in value) || !(value.enabled_at === null || typeof value.enabled_at === 'string')
    || !('recovery_remaining' in value) || typeof value.recovery_remaining !== 'number' || !Number.isInteger(value.recovery_remaining)) throw new ApiRequestError('安全设置响应无效', 500, 50000)
  return { enabled: value.enabled, enabled_at: typeof value.enabled_at === 'string' ? value.enabled_at : null, recovery_remaining: value.recovery_remaining }
}
function codes(value: unknown): MfaCodes {
  const parsed = status(value)
  if (typeof value !== 'object' || value === null || !('recovery_codes' in value) || !Array.isArray(value.recovery_codes)
    || !value.recovery_codes.every((v: unknown) => typeof v === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{8}){3}$/.test(v))) throw new ApiRequestError('恢复码响应无效', 500, 50000)
  return { ...parsed, recovery_codes: value.recovery_codes }
}
/** 管理员本人MFA接口；不接收目标账号ID。 */
export const adminMfaApi = {
  async status(signal?: AbortSignal): Promise<AdminMfaStatus> { return status(await request<unknown>({ url: '/profile/mfa', signal })) },
  async setup(current_password: string): Promise<AdminMfaSetup> {
    const v = await request<unknown>({ url: '/profile/mfa/setup', method: 'POST', data: { current_password } })
    if (typeof v !== 'object' || v === null || !('setup_id' in v) || typeof v.setup_id !== 'string' || !/^[a-f0-9]{64}$/.test(v.setup_id)
      || !('secret' in v) || typeof v.secret !== 'string' || !/^[A-Z2-7]+$/.test(v.secret)
      || !('uri' in v) || typeof v.uri !== 'string' || !v.uri.startsWith('otpauth://totp/')
      || !('expires_at' in v) || typeof v.expires_at !== 'number') throw new ApiRequestError('验证器绑定响应无效', 500, 50000)
    return { setup_id: v.setup_id, secret: v.secret, uri: v.uri, expires_at: v.expires_at }
  },
  async enable(data: MfaProof & { setup_id: string }): Promise<MfaCodes> { return codes(await request<unknown>({ url: '/profile/mfa/enable', method: 'POST', data })) },
  async change(action: 'disable' | 'recovery-codes', data: MfaProof): Promise<MfaCodes> { return codes(await request<unknown>({ url: `/profile/mfa/${action}`, method: 'POST', data })) },
}
