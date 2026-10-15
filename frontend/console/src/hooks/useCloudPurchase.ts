import { useEffect, useRef, useState } from 'react'
import { useHistory, useLocation } from 'react-router-dom'
import { useMemberAuth } from '../auth/AuthContext'
import { ApiRequestError } from '../api/httpClient'
import { createPurchaseOrder, getPurchaseOptions, getPurchaseProducts, getPurchaseQuote, type PurchaseOptions, type PurchaseProduct, type PurchaseQuote, type PurchaseSelection } from '../api/purchaseApi'
import { cleanPurchaseSelection, purchaseDraftKey, purchaseRequestKey, readPurchaseDraft, savePurchaseDraft, type PurchaseDraft } from '../utils/purchaseDraft'
import { paths } from '../routePaths'

/** 两套模板共用选配编排；报价可取消，登录恢复后重新向后端核价，不自动下单。 */
export function useCloudPurchase() {
  const auth = useMemberAuth(), history = useHistory(), location = useLocation()
  const [draft, setDraft] = useState<PurchaseDraft>(() => {
    let stored: PurchaseDraft | null = null
    try { stored = readPurchaseDraft(sessionStorage) } catch { /* 浏览器禁用存储时保持内存选配。 */ }
    const requested = Number(new URLSearchParams(location.search).get('offer'))
    const offer = Number.isSafeInteger(requested) && requested > 0 ? requested : stored?.offer_id
    return stored && stored.offer_id === offer ? stored : { offer_id: offer, selection: { quantity: 1, data_disks: [] }, request_key: purchaseRequestKey(), updated_at: Date.now() }
  })
  const [products, setProducts] = useState<PurchaseProduct[]>([]), [catalogLoading, setCatalogLoading] = useState(true)
  const [options, setOptions] = useState<PurchaseOptions>(), [quoteValue, setQuote] = useState<PurchaseQuote>(), [quoteIdentity, setQuoteIdentity] = useState('')
  const [loading, setLoading] = useState(false), [pricing, setPricing] = useState(false), [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(''), [submitError, setSubmitError] = useState(''), [notice, setNotice] = useState(''), [revision, setRevision] = useState(0)
  const optionSignature = useRef(''), submitLock = useRef(false), mounted = useRef(true)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  const product = products.find(row => row.id === draft.offer_id)
  const selection = draft.selection, signature = JSON.stringify(selection)
  const quote = product && quoteIdentity === `${product.id}:${product.version_id}:${signature}` ? quoteValue : undefined
  const message = (failure: unknown) => failure instanceof Error ? failure.message : '请求失败，请稍后重试'
  const update = (next: PurchaseSelection, offer = draft.offer_id) => {
    setQuote(undefined); setError(''); setSubmitError(''); setLoading(true); setPricing(false)
    setDraft({ offer_id: offer, selection: cleanPurchaseSelection(next), request_key: purchaseRequestKey(), updated_at: Date.now() })
  }
  useEffect(() => {
    const controller = new AbortController()
    void getPurchaseProducts(controller.signal).then(rows => {
      setProducts(rows)
      setDraft(current => {
        if (rows.some(row => row.id === current.offer_id) || !rows.length) return current
        if (current.offer_id) setNotice('原产品已停售，请重新确认当前产品与配置。')
        return { offer_id: rows[0].id, selection: { quantity: 1, data_disks: [] }, request_key: purchaseRequestKey(), updated_at: Date.now() }
      })
    }).catch(failure => { if (!controller.signal.aborted) setError(message(failure)) }).finally(() => { if (!controller.signal.aborted) setCatalogLoading(false) })
    return () => controller.abort()
  }, [revision])
  useEffect(() => { if (draft.offer_id) { try { savePurchaseDraft(sessionStorage, draft) } catch { /* 内存状态仍可使用。 */ } } }, [draft])
  useEffect(() => {
    if (!product) return
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      setLoading(true)
      void getPurchaseOptions(product, selection, controller.signal).then(result => {
        if (controller.signal.aborted) return
        setOptions(result); optionSignature.current = signature
        const next = { ...result.configuration, quantity: selection.quantity ?? 1, data_disks: result.configuration.data_disks ?? [] }
        const pick = (field: 'region_id' | 'zone_id' | 'line_id' | 'flavor_id' | 'image_id', kind: 'regions' | 'zones' | 'lines' | 'flavors' | 'images') => {
          if (!next[field] && result.options[kind].length) next[field] = result.options[kind][0].id
        }
        pick('region_id', 'regions'); pick('zone_id', 'zones'); pick('line_id', 'lines'); pick('flavor_id', 'flavors'); pick('image_id', 'images')
        next.flavor_group_id ??= result.options.flavors.find(row => row.id === next.flavor_id)?.group_id
        next.image_type_id ??= result.options.images.find(row => row.id === next.image_id)?.type_id
        if (!result.durations.some(row => row.months === next.duration_months)) next.duration_months = result.durations[0]?.months
        if (result.line_network && !next.network) next.network = { billing_mode: 'fixed', egress_mbps: result.line_network.egress.default, ingress_mbps: result.line_network.ingress.default, ipv4_count: result.line_network.ip.default }
        const types = result.options.disk_types
        const image = result.options.images.find(row => row.id === next.image_id)
        const type = types.find(row => row.id === next.system_disk?.type_id) || types.find(row => row.id === result.storage_policy.default_type_id) || types[0]
        if (type) {
          const base = type.min_gib ?? 1, step = type.step_gib ?? 1
          const min = Math.max(base, image?.min_disk_gib ?? 1, next.system_disk?.size_gib ?? result.storage_policy.default_size_gib)
          next.system_disk = { type_id: type.id, size_gib: base + Math.ceil((min - base) / step) * step }
        }
        const clean = cleanPurchaseSelection(next)
        if (Object.keys(result.invalid_fields).length) setNotice('部分配置已失效，已重新加载可选项，请确认后再提交订单。')
        if (JSON.stringify(clean) !== signature) setDraft(current => current.offer_id !== product.id || JSON.stringify(current.selection) !== signature
          ? current : { ...current, selection: clean, request_key: purchaseRequestKey(), updated_at: Date.now() })
      }).catch(failure => { if (!controller.signal.aborted) { setError(message(failure)); setOptions(undefined) } })
        .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    }, 180)
    return () => { window.clearTimeout(timer); controller.abort() }
  }, [product, selection, signature, revision])
  useEffect(() => {
    if (!product || !options || loading || optionSignature.current !== signature || !selection.region_id || !selection.zone_id || !selection.line_id || !selection.flavor_id || !selection.image_id || !selection.system_disk || !selection.network || !selection.duration_months) return
    const controller = new AbortController()
    setPricing(true); setError('')
    void getPurchaseQuote(product, selection, controller.signal).then(value => { if (!controller.signal.aborted) { setQuoteIdentity(`${product.id}:${product.version_id}:${signature}`); setQuote(value) } })
      .catch(failure => { if (!controller.signal.aborted) setError(message(failure)) }).finally(() => { if (!controller.signal.aborted) setPricing(false) })
    return () => controller.abort()
  }, [product, options, loading, selection, signature, revision])
  const choose = (field: 'region_id' | 'zone_id' | 'line_id' | 'flavor_group_id' | 'flavor_id' | 'image_type_id' | 'image_id', value: number) => {
    const next = { ...selection, [field]: value }
    if (field === 'region_id') delete next.zone_id
    if (field === 'region_id' || field === 'zone_id') {
      for (const key of ['line_id', 'flavor_group_id', 'flavor_id', 'image_type_id', 'image_id', 'duration_months', 'system_disk', 'network', 'protection_id'] as const) delete next[key]
      next.data_disks = []
    }
    if (field === 'line_id') { delete next.network; delete next.protection_id }
    if (field === 'flavor_group_id') { delete next.flavor_id; delete next.image_id }
    if (field === 'flavor_id') { delete next.image_type_id; delete next.image_id }
    if (field === 'image_type_id') delete next.image_id
    update(next)
  }
  const submit = async () => {
    if (!product || !quote || loading || pricing || submitLock.current) return
    if (!auth.member) {
      try { if (!savePurchaseDraft(sessionStorage, draft)) throw new Error('无法保留选配，请允许浏览器使用会话存储后再登录') }
      catch (failure) { setError(message(failure)); return }
      history.push(paths.login, { from: `${paths.purchase}?offer=${product.id}` }); return
    }
    submitLock.current = true; setSubmitting(true); setError(''); setSubmitError('')
    try {
      const id = await createPurchaseOrder(product, quote, draft.request_key)
      try { if (readPurchaseDraft(sessionStorage)?.request_key === draft.request_key) sessionStorage.removeItem(purchaseDraftKey) } catch { /* 下单成功不受浏览器存储限制影响。 */ }
      if (mounted.current) history.push(`${paths.orders}/${id}`)
    } catch (failure) {
      if (!mounted.current) return
      if (failure instanceof ApiRequestError && failure.status === 401) { auth.retry(); history.push(paths.login, { from: `${paths.purchase}?offer=${product.id}` }) }
      else { setSubmitError(message(failure)); if (failure instanceof ApiRequestError && failure.status === 409) { setQuote(undefined); setRevision(current => current + 1) } }
    } finally { submitLock.current = false; setSubmitting(false) }
  }
  return { products, product, selection, options, quote, catalogLoading, loading, pricing, submitting, error: submitError || error, notice,
    member: auth.member, choose, update, selectProduct: (id: number) => { setOptions(undefined); update({ quantity: 1, data_disks: [] }, id); history.replace(`${paths.purchase}?offer=${id}`) },
    submit, retry: () => setRevision(value => value + 1) }
}
