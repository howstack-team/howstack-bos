import assert from 'node:assert/strict'
const { chromium, expect } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
let scenarios = 0
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
  const page = await context.newPage(); page.setDefaultTimeout(15000)
  const errors = []; page.on('pageerror', e => errors.push(e.message))
  const permissions = ['admin.profile.view', 'admin.profile.mfa', 'admin.profile.update', 'admin.profile.password', 'admin.page.dashboard.view']
  const user = { id: 7788, username: 'mfa_admin', nickname: 'MFA验收管理员', last_login_at: null, is_founder: true, department_id: null }
  let authenticated = false, loginWrites = 0, verifyWrites = 0, setupWrites = 0, enableWrites = 0, recoveryWrites = 0
  let status = { enabled: false, enabled_at: null, recovery_remaining: 0 }, fieldError = true
  const recoveryCodes = Array.from({ length: 10 }, (_, i) => `${String(i).padStart(8, '0')}-aaaaaaaa-bbbbbbbb-cccccccc`)
  await context.route('**/api/admin/v1/**', async route => {
    const path = new URL(route.request().url()).pathname.replace('/api/admin/v1', ''), post = route.request().method() === 'POST'
    let data = null, http = 200, message = 'ok'
    if (path === '/auth/me') { http = authenticated ? 200 : 401; data = { user, permissions } }
    else if (path === '/auth/login') { loginWrites++; data = { mfa_required: true, expires_at: Math.floor(Date.now() / 1000) + 300 } }
    else if (path === '/auth/mfa/verify') {
      verifyWrites++; const input = route.request().postDataJSON()
      if (input.code !== '123456' && input.code !== recoveryCodes[0]) { http = 422; message = '请检查填写内容'; data = { errors: { code: ['动态验证码不正确'] } } }
      else { authenticated = true; data = { mfa_required: false, user, permissions, expires_at: 2099999999 } }
    }
    else if (path === '/profile') data = { ...user, email: null, mobile: null, employee_no: null }
    else if (path === '/profile/mfa') data = status
    else if (path === '/profile/mfa/setup') { setupWrites++; data = { setup_id: 'a'.repeat(64), secret: 'JBSWY3DPEHPK3PXP', uri: 'otpauth://totp/Howstack:mfa_admin?secret=JBSWY3DPEHPK3PXP&issuer=Howstack', expires_at: 2099999999 } }
    else if (path === '/profile/mfa/enable') {
      enableWrites++
      if (fieldError) { http = 422; data = { errors: { code: ['绑定验证码不正确'] } } }
      else { status = { enabled: true, enabled_at: '2026-10-15 12:00:00', recovery_remaining: 10 }; data = { ...status, recovery_codes: recoveryCodes } }
    }
    else if (path === '/profile/mfa/recovery-codes') { recoveryWrites++; data = { ...status, recovery_codes: recoveryCodes } }
    else if (path === '/profile/mfa/disable') { status = { enabled: false, enabled_at: null, recovery_remaining: 0 }; data = { ...status, recovery_codes: [] } }
    else if (post) throw new Error('未声明写请求 ' + path)
    else { http = 404; message = '未声明模拟接口' }
    await route.fulfill({ status: http, json: { code: http === 200 ? 0 : http * 100, message, data, request_id: 'mfa-browser' } })
  })
  await page.goto('http://127.0.0.1:5175/admin/login')
  await page.getByPlaceholder('请输入管理员账号').fill('mfa_admin'); await page.getByPlaceholder('请输入管理员密码').fill('Fixture@123456')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.getByPlaceholder('请输入6位验证码').waitFor()
  assert.equal(authenticated, false); assert.equal(loginWrites, 1); assert.equal(await page.getByPlaceholder('请输入管理员密码').count(), 0); scenarios++
  await page.getByRole('button', { name: '验证并登录' }).click(); await page.getByText('请输入验证码', { exact: true }).waitFor(); assert.equal(verifyWrites, 0); scenarios++
  await page.getByPlaceholder('请输入6位验证码').fill('000000'); await page.getByRole('button', { name: '验证并登录' }).click()
  await page.getByText('动态验证码不正确', { exact: true }).waitFor(); assert.equal(await page.locator('.arco-message').getByText('动态验证码不正确', { exact: true }).count(), 0); scenarios++
  await page.getByText('使用恢复码', { exact: true }).click(); await page.getByPlaceholder('请输入一次性恢复码').fill(recoveryCodes[0])
  await page.getByRole('button', { name: '验证并登录' }).click(); await expect.poll(() => authenticated).toBe(true)
  await page.goto('http://127.0.0.1:5175/admin/profile'); await page.getByRole('button', { name: '绑定验证器', exact: true }).waitFor(); scenarios++
  await page.getByRole('button', { name: '绑定验证器', exact: true }).click()
  await page.getByRole('button', { name: '下一步', exact: true }).click(); await page.getByText('请输入当前密码', { exact: true }).waitFor(); assert.equal(setupWrites, 0); scenarios++
  await page.getByPlaceholder('请输入当前管理员密码').fill('Fixture@123456'); await page.getByRole('button', { name: '下一步', exact: true }).click()
  await page.getByAltText('验证器绑定二维码').waitFor(); assert.match(await page.getByAltText('验证器绑定二维码').getAttribute('src'), /^data:image\/png;/)
  await page.getByRole('button', { name: '确认', exact: true }).click(); await page.getByText('请输入验证码', { exact: true }).waitFor(); assert.equal(enableWrites, 0); scenarios++
  await page.getByPlaceholder('请输入6位验证码').fill('123456'); await page.getByRole('button', { name: '确认', exact: true }).click()
  await page.getByText('绑定验证码不正确', { exact: true }).waitFor(); assert.equal(await page.locator('.arco-message').getByText('绑定验证码不正确', { exact: true }).count(), 0); scenarios++
  fieldError = false; await page.getByRole('button', { name: '确认', exact: true }).click()
  await page.getByText('保存一次性恢复码', { exact: true }).waitFor(); await page.getByRole('button', { name: '已妥善保存', exact: true }).click()
  await page.getByText('已开启验证器验证', { exact: true }).waitFor(); scenarios++
  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 900 }); await page.getByRole('button', { name: '重置恢复码', exact: true }).click()
    await page.getByPlaceholder('请输入当前管理员密码').waitFor()
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
    await page.mouse.click(3, 3)
    await expect(page.getByPlaceholder('请输入当前管理员密码')).toBeHidden()
    assert.equal(recoveryWrites, 0); scenarios++
  }
  const storage = await page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }))
  assert.ok(!storage.includes('JBSWY3DPEHPK3PXP') && !storage.includes(recoveryCodes[0])); assert.deepEqual(errors, [])
  console.log(JSON.stringify({ scenarios, invalidWrites: 0, duplicateFieldFeedback: 0, persistedSecrets: 0 }))
  await context.close()
} finally { await browser.close() }
