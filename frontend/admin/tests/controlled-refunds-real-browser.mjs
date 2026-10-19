import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { mkdir } from 'node:fs/promises'
import { responseValidator } from '../../../tools/api-docs/scripts/responses.mjs'
const settings = JSON.parse(readFileSync(0, 'utf8'))
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true }), output = '/tmp/bos-controlled-refunds-browser'
const document = JSON.parse(readFileSync(new URL('../../../backend/resources/api-docs/specs/admin.json', import.meta.url), 'utf8'))
const cookies = raw => raw.split('; ').map(item => ({ name: item.slice(0, item.indexOf('=')), value: item.slice(item.indexOf('=') + 1), domain: '127.0.0.1', path: '/', httpOnly: true, sameSite: 'Lax' }))
await mkdir(output, { recursive: true })
const base = 'http://127.0.0.1:5175/admin'
const drawer = page => page.locator('.arco-drawer:visible')
async function choose(page, container, index, label) {
  await container.locator('.arco-select').nth(index).click()
  await page.locator('.arco-select-option:visible').filter({ hasText: label }).first().click()
}
async function fieldValidation(page, container, name, writes) {
  const count = writes.length
  await container.getByRole('button', { name, exact: true }).click()
  await container.locator('.arco-form-item-status-error').first().waitFor()
  assert.equal(writes.length, count, '前端校验失败不能请求后端')
}
try {
  for (const [index, width] of [1440, 390].entries()) {
    const applicant = await browser.newContext({ viewport: { width, height: 1000 } }), reviewer = await browser.newContext({ viewport: { width, height: 1000 } })
    await applicant.addCookies(cookies(settings.applicant_cookie)); await reviewer.addCookies(cookies(settings.reviewer_cookie))
    const page = await applicant.newPage(), staff = await reviewer.newPage(), errors = [], writes = [], responses = []
    for (const target of [page, staff]) {
      target.setDefaultTimeout(15000)
      target.on('pageerror', error => errors.push(error.message))
      target.on('request', request => { if (request.method() === 'POST' && new URL(request.url()).pathname.includes('/finance/')) writes.push(request) })
      target.on('response', response => {
        const path = new URL(response.url()).pathname
        if (!path.includes('/finance/') || response.status() !== 200) return
        responses.push((async () => {
          const parts = path.split('/').filter(Boolean)
          const specPath = parts.length === 5 ? '/api/admin/v1/finance/{resource}' : parts.at(-1) === 'members' || parts.at(-1) === 'options'
            ? `/api/admin/v1/finance/{resource}/${parts.at(-1)}` : parts.length === 6 ? '/api/admin/v1/finance/{resource}/{id}' : '/api/admin/v1/finance/{resource}/{id}/{action}'
          const validate = responseValidator(document, specPath, response.request().method().toLowerCase(), 200)
          const value = await response.json()
          assert.ok(validate(value), `${specPath}实际响应与契约不符：${JSON.stringify(validate.errors)}`)
        })().catch(error => { errors.push(error.message) }))
      })
    }
    const visit = async (target, url) => { await Promise.all([page.waitForLoadState('networkidle'), staff.waitForLoadState('networkidle')]); await Promise.all(responses); await target.goto(url) }
    await visit(page, `${base}/refunds?member_id=${settings.member_id}`)
    await page.getByRole('button', { name: '申请退款', exact: true }).click()
    const form = drawer(page)
    await fieldValidation(page, form, '确认申请退款', writes)
    await choose(page, form, 0, settings.marker)
    await choose(page, form, 1, `实例 ${index === 0 ? 3 : 4}`)
    const amount = form.getByPlaceholder('请输入金额', { exact: true })
    await amount.waitFor(); assert.equal(await amount.isDisabled(), true)
    assert.equal(await amount.inputValue(), '10.00')
    await form.getByPlaceholder('请输入申请原因').fill(`浏览器-${width}-未交付退款`)
    const accepted = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/admin/v1/finance/refunds')
    await form.getByRole('button', { name: '确认申请退款', exact: true }).click()
    const payload = await (await accepted).json(); const id = payload.data.id
    await form.waitFor({ state: 'hidden' })
    // 本人申请不能出现审批按钮，服务端另有独立权限与同人拦截。
    const ownDetail = await (await page.request.get(`http://127.0.0.1:5175/api/admin/v1/finance/refunds/${id}`)).json()
    const ownRow = page.locator('tr').filter({ hasText: ownDetail.data.refund_no })
    await ownRow.waitFor()
    assert.equal(await ownRow.count(), 1)
    assert.equal(await ownRow.getByRole('link', { name: '通过', exact: true }).count(), 0)
    await visit(staff, `${base}/refunds?member_id=${settings.member_id}&status=pending`)
    await staff.getByRole('link', { name: '通过', exact: true }).first().click()
    const approval = drawer(staff)
    await fieldValidation(staff, approval, '确认审核通过', writes)
    await approval.getByPlaceholder('请输入审核意见').fill('已核对开通记录、未创建证据及原实付金额')
    await approval.getByRole('button', { name: '确认审核通过', exact: true }).click()
    await approval.waitFor({ state: 'hidden' })
    await visit(staff, `${base}/refunds?member_id=${settings.member_id}&status=approved`)
    await staff.getByRole('link', { name: '执行退款', exact: true }).first().click()
    await drawer(staff).getByRole('button', { name: '确认执行余额退款', exact: true }).click()
    await drawer(staff).waitFor({ state: 'hidden' })
    const result = await staff.request.get(`${base.replace('/admin', '')}/api/admin/v1/finance/refunds/${id}`)
    assert.equal((await result.json()).data.status, 'completed')
    // 充值退还：完整创建、独立审核、登记凭证；测试只登记合成凭证，无银行调用。
    await visit(page, `${base}/withdrawals?member_id=${settings.member_id}`)
    await page.getByRole('button', { name: '申请充值退还', exact: true }).click()
    await fieldValidation(page, drawer(page), '确认申请充值退还', writes)
    await choose(page, drawer(page), 0, settings.marker)
    await choose(page, drawer(page), 1, 'RC')
    await drawer(page).getByPlaceholder('请输入金额', { exact: true }).fill('1.00')
    await drawer(page).getByPlaceholder('请输入申请原因').fill(`浏览器-${width}-退还未消费充值`)
    await drawer(page).getByRole('button', { name: '确认申请充值退还', exact: true }).click()
    await drawer(page).waitFor({ state: 'hidden' })
    await visit(staff, `${base}/withdrawals?member_id=${settings.member_id}&status=pending`)
    await staff.getByRole('link', { name: '通过', exact: true }).first().click()
    await drawer(staff).getByPlaceholder('请输入审核意见').fill('已核对充值来源及冻结资金')
    await drawer(staff).getByRole('button', { name: '确认审核通过', exact: true }).click()
    await drawer(staff).waitFor({ state: 'hidden' })
    await visit(staff, `${base}/withdrawals?member_id=${settings.member_id}&status=approved`)
    await staff.getByRole('link', { name: '登记打款', exact: true }).first().click()
    await fieldValidation(staff, drawer(staff), '确认登记打款', writes)
    await drawer(staff).getByPlaceholder('请输入真实打款凭证编号').fill(`${settings.marker}-BROWSER-${width}`)
    await drawer(staff).getByPlaceholder('填写实际打款渠道、时间及核对依据，不要填写收款账号').fill('浏览器验收合成凭证，没有发生实际外部打款')
    await drawer(staff).getByRole('button', { name: '确认登记打款', exact: true }).click()
    await drawer(staff).waitFor({ state: 'hidden' })
    await visit(staff, `${base}/withdrawals?member_id=${settings.member_id}&status=paid`)
    await staff.getByRole('link', { name: '详情', exact: true }).first().click()
    await drawer(staff).getByText(`${settings.marker}-BROWSER-${width}`, { exact: true }).waitFor()
    await staff.waitForFunction(() => { const rect = document.querySelector('.arco-drawer')?.getBoundingClientRect(); return rect && rect.left >= 23 && rect.right <= innerWidth + 1 })
    await staff.screenshot({ path: `${output}/withdrawal-proof-${width}.png`, fullPage: true })
    assert.equal(await staff.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false)
    assert.equal(await drawer(staff).evaluate(element => element.getBoundingClientRect().width <= innerWidth - 23), true)
    await Promise.all(responses); assert.deepEqual(errors, [])
    await applicant.close(); await reviewer.close()
    console.log(`后台真实浏览器${width}px：退款/充值退还新建、独立审核、执行、凭证详情、字段失败不提交、API响应契约及窄屏无溢出通过`)
  }
} finally { await browser.close() }
