import { getAuthOptions } from './authOptionsApi'
import { ApiRequestError, isObject, request } from './httpClient.ts'
import { parseMemberProfile, type MemberProfile } from './memberApi.ts'

/** 会员验证码与第三方登录接口，凭据只经同源请求传输。 */
export const loginPaths = {
  code: '/auth/login-code', sms: '/auth/login-sms', oauthStart: '/auth/oauth/start', oauthComplete: '/auth/oauth/complete', oauthPending: '/auth/oauth/pending', oauthBind: '/auth/oauth/bind',
} as const
export type LoginProvider = 'qq' | 'wechat'
/** 验证码挑战只保存在当前页面内存，时间单位为秒。 */
export interface LoginChallenge { challenge_id: string; expires_in: number; retry_after: number }

/** 接口未实现时明确提示；其余业务错误保留服务器原文。 */
export function loginErrorMessage(error: unknown): string {
  if (error instanceof ApiRequestError && [404, 405, 501].includes(error.status)) return '该登录方式暂未接入，请使用密码登录'
  return error instanceof Error ? error.message : '登录失败，请稍后重试'
}

/** 获取短信验证码挑战，拒绝缺失或无效的限流与过期时间。 */
export async function sendLoginCode(mobile: string): Promise<LoginChallenge> {
  if (!(await getAuthOptions()).sms_login) throw new Error('短信验证码登录暂不可用')
  return parseLoginChallenge(await request('post', loginPaths.code, { mobile }))
}
/** 登录与密码重置共用挑战结构，具体发送动作保持独立。 */
export function parseLoginChallenge(value: unknown): LoginChallenge {
  if (!isObject(value) || typeof value.challenge_id !== 'string' || !value.challenge_id || value.challenge_id.length > 256
    || typeof value.expires_in !== 'number' || !Number.isSafeInteger(value.expires_in) || value.expires_in <= 0 || value.expires_in > 3600
    || typeof value.retry_after !== 'number' || !Number.isSafeInteger(value.retry_after) || value.retry_after <= 0 || value.retry_after > 3600) {
    throw new Error('验证码响应格式无效')
  }
  return { challenge_id: value.challenge_id, expires_in: value.expires_in, retry_after: value.retry_after }
}
/** 后端校验验证码并签发 HttpOnly Cookie；浏览器不保存会话令牌。 */
export async function loginWithSms(mobile: string, code: string, challenge_id: string, remember = false): Promise<MemberProfile> {
  return parseMemberProfile(await request('post', loginPaths.sms, { mobile, code, challenge_id, ...(remember ? { remember_me: true } : {}) }))
}
/** 只接受 QQ 或微信的 HTTPS 授权地址，避免接口异常产生外部重定向。 */
export function parseOAuthUrl(value: unknown, provider: LoginProvider): string {
  if (!isObject(value) || typeof value.url !== 'string') throw new Error('第三方授权响应格式无效')
  let url: URL
  try { url = new URL(value.url) } catch { throw new Error('第三方授权地址无效') }
  const host = provider === 'qq' ? 'graph.qq.com' : 'open.weixin.qq.com'
  if (url.protocol !== 'https:' || url.username || url.password || url.port || url.hostname !== host) throw new Error('第三方授权地址无效')
  return url.href
}
/** 平台 appid、密钥、state 校验与授权码交换均由后端完成。 */
export async function startOAuth(provider: LoginProvider, remember = false): Promise<string> {
  return parseOAuthUrl(await request('post', loginPaths.oauthStart, { provider, ...(remember ? { remember_me: true } : {}) }), provider)
}
/** 提交一次性授权回调，成功响应与原密码登录的会员资料一致。 */
export async function completeOAuth(state: string, code: string): Promise<OAuthCompletion> {
  return parseOAuthCompletion(await request('post', loginPaths.oauthComplete, { state, code }))
}

/** 首次授权不自动创建账号，进入独立关联流程。 */
export type OAuthCompletion = { status: 'authenticated'; user: MemberProfile } | { status: 'binding_required'; provider: LoginProvider; expires_in: number }
export interface OAuthPending { provider: LoginProvider; expires_in: number }
/** 校验服务端判别联合，不向页面传递票据或上游令牌。 */
export function parseOAuthCompletion(value: unknown): OAuthCompletion {
  if (!isObject(value)) throw new Error('第三方登录响应格式无效')
  if (value.status === 'authenticated') return { status: 'authenticated', user: parseMemberProfile(value) }
  if (value.status === 'binding_required') return { status: 'binding_required', ...parseOAuthPending(value) }
  throw new Error('第三方登录响应格式无效')
}
function parseOAuthPending(value: unknown): OAuthPending {
  if (!isObject(value) || !['qq', 'wechat'].includes(String(value.provider)) || typeof value.expires_in !== 'number'
    || !Number.isSafeInteger(value.expires_in) || value.expires_in <= 0 || value.expires_in > 600) throw new Error('账号关联响应格式无效')
  return { provider: value.provider as LoginProvider, expires_in: value.expires_in }
}
/** 待关联身份仅通过HttpOnly Cookie识别，可刷新继续。 */
export async function getOAuthPending(signal?: AbortSignal): Promise<OAuthPending> {
  return parseOAuthPending(await request('get', loginPaths.oauthPending, undefined, signal))
}
/** 重新验证平台账号后建立关联，不使用浏览器传来的会员ID。 */
export async function bindOAuth(account: string, password: string): Promise<MemberProfile> {
  const result = parseOAuthCompletion(await request('post', loginPaths.oauthBind, { account, password }))
  if (result.status !== 'authenticated') throw new Error('账号关联未完成')
  return result.user
}
