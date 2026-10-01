/** 表格纯文本单元格的空值占位；保留 0、false 等有效值。 */
export function tableCellText(value: unknown): string | number {
  if (value == null || typeof value === 'string' && value.trim() === '') return '-'
  return typeof value === 'string' || typeof value === 'number' ? value : String(value)
}
