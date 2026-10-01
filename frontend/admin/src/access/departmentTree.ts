import type { DepartmentRecord } from './accessApi.ts'

/** Arco TreeSelect 使用字符串 key；表单提交时再转换回部门数字 ID。 */
export interface DepartmentTreeOption {
  key: string
  value: string
  title: string
  children?: DepartmentTreeOption[]
}

/** 按接口给出的同级排序构建部门选择树。 */
export function departmentTreeData(rows: DepartmentRecord[]): DepartmentTreeOption[] {
  const byId = new Map(rows.map((row) => [row.id, {
    key: String(row.id), value: String(row.id), title: row.name,
  } as DepartmentTreeOption]))
  const roots: DepartmentTreeOption[] = []
  for (const row of rows) {
    const current = byId.get(row.id)!
    const parent = row.parent_id === null ? undefined : byId.get(row.parent_id)
    if (!parent || parent === current) roots.push(current)
    else { parent.children ??= []; parent.children.push(current) }
  }
  return roots
}
