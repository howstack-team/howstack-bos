import assert from 'node:assert/strict'
import test from 'node:test'
import { loginErrorMessage, parseOAuthUrl } from '../src/api/loginApi.ts'
import { ApiRequestError } from '../src/api/httpClient.ts'

test('第三方登录只允许对应平台的无凭据 HTTPS 地址', () => {
  assert.equal(parseOAuthUrl({ url: 'https://graph.qq.com/oauth2.0/authorize?state=example' }, 'qq'), 'https://graph.qq.com/oauth2.0/authorize?state=example')
  assert.equal(parseOAuthUrl({ url: 'https://open.weixin.qq.com/connect/qrconnect' }, 'wechat'), 'https://open.weixin.qq.com/connect/qrconnect')
  for (const url of ['http://graph.qq.com', 'https://graph.qq.com.evil.example', 'https://user:pass@graph.qq.com',
    'https://graph.qq.com:444', 'javascript:alert(1)', '//graph.qq.com', 'https://open.weixin.qq.com']) {
    assert.throws(() => parseOAuthUrl({ url }, 'qq'), /授权地址无效/)
  }
  assert.throws(() => parseOAuthUrl(null, 'wechat'), /响应格式无效/)
})

test('扩展登录接口缺失明确提示待接入，并保留服务器业务错误', () => {
  for (const status of [404, 405, 501]) assert.equal(loginErrorMessage(new ApiRequestError('not found', status)), '该登录方式暂未接入，请使用密码登录')
  assert.equal(loginErrorMessage(new ApiRequestError('验证码已过期', 422)), '验证码已过期')
  assert.equal(loginErrorMessage(new ApiRequestError('发送过于频繁', 429)), '发送过于频繁')
})
