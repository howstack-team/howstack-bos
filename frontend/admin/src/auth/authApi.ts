import { request, ApiRequestError } from '../api/httpClient.ts'

/**
 * 运营后台当前管理员资料。
 */
export interface AdminUser {
  /** 管理员标识。 */
  id: number
  /** 管理员登录账号。 */
  username: string
  /** 管理员显示昵称。 */
  nickname: string
  /** 最近一次登录时间，首次登录时为空。 */
  last_login_at: string | null
  /** 是否为不可删除和降权的创始管理员。 */
  is_founder: boolean
  /** 所属部门。 */
  department_id: number | null
}

export interface AdminLoginInput {
  /** 管理员账号。 */
  username: string
  /** 管理员密码。 */
  password: string
  /** 是否在浏览器关闭后继续保留登录状态。 */
  remember?: boolean
}

export interface LoginResponse {
  mfa_required: false
  user: AdminUser
  permissions: string[]
  expires_at: number
}

/** 密码通过但尚未完成第二因素时，没有用户资料和访问会话。 */
export interface MfaLoginChallenge { mfa_required: true; expires_at: number }
export type AdminLoginResponse = LoginResponse | MfaLoginChallenge

/** 校验登录结果的判别字段，挑战响应不能被当作完整登录。 */
function parseLogin(value: unknown): AdminLoginResponse {
  if (typeof value !== 'object' || value === null || !('mfa_required' in value) || typeof value.mfa_required !== 'boolean'
    || !('expires_at' in value) || typeof value.expires_at !== 'number' || !Number.isSafeInteger(value.expires_at)) throw new ApiRequestError('登录响应无效', 500, 50000)
  if (value.mfa_required) return { mfa_required: true, expires_at: value.expires_at }
  if (!('user' in value) || typeof value.user !== 'object' || value.user === null
    || !('id' in value.user) || typeof value.user.id !== 'number' || !Number.isInteger(value.user.id)
    || !('username' in value.user) || typeof value.user.username !== 'string'
    || !('nickname' in value.user) || typeof value.user.nickname !== 'string'
    || !('is_founder' in value.user) || typeof value.user.is_founder !== 'boolean'
    || !('last_login_at' in value.user) || !(value.user.last_login_at === null || typeof value.user.last_login_at === 'string')
    || !('department_id' in value.user) || !(value.user.department_id === null || typeof value.user.department_id === 'number')
    || !('permissions' in value) || !Array.isArray(value.permissions) || !value.permissions.every((p: unknown) => typeof p === 'string')) throw new ApiRequestError('登录响应无效', 500, 50000)
  return { mfa_required: false, expires_at: value.expires_at, user: value.user as AdminUser, permissions: value.permissions }
}

interface ProfileResponse {
  user: AdminUser
  permissions: string[]
}

/**
 * 运营后台认证接口。
 */
export const authApi = {
  /**
   * 使用账号密码登录。
   * @param input 登录凭据
   * @returns 当前管理员和 JWT 过期时间
   */
  async login(input: AdminLoginInput): Promise<AdminLoginResponse> {
    return parseLogin(await request<unknown>({ method: 'POST', url: '/auth/login', data: input }))
  },

  /** 完成第二因素；只有完整登录响应才可更新认证状态。 */
  async verifyMfa(code: string, recovery: boolean): Promise<LoginResponse> {
    const result = parseLogin(await request<unknown>({ method: 'POST', url: '/auth/mfa/verify', data: { code, recovery } }))
    if (result.mfa_required === true) throw new ApiRequestError('第二步登录响应无效', 500, 50000)
    return result
  },
  /** 查询当前 HttpOnly Cookie 对应的管理员。 */
  profile(signal?: AbortSignal): Promise<ProfileResponse> {
    return request<ProfileResponse>({ method: 'GET', url: '/auth/me', signal })
  },

  /**
   * 清除当前浏览器的管理员登录 Cookie。
   * @returns 无业务数据
   */
  logout(): Promise<null> {
    return request<null>({ method: 'POST', url: '/auth/logout' })
  },
}
