import assert from 'node:assert/strict'
import { readFile, mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const out = '/tmp/howstack-community-messages-browser'; await mkdir(out, { recursive: true })
const routes = await readFile(new URL('../src/routes.ts', import.meta.url), 'utf8')
const permissions = [...new Set([...routes.matchAll(/['"](admin\.[\w.-]+)['"]/g)].map(m => m[1])), 'admin.message-configurations.update']
const browser = await chromium.launch({ headless: true })
const names = { customer: '关联客户', admin: '内部人员', robot: '群机器人' }
function configuration(channel) {
 const value = { enabled: false }
 if (['email', 'sms'].includes(channel)) value.channel_id = 0
 if (channel !== 'wechat') { value.body = '原始模板 {{order_no}}'; if (channel !== 'sms') value.title = '消息标题' }
 if (['sms', 'wechat'].includes(channel)) { value.template_id = ''; value.variables = '{}' }
 return value
}
function fixture(code = 'order_paid') {
 if (['register_verification', 'login_verification'].includes(code)) {
  const name = code === 'register_verification' ? '注册验证码' : '登录验证码'
  const customer = { id: 'customer', name: names.customer, kind: 'customer', enabled: true, recipients: { mode: 'founder', ids: [] }, configurations: { sms: { ...configuration('sms'), body: '验证码 {{code}}' } } }
  return { code, name, description: '使用手机号验证码验证身份', purpose: 'verification', revisions: { customer: 0 },
   groups: [structuredClone(customer)], group_templates: { customer },
   metadata: { customer: { code, name, description: '使用手机号验证码验证身份', channels: ['sms'], variables: [{ key: 'code', label: '验证码' }] } },
   channel_options: { sms: [{ id: 8, name: '已启用短信通道', available: true }] }, robot_status: {}, recipient_options: {} }
 }
 const channels = { customer: ['email', 'sms', 'message', 'wechat'], admin: ['email', 'message'], robot: ['dingtalk', 'wecom', 'feishu'] }
 const group_templates = Object.fromEntries(Object.entries(channels).map(([kind, items]) => [kind, { id: kind, name: names[kind], kind, enabled: true, recipients: { mode: 'founder', ids: [] }, configurations: Object.fromEntries(items.map(c => [c, configuration(c)])) }]))
 return { code, name: code === 'order_paid' ? '订单支付成功' : '订单创建', description: '收款确认后通知接收对象', purpose: 'notification', revisions: { customer: 0, admin: 0 }, groups: structuredClone(Object.values(group_templates)), group_templates,
 metadata: Object.fromEntries(['customer','admin'].map(a => [a, { code, name: code === 'order_paid' ? (a === 'customer' ? '订单支付成功' : '订单已付款') : (a === 'customer' ? '订单创建' : '新订单'), description: a === 'customer' ? '通知关联客户' : '通知内部人员', channels: a === 'customer' ? channels.customer : [...channels.admin, ...channels.robot], variables: [{ key: 'order_no', label: '订单编号' }] }])),
 channel_options: { email: [{ id: 7, name: '已启用邮件通道', available: true }], sms: [{ id: 8, name: '已启用短信通道', available: true }] }, robot_status: { dingtalk: true, wecom: true, feishu: true }, recipient_options: { departments: [{ id: 2, name: '售后部门' }], roles: [{ id: 3, name: '客服角色' }], members: [{ id: 1, name: '管理员' }] } }
}
try {
 for (const width of [1440, 390]) {
  const context = await browser.newContext({ viewport: { width, height: 900 } })
  const stored = new Map([['order_paid', fixture()], ['order_created', fixture('order_created')], ['register_verification', fixture('register_verification')], ['login_verification', fixture('login_verification')]]), writes = [], errors = []
  const extra = structuredClone(stored.get('order_paid').groups[1]); extra.id = 'support'; extra.name = '售后人员'; extra.recipients = { mode: 'departments', ids: [2] }; extra.configurations.message.body = '高级模板 {{order_no}}'
  stored.get('order_paid').groups.push(extra)
  const untouchedAdmin = structuredClone(stored.get('order_paid').groups.filter(g => g.kind !== 'customer'))
  let batchConflict = false, missingChannel = false, fieldError = false, readOnly = false
  await context.route('**/api/admin/v1/**', async route => {
   const req = route.request(), path = new URL(req.url()).pathname.replace('/api/admin/v1','')
   let data = { data: [], total: 0 }
   if (path === '/auth/me') data = { user: { id: 1, username: 'mock', nickname: '模拟管理员', is_founder: false }, permissions: readOnly ? permissions.filter(p => p !== 'admin.message-configurations.update') : permissions }
   else if (path === '/messages/summary') data = { unread: 0, categories: {}, topics: {} }
   else if (path === '/system-updates/notice') data = { available: false }
   else if (path === '/message-scenes') data = { total: stored.size, data: [...stored.values()].map(d => ({ code: d.code, name: d.name, description: d.description, purpose: d.purpose, metadata: d.metadata, revisions: d.revisions, enabled: d.groups.some(g => g.enabled && Object.values(g.configurations).some(c => c.enabled)), groups: d.groups.map(g => ({ id: g.id, name: g.name, kind: g.kind, enabled: g.enabled, channels: Object.keys(g.configurations).filter(c => g.enabled && g.configurations[c].enabled), available_channels: Object.keys(g.configurations) })) })) }
   else if (path === '/message-scenes/batch') {
    if (batchConflict) { await route.fulfill({ status: 409, json: { code: 40900, message: '消息配置已变化，请重新选择', data: null, request_id: 'mock' } }); return }
    const input = req.postDataJSON(); writes.push({ path, input })
    for (const item of input.items) { const d = stored.get(item.code); assert.deepEqual(input.items.find(i => i.code === d.code).revisions, d.revisions); for (const g of d.groups) if (g.kind === input.kind && g.configurations[input.channel]) Object.assign(g.configurations[input.channel], Object.fromEntries(['enabled','channel_id'].filter(k => k in input).map(k => [k,input[k]]))); for (const a of Object.keys(d.revisions)) d.revisions[a]++ }
    data = { updated: input.items.length }
   } else if (path.startsWith('/message-scenes/')) {
    const code = path.split('/')[2], d = stored.get(code)
    if (req.method() === 'PUT') {
     if (fieldError) { await route.fulfill({ status: 422, json: { code: 42200, message: '模板变量无效', data: { errors: { 'groups.0.configurations.message.body': ['不支持此变量'] } }, request_id: 'mock' } }); return }
     const input = req.postDataJSON(); writes.push({ path, input }); assert.deepEqual(input.revisions, d.revisions); d.groups = input.groups; for (const a of Object.keys(d.revisions)) d.revisions[a]++
    }
    data = structuredClone(d); if (missingChannel) data.channel_options.email = []
   }
   await route.fulfill({ json: { code: 0, message: 'ok', data, request_id: 'mock' } })
  })
  const page = await context.newPage(); page.on('pageerror', e => errors.push(e.message))
  await page.goto(`${base}/message-configurations`)
  const card = page.locator('.page-card')
  const row = (title = '订单支付成功') => card.locator('tbody tr').filter({ has: page.getByText(title, { exact: true }) })
  const drawer = page.locator('.arco-drawer:visible')
  const active = () => drawer.locator('.arco-tabs-content-item-active')
  const field = label => active().locator('.arco-form-item').filter({ has: page.locator('label').getByText(label, { exact: true }) }).first()
  const select = async (modal, label, option) => { await modal.locator('.arco-form-item').filter({ has: page.locator('label').getByText(label, { exact: true }) }).locator('.arco-select').click(); await page.getByRole('option', { name: option, exact: true }).click() }
  await row().waitFor()
  assert.equal(await card.getByRole('form', { name: '表格查询' }).count(), 0)
  assert.equal(await card.getByRole('button', { name: '刷新', exact: true }).count(), 0)
  assert.equal(await card.getByRole('tab', { name: '发送给客户' }).count(), 1)
  assert.equal(await card.getByText('接收组与渠道', { exact: true }).count(), 0)
  assert.equal(await card.locator('.arco-pagination').count(), 0)
  assert.equal(await card.locator('tbody .arco-checkbox').count(), 0)
  assert.equal(await card.getByRole('button', { name: '批量配置' }).isDisabled(), false)
  await page.screenshot({ path: `${out}/customer-list-${width}.png`, fullPage: true, animations: 'disabled' })
  await row().getByRole('link', { name: '编辑', exact: true }).click(); await drawer.waitFor()
  await field('邮件主题').locator('input').fill('客户主题 {{order_no}}')
  await drawer.getByRole('tab', { name: '站内消息', exact: true }).click()
  await field('消息内容').locator('textarea').fill('客户正文 {{order_no}}')
  await drawer.getByRole('tab', { name: '邮件', exact: true }).click()
  assert.equal(await field('邮件主题').locator('input').inputValue(), '客户主题 {{order_no}}')
  assert.equal(await drawer.getByText('接收组名称', { exact: true }).count(), 0)
  assert.equal(await drawer.getByText('接收范围', { exact: true }).count(), 0)
  assert.equal(await drawer.getByRole('button', { name: '添加', exact: true }).count(), 0)
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${width} drawer overflow`)
  await page.screenshot({ path: `${out}/customer-editor-${width}.png`, fullPage: true, animations: 'disabled' })
  fieldError = true; await drawer.getByRole('button', { name: '保存', exact: true }).click()
  await field('消息内容').getByText('不支持此变量', { exact: true }).waitFor()
  assert.equal(writes.length, 0); fieldError = false
  await field('消息内容').locator('textarea').fill('客户正文 {{order_no}} 修订')
  await drawer.getByRole('button', { name: '保存', exact: true }).click(); await drawer.waitFor({ state: 'hidden' })
  assert.equal(writes.length, 1)
  assert.equal(writes[0].input.groups[0].configurations.email.title, '客户主题 {{order_no}}')
  assert.equal(writes[0].input.groups[0].configurations.message.body, '客户正文 {{order_no}} 修订')
  assert.deepEqual(writes[0].input.groups.filter(g => g.kind !== 'customer'), untouchedAdmin)
  await card.getByRole('button', { name: '批量配置', exact: true }).click()
  const modal = page.locator('.arco-modal:visible')
  assert.equal(await modal.getByText('接收组类型', { exact: true }).count(), 0)
  assert.ok(await modal.getByRole('button', { name: '确定', exact: true }).isDisabled())
  await modal.locator('.arco-form-item').filter({ has: page.locator('label').getByText('适用消息', { exact: true }) }).locator('.arco-select').click()
  await page.getByText('全部适用消息（2）', { exact: true }).click()
  await modal.getByText('消息渠道', { exact: true }).click()
  await modal.getByText('将修改 2 条消息的邮件配置，不更改消息模板。', { exact: true }).waitFor()
  await select(modal, '消息渠道', '站内消息');
  await modal.getByText('将修改 0 条消息的站内消息配置，不更改消息模板。', { exact: true }).waitFor()
  assert.ok(await modal.getByRole('button', { name: '确定', exact: true }).isDisabled())
  assert.equal(writes.length, 1)
  await select(modal, '适用消息', '订单支付成功'); await modal.getByText('消息渠道', { exact: true }).click(); await select(modal, '启用状态', '启用')
  await modal.getByText('将修改 1 条消息的站内消息配置，不更改消息模板。', { exact: true }).waitFor()
  await page.screenshot({ path: `${out}/batch-${width}.png`, fullPage: true, animations: 'disabled' })
  await modal.getByRole('button', { name: '确定', exact: true }).click(); await modal.waitFor({ state: 'hidden' })
  assert.equal(writes.length, 2); assert.equal(writes[1].input.kind, 'customer'); assert.equal(writes[1].input.channel, 'message')
  await card.getByRole('button', { name: '批量配置', exact: true }).click()
  await select(modal, '消息渠道', '站内消息'); await select(modal, '适用消息', '订单支付成功'); await modal.getByText('消息渠道', { exact: true }).click(); await select(modal, '启用状态', '禁用'); batchConflict = true
  await modal.getByRole('button', { name: '确定', exact: true }).click(); await modal.getByText('消息配置已变化，请重新选择', { exact: true }).waitFor()
  await modal.getByRole('button', { name: '取消', exact: true }).click(); batchConflict = false
  await card.getByRole('tab', { name: '发送给管理员', exact: true }).click(); await row('订单已付款').waitFor()
  assert.equal(await card.getByRole('button', { name: '批量配置' }).isDisabled(), false)
  await page.screenshot({ path: `${out}/admin-list-${width}.png`, fullPage: true, animations: 'disabled' })
  await row('订单已付款').getByRole('link', { name: '编辑', exact: true }).click()
  await active().getByText('此渠道已设置自定义接收规则，当前页面仅展示配置，原有规则会继续保留。', { exact: true }).waitFor()
  assert.ok(await field('邮件主题').locator('input').isDisabled())
  const beforeRobot = structuredClone(stored.get('order_paid').groups.filter(g => g.kind !== 'robot'))
  await drawer.getByRole('tab', { name: '飞书', exact: true }).click(); await field('消息内容').locator('textarea').fill('飞书内容 {{order_no}}')
  await page.screenshot({ path: `${out}/admin-editor-${width}.png`, fullPage: true, animations: 'disabled' })
  await drawer.getByRole('button', { name: '保存', exact: true }).click(); await drawer.waitFor({ state: 'hidden' })
  assert.equal(writes.length, 3); assert.deepEqual(writes[2].input.groups.filter(g => g.kind !== 'robot'), beforeRobot)
  assert.equal(writes[2].input.groups.find(g => g.kind === 'robot').configurations.feishu.body, '飞书内容 {{order_no}}')
  await card.getByRole('button', { name: '批量配置' }).click()
  await select(modal, '消息渠道', '钉钉'); await select(modal, '适用消息', '订单已付款'); await modal.getByText('消息渠道', { exact: true }).click(); await select(modal, '启用状态', '启用'); await modal.getByRole('button', { name: '确定' }).click(); await modal.waitFor({ state: 'hidden' })
  assert.equal(writes[3].input.kind, 'robot')
  await card.getByRole('tab', { name: '发送给客户' }).click(); missingChannel = true
  await row().getByRole('link', { name: '编辑', exact: true }).click()
  await active().getByText('暂无可用的邮件通道，请先配置并启用。', { exact: false }).waitFor()
  await active().getByRole('link', { name: '去配置', exact: true }).waitFor()
  await page.reload(); await drawer.waitFor(); assert.equal(writes.length, 4)
  await drawer.getByRole('button', { name: '取消', exact: true }).click(); await drawer.waitFor({ state: 'hidden' })
  // 显式清空过的策略可从简化界面重新配置，未触碰的管理员策略仍保持空。
  stored.get('order_paid').groups = []
  await row().getByRole('link', { name: '编辑', exact: true }).click()
  await drawer.getByRole('tab', { name: '站内消息', exact: true }).click()
  await field('消息内容').locator('textarea').fill('恢复客户消息 {{order_no}}')
  await drawer.getByRole('button', { name: '保存', exact: true }).click(); await drawer.waitFor({ state: 'hidden' })
  assert.equal(writes[4].input.groups.length, 1); assert.equal(writes[4].input.groups[0].kind, 'customer')
  // 只改模板不会重开整组，明确切换渠道开关才恢复启用。
  const paused = stored.get('order_paid').groups[0]; paused.enabled = false; paused.configurations.message.enabled = true
  await row().getByRole('link', { name: '编辑', exact: true }).click(); await drawer.getByRole('tab', { name: '站内消息', exact: true }).click()
  await field('消息内容').locator('textarea').fill('保留组停用 {{order_no}}')
  await drawer.getByRole('button', { name: '保存', exact: true }).click(); await drawer.waitFor({ state: 'hidden' })
  assert.equal(writes[5].input.groups[0].enabled, false); assert.equal(writes[5].input.groups[0].configurations.message.enabled, true)
  await row().getByRole('link', { name: '编辑', exact: true }).click(); await drawer.getByRole('tab', { name: '站内消息', exact: true }).click()
  await field('启用此渠道').getByRole('switch').click()
  await drawer.getByRole('button', { name: '保存', exact: true }).click(); await drawer.waitFor({ state: 'hidden' })
  assert.equal(writes[6].input.groups[0].enabled, true); assert.equal(writes[6].input.groups[0].configurations.message.enabled, true)
  readOnly = true; await page.reload(); await row().getByRole('link', { name: '查看', exact: true }).waitFor()
  assert.equal(await card.getByRole('button', { name: '批量配置' }).count(), 0)
  await row().getByRole('link', { name: '查看', exact: true }).click(); await field('邮件主题').locator('input').waitFor()
  assert.ok(await field('邮件主题').locator('input').isDisabled()); assert.equal(await drawer.getByRole('button', { name: '保存', exact: true }).count(), 0)
  assert.equal(writes.length, 7)
  await drawer.getByRole('button', { name: '取消', exact: true }).click(); await drawer.waitFor({ state: 'hidden' })
  for (const code of ['register_verification', 'login_verification']) {
   // 旧链接指定邮件时，也只显示该场景实际支持的短信 Tab。
   await page.goto(`${base}/message-configurations?tab=customer&edit=${code}&channel=email`)
   await drawer.getByRole('tab', { name: '短信', exact: true }).waitFor()
   assert.equal(await drawer.getByRole('tab', { name: '邮件', exact: true }).count(), 0)
   assert.equal(await drawer.getByRole('tab').count(), 1)
   assert.equal(await drawer.getByText('邮件主题', { exact: true }).count(), 0)
   await page.screenshot({ path: `${out}/${code}-${width}.png`, animations: 'disabled' })
   await drawer.getByRole('button', { name: '取消', exact: true }).click(); await drawer.waitFor({ state: 'hidden' })
  }
  assert.equal(writes.length, 7)
  assert.deepEqual(errors, [])
  await context.close()
 }
} finally { await browser.close() }
console.log(JSON.stringify({ passed: true, widths: [1440,390], realWrites: 0, communityDrawerAndBatch: true, preservesAdvancedStrategies: true, screenshots: out }))
