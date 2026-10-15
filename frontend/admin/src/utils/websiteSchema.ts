/** 官网主题支持的有限 JSON 值；表单不执行主题提供的脚本。 */
export type WebsiteValue = string | number | boolean | null | WebsiteValue[] | { [key: string]: WebsiteValue }
export type WebsiteObject = { [key: string]: WebsiteValue }
/** 与宿主主题 Schema 子集对应的受控字段声明。 */
export interface WebsiteSchema {
  type: 'object' | 'array' | 'string' | 'integer' | 'number' | 'boolean'
  title?: string; description?: string; properties?: Record<string, WebsiteSchema>; required?: string[]
  additionalProperties?: false; items?: WebsiteSchema; enum?: WebsiteValue[]
  minItems?: number; maxItems?: number; minLength?: number; maxLength?: number; minimum?: number; maximum?: number
  pattern?: string; format?: 'color' | 'image-id'; 'x-widget'?: string; default?: WebsiteValue
}
export function websiteObject(value: unknown): value is Record<string, unknown> { return typeof value === 'object' && value !== null && !Array.isArray(value) }
function jsonValue(value: unknown, depth = 0): value is WebsiteValue {
  return depth <= 8 && (value === null || typeof value === 'string' || typeof value === 'boolean' || typeof value === 'number' && Number.isFinite(value)
    || Array.isArray(value) && value.every(item => jsonValue(item, depth + 1))
    || websiteObject(value) && Object.values(value).every(item => jsonValue(item, depth + 1)))
}
/** API 边界检查声明，拒绝外部引用、执行钩子、非法深度和不完整的字段定义。 */
export function isWebsiteSchema(value: unknown, depth = 0): value is WebsiteSchema {
  if (depth > 8 || !websiteObject(value) || !['object', 'array', 'string', 'integer', 'number', 'boolean'].includes(String(value.type))) return false
  const keys = ['type', 'title', 'description', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'minItems', 'maxItems', 'minLength', 'maxLength', 'minimum', 'maximum', 'pattern', 'format', 'x-widget', 'default']
  if (value.default !== undefined && !jsonValue(value.default)) return false
  if (Object.keys(value).some(key => !keys.includes(key))) return false
  if (['title', 'description', 'pattern'].some(key => value[key] !== undefined && typeof value[key] !== 'string')) return false
  if (['minItems', 'maxItems', 'minLength', 'maxLength'].some(key => value[key] !== undefined && (!Number.isSafeInteger(value[key]) || Number(value[key]) < 0))) return false
  if (['minimum', 'maximum'].some(key => value[key] !== undefined && (typeof value[key] !== 'number' || !Number.isFinite(value[key])))) return false
  if (value.format !== undefined && !['color', 'image-id'].includes(String(value.format))) return false
  if (value['x-widget'] !== undefined && !['input', 'textarea', 'image', 'color', 'select', 'switch', 'number', 'list'].includes(String(value['x-widget']))) return false
  if (value.enum !== undefined && (!Array.isArray(value.enum) || !value.enum.length || value.enum.length > 100 || !value.enum.every(item => jsonValue(item)))) return false
  if (value.type === 'object') {
    if (value.additionalProperties !== false || !websiteObject(value.properties)) return false
    if (!Object.entries(value.properties).every(([key, child]) => /^[a-z][a-z0-9_]{0,63}$/.test(key) && isWebsiteSchema(child, depth + 1))) return false
    if (value.required !== undefined && (!Array.isArray(value.required) || !value.required.every(key => typeof key === 'string' && Object.hasOwn(value.properties as object, key)))) return false
  }
  if (value.type === 'array' && (!Number.isSafeInteger(value.maxItems) || Number(value.maxItems) > 100 || !isWebsiteSchema(value.items, depth + 1))) return false
  return value.type !== 'string' || Number.isSafeInteger(value.maxLength) && Number(value.maxLength) <= 10000
}
/** 校验类型和边界并保持错误原值；PCRE pattern 由服务端执行，不在浏览器转换或执行。 */
export function validateWebsiteValue(value: unknown, schema: WebsiteSchema, path = 'settings'): Record<string, string> {
  const errors: Record<string, string> = {}, fail = (text: string) => { errors[path] = text }
  const typeValid = schema.type === 'object' ? websiteObject(value) : schema.type === 'array' ? Array.isArray(value)
    : schema.type === 'integer' ? Number.isSafeInteger(value) : schema.type === 'number' ? typeof value === 'number' && Number.isFinite(value) : typeof value === schema.type
  if (!typeValid) { fail('请填写有效的字段值'); return errors }
  if (schema.enum && !schema.enum.some(option => JSON.stringify(option) === JSON.stringify(value))) fail('请选择有效选项')
  if (schema.type === 'object' && websiteObject(value)) {
    for (const key of schema.required ?? []) if (!Object.hasOwn(value, key)) errors[`${path}.${key}`] = '请填写此字段'
    for (const [key, child] of Object.entries(value)) {
      if (!Object.hasOwn(schema.properties ?? {}, key)) errors[`${path}.${key}`] = '未声明的设置字段'
      else Object.assign(errors, validateWebsiteValue(child, schema.properties![key], `${path}.${key}`))
    }
  } else if (schema.type === 'array' && Array.isArray(value)) {
    if (value.length < (schema.minItems ?? 0) || value.length > schema.maxItems!) fail('项目数量超出允许范围')
    value.forEach((child, i) => Object.assign(errors, validateWebsiteValue(child, schema.items!, `${path}.${i}`)))
  } else if (typeof value === 'string') {
    if ([...value].length < (schema.minLength ?? 0) || [...value].length > schema.maxLength!) fail('文字长度超出允许范围')
    if (schema.format === 'color' && !/^#[a-fA-F0-9]{6}$/.test(value)) fail('请使用六位十六进制颜色')
    if (schema.format === 'image-id' && value !== '' && !/^[a-f0-9]{32}$/.test(value)) fail('请选择已上传完成的图片')
  } else if (typeof value === 'number' && (value < (schema.minimum ?? -Infinity) || value > (schema.maximum ?? Infinity))) fail('数值超出允许范围')
  return errors
}
/** 列表新增项的明确初值；必填指键必须存在，不强迫允许为空的图片或文字非空。 */
export function newWebsiteValue(schema: WebsiteSchema): WebsiteValue {
  if (schema.default !== undefined) return structuredClone(schema.default)
  if (schema.enum) return structuredClone(schema.enum[0])
  if (schema.type === 'object') return Object.fromEntries((schema.required ?? []).map(key => [key, newWebsiteValue(schema.properties![key])]))
  if (schema.type === 'array') return Array.from({ length: schema.minItems ?? 0 }, () => newWebsiteValue(schema.items!))
  if (schema.type === 'boolean') return false
  return schema.type === 'string' ? '' : schema.minimum ?? 0
}
/** 导航目标只接受页面稳定标识或完整 HTTP/HTTPS 地址。 */
export function websiteLinkErrors(value: WebsiteObject, themeId: string, pages: Array<{ id: string; kind: string }>): Record<string, string> {
  const errors: Record<string, string> = {}
  for (const group of ['navigation', 'footer_links']) {
    for (const [index, row] of (Array.isArray(value[group]) ? value[group] : []).entries()) {
      if (!websiteObject(row)) continue
      const path = `settings.${group}.${index}`
      if (typeof row.label !== 'string' || !row.label.trim()) errors[`${path}.label`] = '请填写链接名称'
      if (row.target === 'core' && !pages.some(page => page.kind === 'core' && page.id === row.core_page)) errors[`${path}.core_page`] = '请选择核心页面'
      else if (row.target === 'theme_page' && (row.theme_id !== themeId || !pages.some(page => page.kind === 'custom' && page.id === row.page_id))) errors[`${path}.page_id`] = '请选择主题页面'
      else if (row.target === 'external') {
        let valid = false
        try { const text = String(row.url), url = new URL(text); valid = ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password
          && ![...text].some(char => char === '\\' || char.charCodeAt(0) <= 32 || char.charCodeAt(0) === 127) } catch { /* 保留原始输入并提示。 */ }
        if (!valid) errors[`${path}.url`] = '请填写完整的 HTTP/HTTPS 链接'
      }
    }
  }
  return errors
}
