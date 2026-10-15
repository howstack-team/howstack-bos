import assert from 'node:assert/strict'
import test from 'node:test'
import { formatMoneyMinor, minorToYuanText, yuanToMinor } from '../src/utils/money.ts'

test('元输入精确转换成分，包括常见浮点边界', () => {
  for (const [yuan, minor] of [['0', 0], ['0.01', 1], ['0.29', 29], ['1.01', 101], ['12.34', 1234], ['999999.99', 99999999], ['1000000', 100000000], ['-12.34', -1234]] as const) {
    assert.equal(yuanToMinor(yuan), minor)
    assert.equal(yuanToMinor(Number(yuan)), minor)
  }
})

test('已有分金额回填为元后，不修改再次提交保持原金额', () => {
  for (const minor of [0, 1, 29, 101, 1234, 99999999, 100000000, Number.MAX_SAFE_INTEGER, Number.MIN_SAFE_INTEGER]) {
    assert.equal(yuanToMinor(minorToYuanText(minor)), minor)
  }
  assert.equal(minorToYuanText(1), '0.01')
  assert.equal(minorToYuanText(1234), '12.34')
})

test('非法金额及超出范围的输入被拒绝，不静默截断或舍入', () => {
  for (const value of ['', '¥12', '1.001', '1e6', 'NaN', 'Infinity', '90071992547409.92']) {
    assert.throws(() => yuanToMinor(value), RangeError)
  }
  assert.throws(() => minorToYuanText(1.5), RangeError)
  assert.throws(() => minorToYuanText(Number.MAX_SAFE_INTEGER + 1), RangeError)
})

test('人民币展示保留零、负数和两位小数，其他币种不伪装成人民币', () => {
  assert.equal(formatMoneyMinor(0), '¥0.00 元')
  assert.equal(formatMoneyMinor(1234), '¥12.34 元')
  assert.equal(formatMoneyMinor(-29), '¥-0.29 元')
  assert.match(formatMoneyMinor(1234, 'USD'), /12\.34/)
  assert.equal(formatMoneyMinor(1234, 'USD').includes('元'), false)
})
