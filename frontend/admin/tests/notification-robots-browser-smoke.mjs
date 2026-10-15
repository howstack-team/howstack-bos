import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = '/tmp/howstack-notification-robots'; await mkdir(output, { recursive: true })
const keys = c => c === 'wecom' ? ['webhook_url'] : ['webhook_url', 'sign_secret']
const names = { dingtalk: '钉钉', wecom: '企业微信', feishu: '飞书' }
const robots = Object.fromEntries(Object.keys(names).map(c => [c, Object.fromEntries(keys(c).map(k => [k, '']))]))
const writes = [], errors = []; let revision = 0
const browser = await chromium.launch({ headless: true })
async function mock(route, permissions) {
 const req = route.request(), path = new URL(req.url()).pathname.replace('/api/admin/v1', '')
 let data = { available: false }
 if (path === '/auth/me') data = { user: { id: 1, username: 'mock', nickname: '测试管理员', is_founder: false }, permissions }
 if (path === '/settings/categories') data = [{ id: 1, code: 'base', name: '基础设置', sort_order: 10 }, { id: 2, code: 'security', name: '安全设置', sort_order: 20 }, { id: 3, code: 'notifications', name: '消息通知', sort_order: 30 }]
 if (path === '/settings/notifications') {
  if (req.method() === 'PUT') {
   const input = req.postDataJSON(); assert.equal(input.revision, revision); writes.push(input)
   for (const c of Object.keys(names)) for (const k of keys(c)) {
    if (input.clear_secrets[c]?.includes(k)) robots[c][k] = ''
    else if (input.robots[c][k]) robots[c][k] = input.robots[c][k]
   }
   revision++
  }
  data = { revision, configured_secrets: Object.fromEntries(Object.keys(names).map(c => [c, Object.fromEntries(keys(c).map(k => [k, Boolean(robots[c][k])]))])) }
 }
 await route.fulfill({ json: { code: 0, message: '成功', data, request_id: 'robots-mock' } })
}
try {
 const context = await browser.newContext({ viewport: { width: 1440, height: 900 } })
 await context.route('**/api/admin/v1/**', route => mock(route, ['admin.page.settings.view', 'admin.page.settings.update']))
 const page = await context.newPage(); page.on('pageerror', e => errors.push(e.message))
 const card = page.locator('.settings-page'), panel = card.locator('.arco-tabs-content-item-active .settings-form-panel').first()
 const active = () => panel.locator('.arco-tabs-content-item-active')
 const input = label => active().locator('.arco-form-item').filter({ has: page.locator('label').getByText(label, { exact: true }) }).locator('input')
 const switchTab = name => panel.getByRole('tab', { name, exact: true }).click()
 const save = async () => { const previous = writes.length; await panel.getByRole('button', { name: '保存设置', exact: true }).click(); await page.waitForFunction(n => document.querySelectorAll('.arco-message-success').length > n, await page.locator('.arco-message-success').count() - 1); await page.waitForTimeout(100); assert.equal(writes.length, previous + 1) }
 await page.goto(`${base}/settings?tab=notifications`); await input('机器人 Webhook').waitFor()
 await input('机器人 Webhook').fill('https://oapi.dingtalk.com/robot/send?access_token=MOCK_DING')
 await input('加签密钥').fill('MOCK_SECRET')
 await switchTab('企业微信'); await input('机器人 Webhook').fill('https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=MOCK_WECOM')
 await switchTab('飞书'); await input('机器人 Webhook').fill('https://open.feishu.cn/open-apis/bot/v2/hook/MOCK_FEISHU')
 await switchTab('钉钉'); assert.equal(await input('加签密钥').inputValue(), 'MOCK_SECRET')
 await save(); assert.equal(Object.keys(writes[0].robots).length, 3); assert.equal(await input('机器人 Webhook').inputValue(), '')
 await active().getByText('已设置，留空保持原设置', { exact: true }).first().waitFor()
 await page.screenshot({ path: `${output}/desktop.png` })
 await page.reload(); await input('机器人 Webhook').waitFor(); await save(); assert.equal(robots.dingtalk.sign_secret, 'MOCK_SECRET')
 const secretField = () => active().locator('.arco-form-item').filter({ has: page.locator('label').getByText('加签密钥', { exact: true }) })
 await secretField().getByRole('link', { name: '清除', exact: true }).click()
 await secretField().getByText('保存后清除原设置', { exact: true }).waitFor()
 await secretField().getByRole('link', { name: '保留原设置', exact: true }).click(); await save(); assert.equal(robots.dingtalk.sign_secret, 'MOCK_SECRET')
 await secretField().getByRole('link', { name: '清除', exact: true }).click(); await save(); assert.equal(robots.dingtalk.sign_secret, '')
 await page.setViewportSize({ width: 390, height: 844 }); await page.reload(); await input('机器人 Webhook').waitFor(); await page.waitForFunction(() => document.getElementById('robots_dingtalk_webhook_url_input')?.disabled === false); await page.waitForFunction(() => document.documentElement.scrollWidth <= innerWidth)
 await page.screenshot({ path: `${output}/mobile.png` })
 const viewer = await browser.newContext({ viewport: { width: 1440, height: 900 } }); await viewer.route('**/api/admin/v1/**', route => mock(route, ['admin.page.settings.view']))
 const readonly = await viewer.newPage(); await readonly.goto(`${base}/settings?tab=notifications`); await readonly.getByPlaceholder('已设置，留空保持原设置').first().waitFor()
 assert.equal(await readonly.getByRole('button', { name: '保存设置', exact: true }).count(), 0)
 assert(await readonly.getByPlaceholder('已设置，留空保持原设置').first().isDisabled())
 assert.deepEqual(errors, [])
 console.log(JSON.stringify({ passed: true, mockWrites: writes.length, realWrites: 0, widths: [1440, 390], screenshots: output }))
} finally { await browser.close() }
