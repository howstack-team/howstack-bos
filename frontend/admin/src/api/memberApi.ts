import { request } from './httpClient.ts'

/** 运营后台会员列表项，不包含密码哈希。 */
export interface MemberRecord {
  id: number
  nickname: string
  group_id: number | null
  group_name: string | null
  level_id: number | null
  level_name: string | null
  mobile: string
  email: string | null
  description: string
  status: number
  last_login_at: string | null
  created_at: string
  updated_at: string
}

/** 会员创建或编辑参数。 */
export interface MemberInput {
  nickname: string
  group_id?: number | null
  level_id?: number | null
  mobile: string
  email?: string | null
  description?: string
  password?: string
  status: number
}

/** 运营后台会员管理接口。 */
export const memberApi = {
  list(params: { keyword?: string; status?: number; page: number; page_size: number }) {
    return request<{ data: MemberRecord[]; total: number }>({ url: '/members', params })
  },
  get(id: number) {
    return request<MemberRecord>({ url: `/members/${id}` })
  },
  save(input: MemberInput, id?: number) {
    return request<MemberRecord>({ method: id ? 'PUT' : 'POST', url: id ? `/members/${id}` : '/members', data: input })
  },
  delete(id: number) {
    return request<null>({ method: 'DELETE', url: `/members/${id}` })
  },
}
