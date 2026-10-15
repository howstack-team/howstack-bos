import assert from 'node:assert/strict'
import test from 'node:test'
import { consolePageTitle, parseConsoleSite } from '../../packages/console-theme-runtime/index.ts'

test('品牌配置拒绝无效图片地址，只保留公开展示字段', () => {
  const site = { name: '示例平台', logo: '/api/public/v1/website/images/123', favicon: '', copyright: '© 示例平台', icp_record: '', public_security_record: '' }
  assert.deepEqual(parseConsoleSite({ ...site, token: '不能公开' }), site)
  for (const value of [null, [], { ...site, copyright: undefined }, { ...site, logo: '//outside.test/logo.png' }, { ...site, favicon: 'javascript:alert(1)' }]) {
    assert.throws(() => parseConsoleSite(value))
  }
})

test('SPA标题使用当前平台名称，不拼接固定品牌或空后缀', () => {
  assert.equal(consolePageTitle('会员登录', '示例平台'), '会员登录 - 示例平台')
  assert.equal(consolePageTitle('会员注册', '新平台'), '会员注册 - 新平台')
  assert.equal(consolePageTitle('账户设置', ''), '账户设置')
})
