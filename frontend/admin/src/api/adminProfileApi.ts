import { request } from './httpClient'

/** 个人资料不包含角色、部门、账户状态或密码哈希。 */
export interface AdminProfile {
  id: number
  username: string
  nickname: string
  employee_no: string | null
  mobile: string | null
  email: string | null
}

export type AdminProfileInput = Pick<AdminProfile, 'employee_no' | 'mobile' | 'email'>
export interface AdminPasswordInput {
  current_password: string
  new_password: string
  new_password_confirmation: string
}

/** 所有操作仅作用于后端认证上下文中的本人。 */
export const adminProfileApi = {
  get: (signal?: AbortSignal) => request<AdminProfile>({ url: '/profile', signal }),
  update: (data: AdminProfileInput) => request<AdminProfile>({ url: '/profile', method: 'PUT', data }),
  password: (data: AdminPasswordInput) => request<null>({ url: '/profile/password', method: 'POST', data }),
}
