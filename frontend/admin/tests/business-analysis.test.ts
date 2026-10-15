import assert from 'node:assert/strict'
import test from 'node:test'
import { analysisToday, analysisChange, validateAnalysisQuery } from '../src/utils/businessAnalysis.ts'
import { isAnalysisDate } from '../src/api/businessAnalysisApi.ts'

test('业务时区跨日、真实自然日与366天边界', () => {
  assert.equal(analysisToday('Asia/Shanghai', new Date('2026-10-08T18:00:00Z')), '2026-10-09')
  assert(isAnalysisDate('2024-02-29'))
  assert(!isAnalysisDate('2026-02-29'))
  assert(!isAnalysisDate('2026-04-31'))
  assert(!isAnalysisDate('2026-1-01'))
  assert.deepEqual(validateAnalysisQuery({ start_date: '2024-01-01', end_date: '2024-12-31', granularity: 'day' }, '2026-10-09'), {})
  assert.match(validateAnalysisQuery({ start_date: '2024-01-01', end_date: '2025-01-01', granularity: 'day' }, '2026-10-09').start_date!, /366/)
})
test('未来、倒置、空值和未知粒度保留字段错误，单边日期按接口默认规则', () => {
  const today = '2026-10-09'
  assert(validateAnalysisQuery({ start_date: '2026-10-09', end_date: '2026-10-08', granularity: 'day' }, today).start_date)
  assert(validateAnalysisQuery({ end_date: '2026-10-10', granularity: 'day' }, today).end_date)
  assert(validateAnalysisQuery({ start_date: '', granularity: 'year' }, today).start_date)
  assert(validateAnalysisQuery({ granularity: 'year' }, today).granularity)
  assert.deepEqual(validateAnalysisQuery({ end_date: '2026-10-01', granularity: 'month' }, today), {})
  assert(validateAnalysisQuery({ start_date: '2020-01-01', granularity: 'day' }, today).start_date)
})
test('前期零值无对比，负净实付及最大安全金额比较不丢失整数精度', () => {
  assert.equal(analysisChange(100, 0), '暂无对比')
  assert.equal(analysisChange(0, 0), '暂无对比')
  assert.equal(analysisChange(-100, 100), '-200.00%')
  assert.equal(analysisChange(100, -100), '+200.00%')
  assert.equal(analysisChange(100, 100), '0.00%')
  assert.equal(analysisChange(2, 3), '-33.33%')
  assert.equal(analysisChange(Number.MAX_SAFE_INTEGER, -Number.MAX_SAFE_INTEGER), '+200.00%')
})
