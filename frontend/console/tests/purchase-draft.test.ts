import assert from 'node:assert/strict'
import { test } from 'node:test'
import { cleanPurchaseSelection, purchaseDraftKey, purchaseRequestKey, readPurchaseDraft, savePurchaseDraft } from '../src/utils/purchaseDraft.ts'
const storage = () => { const values = new Map<string, string>(); return { getItem: (key: string) => values.get(key) ?? null, setItem: (key: string, value: string) => values.set(key, value), removeItem: (key: string) => { values.delete(key) } } }
test('选配存储按嵌套白名单保存，任何密码、金额和提供者字段不会恢复', () => {
  const selected = cleanPurchaseSelection({ region_id: 1, quantity: 2, login_password: 'secret', password: 'secret', amount_minor: 1,
    system_disk: { type_id: 3, size_gib: 40, provider_key: 'secret' }, network: { egress_mbps: 5, ingress_mbps: 100, ipv4_count: 1, password: 'secret' }, data_disks: [{ type_id: 3, size_gib: 20, password: 'secret' }] })
  assert.equal(JSON.stringify(selected).includes('secret'), false)
  const store = storage(), now = Date.now(), key = purchaseRequestKey()
  assert.match(key, /^[a-f0-9]{32}$/)
  assert.equal(savePurchaseDraft(store, { offer_id: 4, selection: selected, request_key: key, updated_at: now }), true)
  assert.deepEqual(readPurchaseDraft(store, now)?.selection, selected)
  assert.equal(readPurchaseDraft(store, now)?.request_key, key, '刷新保留同一次选配意图的幂等键')
  assert.equal(readPurchaseDraft(store, now + 2 * 60 * 60 * 1000 + 1), null)
  assert.equal(store.getItem(purchaseDraftKey), null)
})
test('损坏、越界和未来草稿拒绝，禁用浏览器存储明确返回失败', () => {
  const store = storage()
  store.setItem(purchaseDraftKey, 'invalid-json'); assert.equal(readPurchaseDraft(store), null)
  assert.deepEqual(cleanPurchaseSelection({ region_id: -1, quantity: Infinity, data_disks: 'bad' }), { billing_mode: 'prepaid', login_method: 'password' })
  const now = Date.now()
  savePurchaseDraft(store, { offer_id: 2, selection: {}, request_key: purchaseRequestKey(), updated_at: now + 1 })
  assert.equal(readPurchaseDraft(store, now), null)
  assert.equal(savePurchaseDraft({ setItem: () => { throw new Error('blocked') } }, { selection: {}, request_key: purchaseRequestKey(), updated_at: now }), false)
})
