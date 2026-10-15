import assert from 'node:assert/strict'
import test from 'node:test'
import { parseMemberProfile, parseWalletBalance } from '../src/api/memberApi.ts'
import { parseRegistrationOptions } from '../src/api/registrationApi.ts'
import { ApiRequestError } from '../src/api/httpClient.ts'
import { clearMemberSession, createMemberQueryClient, queryKeys } from '../src/api/queryClient.ts'
import { loginReturnPath } from '../src/utils/loginReturnPath.ts'
import { minorToYuanText } from '../src/utils/money.ts'

test('会员响应只保留本人公开字段，并拒绝无效身份', () => {
  assert.deepEqual(parseMemberProfile({ user: { id: '72846193', email: null, mobile: '13800138000', nickname: '测试会员', token: 'excluded' } }),
    { id: '72846193', email: null, mobile: '13800138000', nickname: '测试会员' })
  for (const value of [null, [], { user: null }, { user: { id: 0, mobile: '123456', nickname: '' } },
    { user: { id: 1.1, mobile: '123456', nickname: '' } }, { user: { id: 1, mobile: 123456, nickname: '' } }]) {
    assert.throws(() => parseMemberProfile(value), /响应格式无效/)
  }
})

test('余额边界保留零和整数分，拒绝未知币种及不安全金额', () => {
  assert.deepEqual(parseWalletBalance({ currency: 'CNY', available_minor: 0, frozen_minor: 101 }),
    { currency: 'CNY', available_minor: 0, frozen_minor: 101 })
  for (const amount of [-1, 1.5, Number.NaN, Number.POSITIVE_INFINITY, Number.MAX_SAFE_INTEGER + 1, '100', null]) {
    assert.throws(() => parseWalletBalance({ currency: 'CNY', available_minor: amount, frozen_minor: 0 }))
  }
  assert.throws(() => parseWalletBalance({ currency: 'USD', available_minor: 100, frozen_minor: 0 }))
})

test('登录恢复完整内部路径，拒绝外部跳转和登录循环', () => {
  assert.equal(loginReturnPath({ from: '/finance/wallet?source=home#balance' }), '/finance/wallet?source=home#balance')
  for (const from of ['//example.com', 'https://example.com', '/\\example.com', '/login', '/login/?next=1',
    '/console/login', '/register', '/console/register', '/finance/\nwallet']) assert.equal(loginReturnPath({ from }), '/overview')
  assert.equal(loginReturnPath(null), '/overview')
})

test('金额精确展示到分，包括最大安全整数与零', () => {
  for (const [minor, yuan] of [[0, '0.00'], [1, '0.01'], [29, '0.29'], [101, '1.01'],
    [Number.MAX_SAFE_INTEGER, '90071992547409.91']] as const) assert.equal(minorToYuanText(minor), yuan)
  assert.throws(() => minorToYuanText(1.5), RangeError)
})

test('身份查询返回匿名时清理上一会员业务缓存', async () => {
  const client = createMemberQueryClient()
  client.setQueryData(queryKeys.wallet, { available_minor: 100 })
  await client.fetchQuery({ queryKey: queryKeys.session, queryFn: () => null })
  await new Promise<void>((resolve) => setImmediate(resolve))
  assert.equal(client.getQueryData(queryKeys.session), null)
  assert.equal(client.getQueryState(queryKeys.wallet), undefined)
  client.clear()
})

test('清理会话取消在途本人查询，不清理其他应用缓存', async () => {
  const client = createMemberQueryClient()
  client.setQueryData(queryKeys.session, { id: 1 })
  client.setQueryData(['admin', 'unrelated'], 'keep')
  let aborted = false
  const fetching = client.fetchQuery({ queryKey: queryKeys.wallet, queryFn: ({ signal }) => new Promise((_resolve, reject) => {
    signal.addEventListener('abort', () => { aborted = true; reject(new Error('aborted')) })
  }) }).catch(() => undefined)
  await clearMemberSession(client)
  await fetching
  assert.equal(aborted, true)
  assert.equal(client.getQueryData(queryKeys.session), null)
  assert.equal(client.getQueryData(queryKeys.wallet), undefined)
  assert.equal(client.getQueryData(['admin', 'unrelated']), 'keep')
  client.clear()
})

test('业务查询 401 清除本人缓存；403 保留会话且不重试', async () => {
  for (const status of [401, 403]) {
    const client = createMemberQueryClient()
    client.setQueryData(queryKeys.session, { id: 1 })
    let requests = 0
    await assert.rejects(client.fetchQuery({ queryKey: queryKeys.wallet, queryFn: () => {
      requests += 1
      throw new ApiRequestError('拒绝访问', status)
    } }))
    await new Promise<void>((resolve) => setImmediate(resolve))
    assert.equal(requests, 1)
    if (status === 401) {
      assert.equal(client.getQueryData(queryKeys.session), null)
      assert.equal(client.getQueryState(queryKeys.wallet), undefined)
    } else assert.deepEqual(client.getQueryData(queryKeys.session), { id: 1 })
    client.clear()
  }
})

test('邮箱注册资料与完整注册开关，不接受内部主键或字符串开关', () => {
  assert.deepEqual(parseMemberProfile({ user: { id: '72846193', mobile: null, email: 'user@example.com', nickname: '会员' } }),
    { id: '72846193', mobile: null, email: 'user@example.com', nickname: '会员' })
  assert.equal(parseMemberProfile({ user: { id: '123456789', mobile: null, email: 'u@example.com', nickname: '会员' } }).id, '123456789')
  for (const id of [7, '728461', 'ABC12345', '1234567890', '01234567']) assert.throws(() => parseMemberProfile({ user: { id, mobile: null, email: 'u@example.com', nickname: '会员' } }))
  const flags = { mobile_enabled: 1, email_enabled: 0, mobile_verification: 1, email_verification: 1 } as const
  assert.deepEqual(parseRegistrationOptions(flags), flags)
  for (const invalid of [null, {}, { ...flags, email_enabled: true }, { ...flags, email_verification: '1' }]) assert.throws(() => parseRegistrationOptions(invalid))
})
