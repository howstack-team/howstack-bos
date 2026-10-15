/** 对已取得的完整数据集应用枚举筛选和排序；禁止用于服务端分页结果。 */
export function applyClientTableQuery<T extends object>(rows: readonly T[], params: Readonly<Record<string, string>>, enumFields: readonly string[] = []): T[] {
  const value = (row: T, key: string): unknown => (row as Record<string, unknown>)[key]
  const filtered = rows.filter(row => enumFields.every(key => params[key] === undefined || String(value(row, key)) === params[key]))
  const key = params.sort_by
  if (!key) return filtered
  const direction = params.sort_order === 'desc' ? -1 : 1
  return filtered.sort((a, b) => {
    const left = value(a, key), right = value(b, key)
    const leftEmpty = left === null || left === undefined || left === ''
    const rightEmpty = right === null || right === undefined || right === ''
    if (leftEmpty !== rightEmpty) return leftEmpty ? 1 : -1
    const compared = leftEmpty ? 0 : typeof left === 'number' && typeof right === 'number'
      ? left - right : String(left).localeCompare(String(right), 'zh-CN')
    if (compared !== 0) return compared * direction
    const firstId = value(a, 'id') ?? value(a, 'application_id')
    const secondId = value(b, 'id') ?? value(b, 'application_id')
    return typeof firstId === 'number' && typeof secondId === 'number'
      ? secondId - firstId : String(secondId).localeCompare(String(firstId))
  })
}
