import axios from 'axios'

interface ApiEnvelope<T> { code: number; message: string; data: T }
export interface MemberProfile { id: number; mobile: string; nickname: string }
export interface WalletBalance { currency: string; available_minor: number; frozen_minor: number }

const client = axios.create({ baseURL: '/api/console/v1', withCredentials: true, timeout: 15_000 })

class ApiRequestError extends Error {
  readonly status: number

  constructor(message: string, status: number) {
    super(message)
    this.status = status
  }
}

function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

async function call<T>(method: 'get' | 'post', url: string, body?: unknown): Promise<T> {
  let response
  try {
    response = await client.request<unknown>({ method, url, data: body })
  } catch (error) {
    if (axios.isAxiosError(error) && error.response) {
      const payload: unknown = error.response.data
      const message = isObject(payload) && typeof payload.message === 'string'
        ? payload.message : '请求失败，请稍后重试'
      throw new ApiRequestError(message, error.response.status)
    }
    throw error
  }
  if (!isObject(response.data) || typeof response.data.code !== 'number' || !('data' in response.data)) {
    throw new Error('服务器响应格式无效')
  }
  const envelope = response.data as unknown as ApiEnvelope<unknown>
  if (envelope.code !== 0) throw new Error(envelope.message || '请求失败')
  return envelope.data as T
}

function profileFrom(value: unknown): MemberProfile {
  if (!isObject(value) || !isObject(value.user) || typeof value.user.id !== 'number'
    || typeof value.user.mobile !== 'string' || typeof value.user.nickname !== 'string') {
    throw new Error('会员资料响应格式无效')
  }
  return value.user as unknown as MemberProfile
}

/** 查询当前登录会员；401 代表尚未登录。 */
export async function getMember(): Promise<MemberProfile | null> {
  try { return profileFrom(await call<unknown>('get', '/auth/me')) }
  catch (error) { if (error instanceof ApiRequestError && error.status === 401) return null; throw error }
}

/** 使用会员手机号和密码建立 HttpOnly Cookie 会话。 */
export async function loginMember(mobile: string, password: string): Promise<MemberProfile> {
  return profileFrom(await call<unknown>('post', '/auth/login', { mobile, password }))
}

export async function logoutMember(): Promise<void> {
  await call<null>('post', '/auth/logout')
}

export async function getWalletBalance(): Promise<WalletBalance> {
  const value = await call<unknown>('get', '/wallet')
  if (!isObject(value) || value.currency !== 'CNY'
    || typeof value.available_minor !== 'number' || typeof value.frozen_minor !== 'number') {
    throw new Error('余额响应格式无效')
  }
  return value as unknown as WalletBalance
}
