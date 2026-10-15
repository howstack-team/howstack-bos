import { getAuthOptions } from './authOptionsApi'
import { notifyMemberSessionChanged } from '../auth/sessionEvents'
import { ApiRequestError, request } from './httpClient.ts'
import { parseLoginChallenge, type LoginChallenge } from './loginApi.ts'

/** 前端密码找回接口约定，独立于会员注册验证码，由后端独立校验。 */
export const passwordResetPaths = { code: '/auth/password-reset-code', reset: '/auth/password-reset' } as const
/** 根据绑定的手机号或邮箱发送重置密码验证码。 */
export async function sendPasswordResetCode(account: string): Promise<LoginChallenge> {
  const options = await getAuthOptions()
  if (!options.password_reset[account.includes('@') ? 'email' : 'mobile']) throw new Error('此密码找回方式暂不可用')
  return parseLoginChallenge(await request('post', passwordResetPaths.code, { account }))
}
/** 服务端验证独立的重置挑战并修改密码；确认密码只在前端校验。 */
export async function resetPassword(account: string, code: string, challenge_id: string, password: string): Promise<void> {
  await request('post', passwordResetPaths.reset, { account, code, challenge_id, password })
  notifyMemberSessionChanged()
}
/** 接口缺失时保留可操作表单并说明待接入，其余错误保留原文。 */
export function passwordResetErrorMessage(error: unknown): string {
  if (error instanceof ApiRequestError && [404, 405, 501].includes(error.status)) return '密码找回暂未接入，请联系服务方协助处理'
  return error instanceof Error ? error.message : '密码重置失败，请稍后重试'
}
