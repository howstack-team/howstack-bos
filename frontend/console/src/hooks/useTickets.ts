import { useEffect, useRef } from 'react'
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useHistory, useLocation } from 'react-router-dom'
import axios from 'axios'
import { useMemberAuth } from '../auth/AuthContext'
import { ticketApi, ticketStatuses } from '../api/ticketApi'
import { ticketPagination, ticketSubmission } from '../../../packages/support-tickets'
import { getMemberServices } from '../api/serviceApi'

export function useTickets() {
  const { member } = useMemberAuth(), location = useLocation(), history = useHistory()
  const { page, pageSize } = ticketPagination(location.search), raw = new URLSearchParams(location.search).get('status') ?? ''
  const status = Object.hasOwn(ticketStatuses, raw) ? raw : ''
  const query = useQuery({ queryKey: ['console', 'tickets', member?.id, page, pageSize, status], queryFn: ({ signal }) => ticketApi.list(page, pageSize, status, signal), retry: false, staleTime: 0, enabled: Boolean(member) })
  function change(next: number, size = pageSize, state = status) {
    const params = new URLSearchParams(location.search); params.set('page', String(size !== pageSize || state !== status ? 1 : next)); params.set('page_size', String(size))
    if (state) params.set('status', state); else params.delete('status')
    history.push({ pathname: location.pathname, search: params.toString() })
  }
  return { ...query, page, pageSize, status, change }
}
export function useTicketOptions() {
  const { member } = useMemberAuth()
  const types = useQuery({ queryKey: ['console', 'ticket-types', member?.id], queryFn: ({ signal }) => ticketApi.types(signal), retry: false, staleTime: 0 })
  const services = useInfiniteQuery({ queryKey: ['console', 'ticket-services', member?.id], initialPageParam: 1,
    queryFn: ({ pageParam, signal }) => getMemberServices(pageParam, signal), getNextPageParam: (last, pages) => pages.reduce((total, item) => total + item.data.length, 0) < last.total ? pages.length + 1 : undefined, retry: false })
  return { types, services, serviceRows: services.data?.pages.flatMap(page => page.data) ?? [] }
}
export function useTicketDetail(id: number) {
  const { member } = useMemberAuth()
  return useQuery({ queryKey: ['console', 'tickets', member?.id, 'detail', id], queryFn: ({ signal }) => ticketApi.detail(id, signal), retry: false, staleTime: 0, enabled: Boolean(member && Number.isSafeInteger(id) && id > 0) })
}
/** 请求失败保留命令键，内容变化换键，成功后清空；取消请求不反馈旧会话错误。 */
export function useTicketCommand() {
  const client = useQueryClient(), attempt = useRef<ReturnType<typeof ticketSubmission> | null>(null), locked = useRef(false), mounted = useRef(true)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  const mutation = useMutation({ mutationFn: ({ action, id, values, key }: { action: 'create' | 'reply' | 'close'; id: number | null; values: object; key: string }) => ticketApi.command(action, id, values, key), retry: false })
  async function submit(action: 'create' | 'reply' | 'close', id: number | null, values: object) {
    if (locked.current) return
    locked.current = true; attempt.current = ticketSubmission(attempt.current, [action, id, values])
    try {
      const result = await mutation.mutateAsync({ action, id, values, key: attempt.current.key })
      if (!mounted.current) return
      attempt.current = null
      void client.invalidateQueries({ queryKey: ['console', 'tickets'] })
      return result
    } catch (error) {
      if (!mounted.current || axios.isCancel(error)) return
      void client.invalidateQueries({ queryKey: ['console', 'tickets'] })
      throw error
    } finally { locked.current = false }
  }
  return { submit, busy: mutation.isPending }
}
