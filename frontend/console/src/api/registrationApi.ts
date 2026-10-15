import { isObject, request } from './httpClient.ts'
import { parseMemberProfile, type MemberProfile } from './memberApi.ts'
export type RegistrationMethod = 'mobile' | 'email'
export interface RegistrationOptions { mobile_enabled: 0 | 1; email_enabled: 0 | 1; mobile_verification: 0 | 1; email_verification: 0 | 1 }
/** 设置读取失败时不擅自开放注册。 */
export function parseRegistrationOptions(value: unknown): RegistrationOptions {
  if (!isObject(value) || !['mobile_enabled', 'email_enabled', 'mobile_verification', 'email_verification'].every(key => value[key] === 0 || value[key] === 1)) throw new Error('注册设置响应格式无效')
  return { mobile_enabled: value.mobile_enabled as 0 | 1, email_enabled: value.email_enabled as 0 | 1,
    mobile_verification: value.mobile_verification as 0 | 1, email_verification: value.email_verification as 0 | 1 }
}
/** 建立绑定验证码的 HttpOnly 临时注册会话。 */
export async function getRegistrationOptions(signal?: AbortSignal): Promise<RegistrationOptions> {
  return parseRegistrationOptions(await request('get', '/auth/registration', undefined, signal))
}
export async function sendRegistrationCode(method: RegistrationMethod, account: string): Promise<{ challenge_id: string; expires_in: number; retry_after: number }> {
  const value = await request('post', '/auth/registration-code', { method, account })
  if (!isObject(value) || typeof value.challenge_id !== 'string' || !/^[a-f0-9]{32}$/.test(value.challenge_id)
    || value.expires_in !== 300 || value.retry_after !== 60) throw new Error('验证码响应格式无效，请稍后重试')
  return { challenge_id: value.challenge_id, expires_in: value.expires_in, retry_after: value.retry_after }
}
/** 注册后回登录页；敏感值只用于本次提交。 */
export async function registerMember(input: { method: RegistrationMethod; account: string; password: string; code: string; challenge_id: string }): Promise<MemberProfile> {
  return parseMemberProfile(await request('post', '/auth/register', input))
}
