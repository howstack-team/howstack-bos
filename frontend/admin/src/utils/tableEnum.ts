/** Arco 原生业务状态圆点的语义，启用 / 禁用不使用此映射。 */
export type BusinessStatus = 'default' | 'processing' | 'success' | 'warning' | 'error'

/** 流程状态的中文文案与颜色语义。 */
export interface StatusMeta { text: string; status: BusinessStatus }
/** 普通分类的中文文案。 */
export interface CategoryMeta { text: string }
/** 按接口原始枚举值索引的状态映射。 */
export type StatusMap = Readonly<Record<string, StatusMeta>>
/** 按接口原始枚举值索引的分类映射。 */
export type CategoryMap = Readonly<Record<string, CategoryMeta>>

/** 从同一份枚举映射生成查询及表头筛选文案，不改变接口值。 */
export function enumLabels(mapping: Readonly<Record<string, { text: string }>>): Record<string, string> {
  return Object.fromEntries(Object.entries(mapping).map(([key, item]) => [key, item.text]))
}

/** 只读取已声明枚举，避免未知值命中对象原型；空值与数值 0 分别处理。 */
export function enumMeta<T>(mapping: Readonly<Record<string, T>>, value: unknown): T | undefined {
  if (typeof value !== 'string' && typeof value !== 'number') return undefined
  return Object.hasOwn(mapping, value) ? mapping[String(value)] : undefined
}

/** 普通分类或辅助状态使用中文纯文本，未知值保留原文，空值显示占位符。 */
export function enumText(mapping: Readonly<Record<string, { text: string }>>, value: unknown): string | number {
  const meta = enumMeta(mapping, value)
  if (meta) return meta.text
  if (value == null || typeof value === 'string' && value.trim() === '') return '-'
  return typeof value === 'string' || typeof value === 'number' ? value : String(value)
}
