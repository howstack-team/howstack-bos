/**
 * 规范化允许在新窗口打开的外部帮助文档地址。
 * @param value 构建环境提供的原始地址
 * @returns 合法的 HTTP(S) 地址，未配置或格式无效时返回空字符串
 */
function normalizeExternalHelpUrl(value: string | undefined): string {
  const normalizedValue = value?.trim() ?? ''
  if (!normalizedValue) return ''

  try {
    const url = new URL(normalizedValue)
    return url.protocol === 'https:' || url.protocol === 'http:' ? url.toString() : ''
  } catch {
    return ''
  }
}

/** 管理员忘记密码帮助文档地址，未配置时登录页隐藏帮助入口。 */
export const passwordHelpUrl = normalizeExternalHelpUrl(import.meta.env.VITE_PASSWORD_HELP_URL)
