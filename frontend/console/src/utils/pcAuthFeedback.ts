import { Message } from '@arco-design/web-react'
import { authFieldErrors } from './authFieldErrors'

/** PC 认证表单的字段错误与全局反馈互斥；Mobile 不导入此模块。 */
export function reportPcAuthError(error: unknown, setFields: (fields: Record<string, { error: { message: string } }>) => void, fields: readonly string[], format: (error: unknown) => string, aliases: Readonly<Record<string, string>> = {}): void {
  const errors = authFieldErrors(error, fields, aliases)
  if (Object.keys(errors).length) setFields(Object.fromEntries(Object.entries(errors).map(([key, message]) => [key, { error: { message } }])))
  else Message.error(format(error))
}
