import { useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemberAuth } from '../auth/AuthContext'
import { quoteServiceRenewal, createServiceRenewal } from '../api/serviceApi'
import { renewalRequestKey } from '../../../packages/service-renewal'

/** PC和移动复用同一报价、幂等及防重复提交逻辑。 */
export function useServiceRenewal(id: number) {
  const { member } = useMemberAuth(), client = useQueryClient()
  const [months, setMonths] = useState<number>(), [submitting, setSubmitting] = useState(false)
  const locked = useRef(false), requestKey = useRef(renewalRequestKey())
  const options = useQuery({ queryKey: ['console', 'renewal-options', member?.id, id], queryFn: ({ signal }) => quoteServiceRenewal(id, 0, signal), retry: false, staleTime: 0 })
  const selected = months ?? options.data?.durations[0]?.months
  const quote = useQuery({ queryKey: ['console', 'renewal-quote', member?.id, id, selected], queryFn: ({ signal }) => quoteServiceRenewal(id, selected!, signal), enabled: Boolean(selected && options.data), retry: false, staleTime: 0 })
  const select = (value: number) => { if (locked.current) return; requestKey.current = renewalRequestKey(); setMonths(value) }
  const valid = Boolean(quote.data?.amount_minor && quote.data.months === selected && !quote.isFetching && !quote.error && !options.error)
  const submit = async () => {
    if (locked.current || !valid || !selected || !quote.data?.amount_minor) return
    locked.current = true; setSubmitting(true)
    try {
      const orderId = await createServiceRenewal(id, selected, quote.data.amount_minor, requestKey.current)
      await client.invalidateQueries({ queryKey: ['console', 'services'] })
      return orderId
    } finally { locked.current = false; setSubmitting(false) }
  }
  return { options, quote, selected, select, submit, valid, submitting }
}
