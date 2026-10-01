import { request } from '../api/httpClient.ts'

/** 后台授权的数据范围类型。 */
export type DataScopeMode = 'all' | 'department_and_children' | 'department' | 'self' | 'custom'

/** 管理员列表项，不包含密码哈希。 */
export interface AdministratorRecord {
  id: number
  username: string
  nickname: string
  employee_no: string | null
  mobile: string | null
  email: string | null
  description: string
  department_id: number | null
  created_by: number | null
  is_founder: boolean
  status: number
  role_ids: number[]
  last_login_at: string | null
  created_at: string
}

/** 角色及其操作和数据范围授权。 */
export interface RoleRecord {
  id: number
  name: string
  code: string
  sort_order: number
  description: string
  data_scope: DataScopeMode
  status: number
  is_system: boolean
  permissions: string[]
  department_ids: number[]
  created_at: string
  updated_at: string
}

/** 部门树节点。 */
export interface DepartmentRecord {
  id: number
  name: string
  parent_id: number | null
  sort_order: number
  description: string
  status: number
  is_root: boolean
}

/** 角色写入数据。 */
export type RoleInput = Omit<RoleRecord, 'id' | 'created_at' | 'updated_at' | 'is_system'>

/** 管理员写入数据。 */
export interface AdministratorInput {
  username: string
  nickname: string
  employee_no?: string | null
  mobile?: string | null
  email?: string | null
  description?: string
  password?: string
  department_id: number | null
  status: number
  role_ids: number[]
}

/** 部门写入数据。 */
export type DepartmentInput = Omit<DepartmentRecord, 'id' | 'is_root'>

/** 统一的运营后台授权接口。 */
export const accessApi = {
  administrators(params: { keyword?: string; page?: number; page_size?: number }) {
    return request<{ data: AdministratorRecord[]; total: number }>({ url: '/administrators', params })
  },
  administrator(id: number) {
    return request<AdministratorRecord>({ url: `/administrators/${id}` })
  },
  saveAdministrator(input: AdministratorInput, id?: number) {
    return request<AdministratorRecord>({ method: id ? 'PUT' : 'POST', url: id ? `/administrators/${id}` : '/administrators', data: input })
  },
  deleteAdministrator(id: number) {
    return request<null>({ method: 'DELETE', url: `/administrators/${id}` })
  },
  roles() { return request<RoleRecord[]>({ url: '/roles' }) },
  role(id: number) { return request<RoleRecord>({ url: `/roles/${id}` }) },
  saveRole(input: RoleInput, id?: number) {
    return request<RoleRecord>({ method: id ? 'PUT' : 'POST', url: id ? `/roles/${id}` : '/roles', data: input })
  },
  deleteRole(id: number) { return request<null>({ method: 'DELETE', url: `/roles/${id}` }) },
  departments() { return request<DepartmentRecord[]>({ url: '/departments' }) },
  saveDepartment(input: DepartmentInput, id?: number) {
    return request<DepartmentRecord>({ method: id ? 'PUT' : 'POST', url: id ? `/departments/${id}` : '/departments', data: input })
  },
  deleteDepartment(id: number) { return request<null>({ method: 'DELETE', url: `/departments/${id}` }) },
  permissions() { return request<Record<string, { label: string; group: string }>>({ url: '/permissions' }) },
}
