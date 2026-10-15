/** 隐去登录手机号的中间部分；兼容带国际区号的有效手机号。 */
export function maskMobile(mobile: string): string {
  if (!mobile) return '-'
  if (mobile.length <= 7) return `${mobile.slice(0, 2)}****${mobile.slice(-2)}`
  return `${mobile.slice(0, 3)}****${mobile.slice(-4)}`
}

/** 仅保留邮箱首字母及域名；原值只在本人资料页按需展示。 */
export function maskEmail(email: string): string {
  if (!email) return '-'
  const [name, domain] = email.split('@')
  return `${name.slice(0, 1)}***@${domain}`
}
