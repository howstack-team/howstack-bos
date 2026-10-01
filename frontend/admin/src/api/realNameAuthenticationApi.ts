import { request } from './httpClient.ts'

export type AuthenticationType = 'personal' | 'enterprise'
export type AuthenticationStatus = 'pending' | 'approved' | 'rejected'
export interface RealNameAuthenticationRecord {
  id: number; member_id: number; mobile: string; nickname: string; authentication_type: AuthenticationType
  status: AuthenticationStatus; subject_name: string; id_type: string; id_number_masked: string
  company_name: string | null; registration_code_masked: string | null; legal_representative: string | null
  upgrade_from_id: number | null; reviewed_by: number | null; rejection_reason: string
  submitted_at: string; reviewed_at: string | null; created_at: string; updated_at: string
}

export const realNameAuthenticationApi = {
  list(params: { keyword?: string; type?: AuthenticationType; status?: AuthenticationStatus; page: number; page_size: number }) {
    return request<{ data: RealNameAuthenticationRecord[]; total: number }>({ url: '/real-name-authentications', params })
  },
  get(id: number) { return request<RealNameAuthenticationRecord>({ url: `/real-name-authentications/${id}` }) },
  review(id: number, decision: 'approved' | 'rejected', reason = '') {
    return request<RealNameAuthenticationRecord>({ method: 'PUT', url: `/real-name-authentications/${id}/review`, data: { decision, reason } })
  },
}
