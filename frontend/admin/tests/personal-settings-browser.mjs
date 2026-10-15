import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = '/tmp/howstack-personal-settings'
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
  const page = await context.newPage()
  const errors = []
  page.on('pageerror', (error) => errors.push(error.message))
  let permissions = ['admin.profile.view', 'admin.profile.update', 'admin.profile.password', 'admin.page.dashboard.view']
  let profile = { id: 1, username: 'test_admin', nickname: '测试管理员', email: null, mobile: null, employee_no: null }
  let loggedIn = true
  let failLoad = false
  let failPassword = true
  let passwordWrites = 0
  let saves = 0
  const roleData = { id: 20, name: '测试角色', code: 'profile_test', status: 1, data_scope: 'self', department_ids: [], permissions: [], is_system: false }
  const catalog = Object.fromEntries(['view', 'update', 'password'].map((action) => [`admin.profile.${action}`, { label: action === 'view' ? '查看个人设置' : action === 'update' ? '修改个人资料' : '修改本人密码', group: '个人设置' }]))
  await context.route('**/api/admin/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api/admin/v1', '')
    let data = null
    let status = 200
    let message = '成功'
    if (path === '/auth/me') {
      status = loggedIn ? 200 : 401
      data = { user: profile, permissions }
    } else if (path === '/profile' && route.request().method() === 'GET') {
      if (failLoad) { status = 503; message = '个人资料暂时不可用' }
      else data = profile
    } else if (path === '/profile') {
      saves++
      const input = route.request().postDataJSON()
      assert.deepEqual(Object.keys(input).sort(), ['email', 'employee_no', 'mobile'])
      profile = { ...profile, ...input }
      data = profile
    } else if (path === '/profile/password') {
      passwordWrites++
      const input = route.request().postDataJSON()
      assert.equal(input.new_password, input.new_password_confirmation)
      if (failPassword) { status = 422; message = '原密码不正确' }
      else loggedIn = false
    } else if (path === '/roles') data = [roleData]
    else if (path === '/roles/20') data = roleData
    else if (path === '/departments') data = []
    else if (path === '/permissions') data = catalog
    else { status = 404; message = `未声明模拟接口 ${path}` }
    await route.fulfill({ status, json: { code: status === 200 ? 0 : status, message, data, request_id: 'profile-browser-test' } })
  })
  await page.goto(`${base}/dashboard`)
  await page.locator('.arco-avatar').last().click()
  await page.getByText('个人设置', { exact: true }).click()
  await page.getByPlaceholder('请输入邮箱（选填）').waitFor()
  assert.equal(new URL(page.url()).pathname, '/admin/profile')
  await page.getByPlaceholder('请输入邮箱（选填）').fill('staff@example.com')
  await page.getByPlaceholder('请输入手机号（选填）').fill('13800138000')
  await page.getByPlaceholder('请输入工号（选填）').fill('STAFF-001')
  await page.getByRole('button', { name: '保存资料', exact: true }).click()
  await page.getByText('个人资料已保存', { exact: true }).waitFor()
  assert.equal(saves, 1)
  await page.reload()
  await page.getByPlaceholder('请输入工号（选填）').waitFor()
  assert.equal(await page.getByPlaceholder('请输入工号（选填）').inputValue(), 'STAFF-001')
  await page.screenshot({ path: `${output}/profile-1440.png`, fullPage: true })
  console.log('通过：头像菜单进入个人设置、资料保存和刷新回填')
  await page.setViewportSize({ width: 390, height: 844 })
  await page.waitForTimeout(350)
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
  await page.getByPlaceholder('请输入邮箱（选填）').fill('')
  await page.getByPlaceholder('请输入手机号（选填）').fill('')
  await page.getByPlaceholder('请输入工号（选填）').fill('')
  await page.getByRole('button', { name: '保存资料', exact: true }).click()
  await page.waitForFunction(() => document.querySelector('input[placeholder="请输入工号（选填）"]').value === '')
  assert.equal(saves, 2)
  assert.equal(profile.email, null)
  assert.equal(profile.mobile, null)
  assert.equal(profile.employee_no, null)
  await page.screenshot({ path: `${output}/profile-390.png`, fullPage: true })
  console.log('通过：390px无横向溢出、三个资料字段可留空')
  await page.getByPlaceholder('请输入原密码').fill('wrong-password')
  await page.getByPlaceholder('请输入新密码，至少 8 个字符').fill('new-password')
  await page.getByPlaceholder('请再次输入新密码').fill('different-password')
  await page.getByRole('button', { name: '修改密码', exact: true }).click()
  await page.getByText('两次输入的新密码不一致', { exact: true }).waitFor()
  assert.equal(passwordWrites, 0)
  await page.getByPlaceholder('请再次输入新密码').fill('new-password')
  await page.getByRole('button', { name: '修改密码', exact: true }).click()
  await page.getByText('原密码不正确', { exact: true }).waitFor()
  assert.equal(passwordWrites, 1)
  assert.equal(new URL(page.url()).pathname, '/admin/profile')
  failPassword = false
  await page.getByPlaceholder('请输入原密码').fill('original-password')
  await page.getByRole('button', { name: '修改密码', exact: true }).click()
  await page.getByPlaceholder('请输入管理员账号').waitFor()
  assert.equal(new URL(page.url()).pathname, '/admin/login')
  console.log('通过：确认密码校验、错误原密码反馈、改密成功跳转登录并清除会话')
  loggedIn = true
  permissions = ['admin.profile.view']
  await page.goto(`${base}/profile`)
  await page.getByPlaceholder('请输入工号（选填）').waitFor()
  assert.equal(await page.getByRole('button', { name: '保存资料', exact: true }).count(), 0)
  assert.equal(await page.getByRole('button', { name: '修改密码', exact: true }).count(), 0)
  assert.equal(await page.getByPlaceholder('请输入邮箱（选填）').isDisabled(), true)
  console.log('通过：只读权限隐藏写操作')
  failLoad = true
  await page.reload()
  await page.getByText('个人资料暂时不可用', { exact: true }).waitFor()
  failLoad = false
  await page.getByRole('button', { name: '重试', exact: true }).click()
  await page.getByPlaceholder('请输入工号（选填）').waitFor()
  console.log('通过：接口失败提示及重新加载')
  permissions = ['admin.profile.view', 'admin.profile.update', 'admin.profile.password', 'admin.roles.view', 'admin.roles.update']
  await page.setViewportSize({ width: 1440, height: 1000 })
  await page.goto(`${base}/roles`)
  await page.getByRole('link', { name: '分配权限', exact: true }).click()
  const menuSelection = page.getByRole('checkbox', { name: '选中菜单：个人设置', exact: true })
  await menuSelection.waitFor({ state: 'attached' })
  await menuSelection.locator('..').click()
  assert.equal(await menuSelection.isChecked(), true)
  assert.equal(await page.getByRole('checkbox', { name: '修改本人密码', exact: true }).isChecked(), true)
  assert.equal(await page.getByRole('checkbox', { name: '修改', exact: true }).isChecked(), true)
  console.log('通过：角色权限表提供个人设置页面、资料修改及本人密码权限')
  permissions = ['admin.page.dashboard.view']
  await page.goto(`${base}/profile`)
  await page.locator('.arco-avatar').last().waitFor()
  assert.equal(await page.getByPlaceholder('请输入工号（选填）').count(), 0)
  await page.locator('.arco-avatar').last().click()
  assert.equal(await page.getByText('个人设置', { exact: true }).count(), 0)
  assert.deepEqual(errors, [])
  console.log('通过：无权限不能直接访问，头像入口隐藏，无浏览器运行错误')
} finally { await browser.close() }
