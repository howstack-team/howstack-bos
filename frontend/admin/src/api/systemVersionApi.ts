import { ApiRequestError, request } from './httpClient.ts'

/** 后端版本文件提供的当前代码版本，不表示数据库迁移或远程更新状态。 */
export interface SystemVersion {
  current_version: string
}

/** 读取当前系统版本；失败时不以硬编码版本代替真实信息。 */
export async function getSystemVersion(signal?: AbortSignal): Promise<SystemVersion> {
  const value = await request<unknown>({ url: '/system/version', signal })
  if (typeof value !== 'object' || value === null || !('current_version' in value)
    || typeof value.current_version !== 'string' || !value.current_version.trim()) {
    throw new ApiRequestError('系统版本响应格式无效，请重试', 500, 50000)
  }
  return { current_version: value.current_version }
}
