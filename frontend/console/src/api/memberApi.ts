import { ApiRequestError, apiPaths, isObject, request } from './httpClient.ts'

/** 后端公开的本人资料；不含密码、令牌或管理员权限。 */
export interface MemberProfile { id: string; mobile: string | null; email: string | null; nickname: string }
/** 人民币钱包；两类余额的单位均为整数分。 */
export interface WalletBalance { currency: 'CNY'; available_minor: number; frozen_minor: number }

/** 校验会员响应并仅保留允许展示的字段。 */
export function parseMemberProfile(value: unknown): MemberProfile {
  if (!isObject(value) || !isObject(value.user) || typeof value.user.id !== 'string' || !/^[1-9][0-9]{7,8}$/.test(value.user.id)
    || (value.user.mobile !== null && typeof value.user.mobile !== 'string')
    || (value.user.email !== null && typeof value.user.email !== 'string') || typeof value.user.nickname !== 'string') {
    throw new Error('会员资料响应格式无效')
  }
  return { id: value.user.id, mobile: value.user.mobile, email: value.user.email, nickname: value.user.nickname }
}

/** 校验金额单位及币种，防止格式错误被展示成有效余额。 */
export function parseWalletBalance(value: unknown): WalletBalance {
  if (!isObject(value) || value.currency !== 'CNY'
    || typeof value.available_minor !== 'number' || !Number.isSafeInteger(value.available_minor) || value.available_minor < 0
    || typeof value.frozen_minor !== 'number' || !Number.isSafeInteger(value.frozen_minor) || value.frozen_minor < 0) {
    throw new Error('余额响应格式无效')
  }
  return { currency: value.currency, available_minor: value.available_minor, frozen_minor: value.frozen_minor }
}

/** 查询当前登录会员；401 代表匿名，支持取消在途请求。 */
export async function getMember(signal?: AbortSignal): Promise<MemberProfile | null> {
  try { return parseMemberProfile(await request('get', apiPaths.member, undefined, signal)) }
  catch (error) { if (error instanceof ApiRequestError && error.status === 401) return null; throw error }
}

/** 使用手机号或邮箱及密码建立后端 HttpOnly Cookie 会话，不在浏览器保存凭据。 */
export async function loginMember(account: string, password: string, remember = false): Promise<MemberProfile> {
  return parseMemberProfile(await request('post', apiPaths.login, { account, password, ...(remember ? { remember_me: true } : {}) }))
}

/** 请求后端清除当前会员会话；失败时保留已登录界面。 */
export async function logoutMember(): Promise<void> { await request('post', apiPaths.logout) }

/** 查询本人资金账户，不接受可篡改的会员 ID。 */
export async function getWalletBalance(signal?: AbortSignal): Promise<WalletBalance> {
  return parseWalletBalance(await request('get', apiPaths.wallet, undefined, signal))
}
