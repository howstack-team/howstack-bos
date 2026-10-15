import axios, { type AxiosInstance } from 'axios'

/** 会员 API 地址集中定义，与 Webman 的 Cookie 会话契约一致。 */
export const apiPaths = {
  base: '/api/console/v1', member: '/auth/me', login: '/auth/login', logout: '/auth/logout', wallet: '/wallet',
} as const
let memberGeneration = 0
let memberRequests = new AbortController()
/** 切换会话时阻止旧账号的查询及迟到写响应更新页面。 */
export function rotateMemberRequests(): void {
  memberGeneration++
  memberRequests.abort()
  memberRequests = new AbortController()
}

const client = axios.create({ baseURL: apiPaths.base, withCredentials: true, timeout: 15_000 })

/** 主题宿主只允许使用同源会员API，避免将会话发送给外部地址。 */
export function configureApiBase(base: string): void {
  if (base !== apiPaths.base) throw new Error('会员API入口无效')
  client.defaults.baseURL = base
}

/** HTTP 失败保留状态码，供会话失效及无权限提示使用。 */
export class ApiRequestError extends Error {
  readonly status: number
  readonly data: unknown
  constructor(message: string, status: number, data: unknown = null) {
    super(message)
    this.name = 'ApiRequestError'
    this.status = status
    this.data = data
  }
}

/** 校验 API 与浏览器存储中的对象边界，排除数组和 null。 */
export function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/** 解包统一响应；业务数据由各 API 解析器继续校验。 */
async function requestUsing(client: AxiosInstance, method: 'get' | 'post', url: string, body?: unknown, signal?: AbortSignal): Promise<unknown> {
  let response
  try {
    response = await client.request<unknown>({ method, url, data: body, signal })
  } catch (error) {
    if (axios.isCancel(error)) throw error
    if (axios.isAxiosError(error)) {
      const payload: unknown = error.response?.data
      const message = isObject(payload) && typeof payload.message === 'string' && payload.message
        ? payload.message : error.code === 'ECONNABORTED' ? '请求超时，请稍后重试' : '请求失败，请检查网络后重试'
      throw new ApiRequestError(message, error.response?.status ?? 0, isObject(payload) ? payload.data : null)
    }
    throw error
  }
  if (!isObject(response.data) || typeof response.data.code !== 'number'
    || typeof response.data.message !== 'string' || !('data' in response.data)) throw new Error('服务器响应格式无效')
  if (response.data.code !== 0) throw new ApiRequestError(response.data.message || '请求失败', response.status, response.data.data)
  return response.data.data
}

/** 同源会员接口，按原有统一响应解包。 */
export function request(method: 'get' | 'post', url: string, body?: unknown, signal?: AbortSignal): Promise<unknown> {
  const generation = memberGeneration
  const combined = signal ? AbortSignal.any([signal, memberRequests.signal]) : memberRequests.signal
  return requestUsing(client, method, url, body, combined).then(result => {
    if (generation !== memberGeneration) throw new axios.CanceledError('会话已变更')
    return result
  }).catch(error => {
    if (generation !== memberGeneration) throw new axios.CanceledError('会话已变更')
    throw error
  })
}

const publicClient = axios.create({ baseURL: '/api/public/v1', withCredentials: false, timeout: 15_000 })
/** 匿名产品目录与报价不依赖会员会话，也不访问后台接口。 */
export function publicRequest(method: 'get' | 'post', url: string, body?: unknown, signal?: AbortSignal): Promise<unknown> {
  return requestUsing(publicClient, method, url, body, signal)
}
