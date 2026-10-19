import assert from 'node:assert/strict'
import test from 'node:test'
import { parseWalletEntryPage, walletEntryAmount, walletEntryPagination } from '../src/api/walletEntriesApi.ts'

const entry = { entry_no: 'LEDGER-20261019-1', entry_type: 'credit', amount_minor: 10001,
  available_after_minor: 10001, currency: 'CNY', business_type: 'recharge', business_no: 'RECHARGE-1', created_at: '2026-10-19 12:00:00' }
const page = () => ({ data: [{ ...entry }], total: 1, page: 1, page_size: 20 })

test('整数分格式：正负、零、分以下不舍入，拒绝非整数和不安全值', () => {
  for (const [value, expected] of [[0, '0.00'], [1, '+0.01'], [-1, '-0.01'], [10001, '+100.01'], [-12345, '-123.45']] as const) assert.equal(walletEntryAmount(value), expected)
  for (const value of [0.1, NaN, Infinity, Number.MAX_SAFE_INTEGER + 1]) assert.throws(() => walletEntryAmount(value))
})

test('分页 URL 有界、默认20条，身份参数不会成为 API 查询条件', () => {
  assert.deepEqual(walletEntryPagination('?page=2&page_size=50&member_id=999'), { page: 2, pageSize: 50 })
  for (const query of ['', '?page=0&page_size=0', '?page=100001&page_size=1000', '?page[]=2&page_size=-1', '?page=1.2&page_size=01']) assert.deepEqual(walletEntryPagination(query), { page: 1, pageSize: 20 })
})

test('DTO 拒绝金额、分页和币种越界，兼容未知类型与失联业务，丢弃额外私有字段', () => {
  const valid = { ...page(), data: [{ ...entry, entry_type: 'future_type', business_type: 'other', business_no: null, member_id: 999, private_note: 'never expose' }] }
  const parsed = parseWalletEntryPage(valid)
  assert.equal(parsed.data[0].entry_type, 'future_type')
  assert.equal(parsed.data[0].business_no, null)
  assert.equal(Object.hasOwn(parsed.data[0], 'member_id'), false)
  assert.equal(Object.hasOwn(parsed.data[0], 'private_note'), false)
  for (const patch of [{ amount_minor: 0.01 }, { amount_minor: Number.MAX_SAFE_INTEGER + 1 }, { available_after_minor: -1 }, { currency: 'USD' }, { business_type: 'private' }, { business_no: '' }, { entry_no: '' }, { created_at: 'bad date' }]) assert.throws(() => parseWalletEntryPage({ ...page(), data: [{ ...entry, ...patch }] }))
  for (const patch of [{ total: -1 }, { total: 0 }, { page: 0 }, { page_size: 200 }, { data: [entry, entry], total: 2 }]) assert.throws(() => parseWalletEntryPage({ ...page(), ...patch }))
  assert.deepEqual(parseWalletEntryPage({ data: [], total: 0, page: 1, page_size: 20 }).data, [])
  assert.deepEqual(parseWalletEntryPage({ data: [], total: 25, page: 3, page_size: 20 }).data, [])
})
