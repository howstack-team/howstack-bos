import axios, { AxiosError, type AxiosRequestConfig } from 'axios'

interface ApiEnvelope<T> {
  code: number
  message: string
  data: T
  request_id: string
}

/**
 * 表示运营后台接口返回的可展示错误。
 */
export class ApiRequestError extends Error {
  public readonly status: number
  public readonly code: number
  public readonly data: unknown

  /**
   * 创建接口请求错误。
   * @param message 可展示错误信息
   * @param status HTTP 状态码
   * @param code 稳定业务错误码
   * @param data 结构化错误详情
   */
  constructor(
    message: string,
    status: number,
    code: number,
    data: unknown = null,
  ) {
    super(message)
    this.name = 'ApiRequestError'
    this.status = status
    this.code = code
    this.data = data
  }
}

const httpClient = axios.create({
  baseURL: '/api/admin/v1',
  timeout: 15_000,
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

/**
 * 判断未知数据是否符合统一 API 响应外层结构。
 * @param value 待判断数据
 * @returns 符合统一响应结构时返回 true
 */
function isApiEnvelope(value: unknown): value is ApiEnvelope<unknown> {
  if (typeof value !== 'object' || value === null) return false
  const record = value as Record<string, unknown>

  return typeof record.code === 'number'
    && typeof record.message === 'string'
    && typeof record.request_id === 'string'
    && 'data' in record
}

/**
 * 调用运营后台接口并校验统一响应结构。
 * @param config Axios 请求配置
 * @returns 接口 data 字段
 * @throws ApiRequestError 接口拒绝请求或响应结构无效时抛出
 */
export async function request<T>(config: AxiosRequestConfig): Promise<T> {
  try {
    const response = await httpClient.request<unknown>(config)
    if (!isApiEnvelope(response.data)) {
      throw new ApiRequestError('认证服务返回了无法识别的响应', response.status, 50000)
    }
    if (response.data.code !== 0) {
      throw new ApiRequestError(
        response.data.message || '请求失败，请稍后重试',
        response.status,
        response.data.code,
        response.data.data,
      )
    }

    return response.data.data as T
  } catch (error) {
    if (error instanceof ApiRequestError) throw error
    if (error instanceof AxiosError) {
      const responseData = error.response?.data
      if (isApiEnvelope(responseData)) {
        if (error.response?.status === 403) window.dispatchEvent(new Event('admin:access-changed'))
        throw new ApiRequestError(
          responseData.message || '请求失败，请稍后重试',
          error.response?.status ?? 0,
          responseData.code,
          responseData.data,
        )
      }

      throw new ApiRequestError(
        error.code === 'ECONNABORTED' ? '请求超时，请稍后重试' : '暂时无法连接认证服务',
        error.response?.status ?? 0,
        0,
      )
    }

    throw new ApiRequestError('请求失败，请稍后重试', 0, 0)
  }
}
