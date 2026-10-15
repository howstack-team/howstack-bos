import assert from 'node:assert/strict'
import test from 'node:test'
import { rechargeAmount, parseRecharge } from '../src/api/rechargeApi.ts'

test('充值金额精确转换整数分，拒绝零、科学计数法和超过两位小数', () => {
  for (const [input, expected] of [['0.01', 1], ['10.01', 1001], ['1000000.00', 100000000], [' 10.1 ', 1010]] as const) assert.equal(rechargeAmount(input), expected)
  for (const input of ['', '0', '-1', '0.001', '10.001', '1e3', '1000000.01', 'NaN']) assert.equal(rechargeAmount(input), null)
})
test('支付状态边界拒绝未知状态和错误金额，不向组件透传私有商户字段', () => {
  const row = { id: 1, recharge_no: 'R1', payment_no: 'P1', amount_minor: 1001, currency: 'CNY', plugin_id: 'alipay_order_code', status: 'pending', expires_at: '2026-10-15 10:00:00', created_at: '2026-10-15 09:00:00', paid_at: null, qr_code: 'https://qr.alipay.com/fixture', message: '', configuration_encrypted: 'private', lease_token: 'private' }
  assert.equal('configuration_encrypted' in parseRecharge(row), false)
  assert.equal('lease_token' in parseRecharge(row), false)
  for (const value of [{ ...row, status: '__proto__' }, { ...row, status: 'fake-paid' }, { ...row, amount_minor: 1.01 }, { ...row, currency: 'USD' }]) assert.throws(() => parseRecharge(value))
})
