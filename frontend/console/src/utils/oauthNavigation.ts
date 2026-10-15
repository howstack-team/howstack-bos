/** 注册成功时仅允许固定关联页面，原业务返回路径继续使用loginReturnPath校验。 */
export function isOAuthRegistration(state: unknown): boolean {
  return typeof state === 'object' && state !== null && 'oauthBinding' in state && state.oauthBinding === true
}
