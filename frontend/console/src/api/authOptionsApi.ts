import { isObject, request } from './httpClient'
export interface AuthOptions { sms_login: boolean; password_reset: { mobile: boolean; email: boolean }; oauth_providers: string[] }
/** 建立验证码浏览器绑定，同时读取后端真实能力；不在浏览器保存绑定凭据。 */
export async function getAuthOptions(signal?: AbortSignal): Promise<AuthOptions> {
  const data = await request('get', '/auth/options', undefined, signal)
  if (!isObject(data) || typeof data.sms_login !== 'boolean' || !isObject(data.password_reset)
    || typeof data.password_reset.mobile !== 'boolean' || typeof data.password_reset.email !== 'boolean'
    || !Array.isArray(data.oauth_providers) || !data.oauth_providers.every(item => typeof item === 'string')) throw new Error('认证配置响应格式无效')
  return { sms_login: data.sms_login, password_reset: { mobile: data.password_reset.mobile, email: data.password_reset.email }, oauth_providers: data.oauth_providers }
}
