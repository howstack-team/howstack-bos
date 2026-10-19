import assert from 'node:assert/strict'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
try {
  for (const readonly of [true, false]) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } }), page = await context.newPage()
    const writes = [], errors = []; page.on('pageerror', error => errors.push(error.message))
    const permissions = ['admin.services.cloud-servers.view', ...(!readonly ? ['admin.services.cloud-servers.provision', 'admin.services.cloud-servers.resolve'] : [])]
    const row = { id: 17, instance_no: 'bos-fixture-request', product_type: 'cloud_server', member_id: 1, member_nickname: '测试会员', member_mobile: '13800138000', order_no: 'ORDER-9', display_name: '云服务器 1', status: 'provisioning', activated_at: null, expires_at: null, created_at: '2026-10-18 10:00:00', provisioning_status: 'attention', provisioning_attempts: 20, provisioning_error: 'provider_result_unknown', addresses: [], can_confirm_not_created: true }
    await page.route('**/api/admin/v1/**', async route => {
      const path = new URL(route.request().url()).pathname.replace('/api/admin/v1', '')
      let data = null
      if (path === '/auth/me') data = { user: { id: 1, username: 'fixture', nickname: '测试管理员', is_founder: false }, permissions }
      else if (path === '/service-instances') data = { data: [row], total: 1 }
      else if (path === '/service-instances/17' && route.request().method() === 'GET') data = row
      else if (path.startsWith('/service-instances/') && route.request().method() === 'POST') {
        writes.push({ path, body: route.request().postData() ? route.request().postDataJSON() : null })
      }
      await route.fulfill({ json: { code: 0, message: 'ok', data, request_id: 'provision-admin-fixture' } })
    })
    await page.goto('http://127.0.0.1:5175/admin/services/cloud-servers')
    await page.getByText('待人工核查', { exact: true }).waitFor()
    if (readonly) {
      assert.equal(await page.getByText('重试开通', { exact: true }).count(), 0)
      assert.equal(await page.getByText('确认未开通', { exact: true }).count(), 0)
    } else {
      await page.getByText('重试开通', { exact: true }).click()
      await page.getByRole('button', { name: '确定', exact: true }).click()
      await page.getByText('已加入处理队列', { exact: true }).waitFor()
      assert.equal(writes.length, 1)
      await page.getByText('确认未开通', { exact: true }).click()
      const confirm = page.getByRole('button', { name: '确定', exact: true })
      assert.equal(await confirm.isDisabled(), true)
      await page.locator('textarea').fill('已在供应商后台按固定主机名核查，无实例记录')
      await confirm.click()
      await page.getByText('已确认未开通，可按原订单重试或申请退款', { exact: true }).waitFor()
      assert.equal(writes.length, 2); assert.equal(writes[1].body.acknowledged, true)
    }
    assert.deepEqual(errors, []); await context.close()
    console.log(`后台${readonly ? '只读权限' : '生产与核查权限'}：状态、操作隔离及表单校验通过`)
  }
} finally { await browser.close() }
