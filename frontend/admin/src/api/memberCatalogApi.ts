import { request } from './httpClient.ts'

export interface MemberGroupRecord { id: number; name: string; code: string; sort_order: number; description: string; status: number; created_at: string; updated_at: string }
export interface MemberLevelRecord { id: number; name: string; code: string; level_order: number; growth_threshold: number; description: string; status: number; created_at: string; updated_at: string }
export interface MemberCatalogInput { name: string; code: string; sort_order?: number; level_order?: number; growth_threshold?: number; description?: string; status: number }
export interface MemberOption { id: number; name: string }

export const memberCatalogApi = {
  groups(params: { keyword?: string; status?: number; page: number; page_size: number }) {
    return request<{ data: MemberGroupRecord[]; total: number }>({ url: '/member-groups', params })
  },
  saveGroup(input: MemberCatalogInput, id?: number) { return request<MemberGroupRecord>({ method: id ? 'PUT' : 'POST', url: id ? `/member-groups/${id}` : '/member-groups', data: input }) },
  deleteGroup(id: number) { return request<null>({ method: 'DELETE', url: `/member-groups/${id}` }) },
  levels(params: { keyword?: string; status?: number; page: number; page_size: number }) {
    return request<{ data: MemberLevelRecord[]; total: number }>({ url: '/member-levels', params })
  },
  saveLevel(input: MemberCatalogInput, id?: number) { return request<MemberLevelRecord>({ method: id ? 'PUT' : 'POST', url: id ? `/member-levels/${id}` : '/member-levels', data: input }) },
  deleteLevel(id: number) { return request<null>({ method: 'DELETE', url: `/member-levels/${id}` }) },
  options() { return request<{ groups: MemberOption[]; levels: MemberOption[] }>({ url: '/member-catalog/options' }) },
}
