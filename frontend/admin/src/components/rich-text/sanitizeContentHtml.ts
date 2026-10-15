import DOMPurify from 'dompurify'

/** 只渲染编辑器支持的内容；服务端仍负责保存前的权威清理。 */
export function sanitizeContentHtml(value: string): string {
  const fragment = DOMPurify.sanitize(value, {
    ALLOWED_TAGS: ['p', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'span', 'blockquote', 'ul', 'ol', 'li', 'pre', 'code', 'hr', 'a', 'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td'],
    ALLOWED_ATTR: ['href', 'src', 'alt', 'title', 'colspan', 'rowspan', 'start', 'style'],
    RETURN_DOM_FRAGMENT: true,
  })
  fragment.querySelectorAll<HTMLElement>('[style]').forEach(element => {
    const align = element.style.textAlign
    element.removeAttribute('style')
    if (['left', 'center', 'right', 'justify'].includes(align)) element.style.textAlign = align
  })
  fragment.querySelectorAll('a').forEach(element => {
    element.setAttribute('target', '_blank')
    element.setAttribute('rel', 'noopener noreferrer')
  })
  const container = document.createElement('div')
  container.append(fragment)
  return container.innerHTML
}

/** 编辑器图片、链接仅接受 HTTP(S)，图片也接受宿主公开图片地址。 */
export function validateContentUrl(value: string, image = false): string | null {
  const url = value.trim()
  if (image && /^\/api\/public\/v1\/(?:content\/images|website\/images|files)\/[a-f0-9]{32}$/.test(url)) return url
  try {
    const parsed = new URL(url)
    return ['http:', 'https:'].includes(parsed.protocol) && !parsed.username && !parsed.password ? parsed.href : null
  } catch { return null }
}
