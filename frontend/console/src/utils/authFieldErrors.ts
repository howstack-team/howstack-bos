import { ApiRequestError, isObject } from '../api/httpClient'

/** 只映射当前表单已登记的后端字段错误，不把认证失败解释为字段校验。 */
export function authFieldErrors(error: unknown, fields: readonly string[], aliases: Readonly<Record<string, string>> = {}): Record<string, string> {
  if (!(error instanceof ApiRequestError) || ![200, 422].includes(error.status) || !isObject(error.data) || !isObject(error.data.errors)) return {}
  const result: Record<string, string> = {}
  for (const [field, messages] of Object.entries(error.data.errors)) {
    const target = Object.hasOwn(aliases, field) ? aliases[field] : field
    if (fields.includes(target) && Array.isArray(messages) && messages.length && messages.every((message: unknown) => typeof message === 'string')) result[target] = messages.join('；')
  }
  return result
}
