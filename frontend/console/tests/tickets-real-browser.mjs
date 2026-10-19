import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
import { readFileSync } from 'node:fs'
const settings = JSON.parse(readFileSync(0, 'utf8'))
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true }), output = '/tmp/bos-tickets-real-browser'
await mkdir(output, { recursive: true })
const cookies = raw => raw.split('; ').map(item => ({ name: item.slice(0, item.indexOf('=')), value: item.slice(item.indexOf('=') + 1), domain: '127.0.0.1', path: '/', httpOnly: true, sameSite: 'Lax', secure: false }))
try {
  for (const [mobile, width] of [[false, 1440], [true, 390], [true, 320]]) {
    const member = await browser.newContext({ viewport: { width, height: 960 }, isMobile: mobile, hasTouch: mobile,
      ...(mobile ? { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' } : {}) })
    const admin = await browser.newContext({ viewport: { width: width === 390 ? 390 : 1440, height: 960 } })
    await member.addCookies(cookies(settings.member_cookie)); await admin.addCookies(cookies(settings.admin_cookie))
    const page = await member.newPage(), staff = await admin.newPage(), errors = [], writes = []
    page.setDefaultTimeout(15000); staff.setDefaultTimeout(15000)
    page.on('pageerror', error => errors.push(error.message)); staff.on('pageerror', error => errors.push(error.message))
    page.on('response', async response => {
      if (new URL(response.url()).pathname.startsWith('/api/console/v1/tickets') && response.status() >= 400) {
        const body = await response.json().catch(() => ({})); console.error('工单API失败', new URL(response.url()).pathname, response.status(), body.message)
      }
    })
    page.on('request', request => { if (request.method() === 'POST' && new URL(request.url()).pathname.includes('/tickets')) writes.push(request) })
    const base = `http://127.0.0.1:${mobile ? 5177 : 5176}/console`, subject = `${settings.marker}-浏览器-${width}`
    await page.goto(`${base}/support/tickets`)
    await page.getByRole('button', { name: '提交工单', exact: true }).click()
    if (mobile) {
      await page.getByRole('button', { name: '提交工单', exact: true }).click()
      await page.getByText('请选择工单类型', { exact: true }).last().waitFor()
      assert.equal(writes.length, 0, '移动前端校验失败不能请求后端')
      await page.getByRole('button', { name: '请选择工单类型', exact: true }).click()
      await page.getByText('确认', { exact: true }).last().click()
      await page.getByRole('textbox', { name: '主题', exact: true }).fill(subject)
      await page.getByRole('textbox', { name: '问题描述', exact: true }).fill('<img src=x onerror=window.ticketXss=1>请排查连接问题')
      await page.getByRole('button', { name: '提交工单', exact: true }).click()
    } else {
      const modal = page.getByRole('dialog')
      await modal.getByRole('button', { name: '确定', exact: true }).click()
      await modal.getByText('请选择工单类型', { exact: true }).last().waitFor()
      assert.equal(writes.length, 0, 'PC前端校验失败不能请求后端')
      await modal.getByText('请选择工单类型', { exact: true }).first().click()
      await page.getByText(settings.marker, { exact: true }).click()
      await modal.getByPlaceholder('简要描述问题').fill(subject)
      await modal.getByPlaceholder('请提供问题现象和必要信息，请勿填写密码或密钥').fill('<img src=x onerror=window.ticketXss=1>请排查连接问题')
      await modal.getByRole('button', { name: '确定', exact: true }).click()
    }
    await page.waitForURL(/\/support\/tickets\/\d+$/)
    const id = Number(new URL(page.url()).pathname.split('/').at(-1))
    await page.getByText(subject, { exact: true }).waitFor()
    assert.equal(await page.evaluate(() => window.ticketXss), undefined)
    assert.equal(writes.length, 1, '新建只受理一次')
    await staff.goto(`http://127.0.0.1:5175/admin/support/tickets?subject=${encodeURIComponent(subject)}`)
    await staff.getByText(subject, { exact: true }).waitFor()
    await staff.getByText('详情', { exact: true }).click()
    const drawer = staff.locator('.arco-drawer')
    await drawer.getByText(subject, { exact: true }).waitFor()
    const staffWrites = []; staff.on('request', request => { if (request.method() === 'POST' && new URL(request.url()).pathname.includes('/tickets')) staffWrites.push(request) })
    await drawer.getByRole('button', { name: '发送回复', exact: true }).click()
    await drawer.getByText('请输入回复内容', { exact: true }).waitFor()
    assert.equal(staffWrites.length, 0, '后台字段校验失败不能提交')
    const responseText = `工作人员回复-${width}-<svg onload=window.ticketXss=1>`
    await drawer.getByPlaceholder('输入回复内容，请勿填写密码或密钥').fill(responseText)
    const accepted = staff.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === `/api/admin/v1/tickets/${id}/replies`)
    await drawer.getByRole('button', { name: '发送回复', exact: true }).click()
    assert.equal((await accepted).status(), 200)
    await drawer.getByText(responseText, { exact: true }).waitFor()
    if (width === 390) { await staff.screenshot({ path: `${output}/admin-390.png`, fullPage: true }); assert.equal(await staff.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false) }
    await page.getByRole('button', { name: '刷新', exact: true }).click()
    await page.getByText(responseText, { exact: true }).waitFor()
    await page.getByText('等待会员', { exact: true }).waitFor()
    assert.equal(await page.evaluate(() => window.ticketXss), undefined)
    let notification
    for (let attempt = 0; attempt < 60; attempt++) {
      const result = await page.request.get(`http://127.0.0.1:${mobile ? 5177 : 5176}/api/console/v1/messages?page=1&page_size=20`)
      const payload = await result.json()
      notification = payload.data?.data.find(message => message.business_type === 'ticket' && message.business_id === id)
      if (notification) break
      await new Promise(resolve => setTimeout(resolve, 500))
    }
    assert.ok(notification, '站内通知由真实Worker投递并属于本人')
    await page.goto(`${base}/account/messages`)
    await page.getByText(notification.title, { exact: true }).first().click()
    if (mobile) await page.getByText('查看工单', { exact: true }).click()
    else await page.getByRole('button', { name: '查看工单', exact: true }).click()
    await page.waitForURL(new RegExp(`/support/tickets/${id}$`))
    await page.getByText(responseText, { exact: true }).waitFor()
    const count = writes.length
    await page.getByRole('button', { name: '提交追问', exact: true }).click()
    await page.getByText('请输入内容', { exact: true }).waitFor()
    assert.equal(writes.length, count, '空追问不能发请求')
    const follow = `会员补充信息-${width}`
    if (mobile) await page.getByRole('textbox', { name: '继续追问', exact: true }).fill(follow)
    else await page.getByPlaceholder('补充问题信息，请勿填写密码或密钥').fill(follow)
    await page.getByRole('button', { name: '提交追问', exact: true }).click()
    await page.getByText(follow, { exact: true }).waitFor()
    await page.getByText('等待后台', { exact: true }).waitFor()
    await page.screenshot({ path: `${output}/${mobile ? 'mobile' : 'pc'}-${width}.png`, fullPage: true })
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false)
    if (width === 390) {
      await drawer.getByRole('button', { name: '关闭工单', exact: true }).click()
      await staff.getByRole('dialog').getByRole('button', { name: '确定', exact: true }).click()
      await drawer.getByText('工单已关闭，不能继续回复。', { exact: true }).waitFor()
      await page.getByRole('button', { name: '刷新', exact: true }).click()
    } else {
      await page.getByRole('button', { name: '关闭工单', exact: true }).click()
      if (mobile) await page.getByText('确认关闭', { exact: true }).click()
      else await page.getByRole('button', { name: '确定', exact: true }).click()
    }
    await page.getByText('工单已关闭，不能继续回复。如有新问题，请重新提交工单。', { exact: true }).waitFor()
    assert.equal(await page.getByRole('button', { name: '提交追问', exact: true }).count(), 0)
    await page.reload()
    await page.getByText('工单已关闭，不能继续回复。如有新问题，请重新提交工单。', { exact: true }).waitFor()
    assert.deepEqual(errors, [])
    await member.close(); await admin.close()
    console.log(`${mobile ? '移动' : 'PC'} ${width}px：真实HTTP/数据库提交→后台回复→Worker站内通知跳转→追问→${width === 390 ? '后台' : '会员'}关闭，空字段阻止提交、纯文本XSS及无横向溢出通过。`)
  }
} catch (error) {
  let index = 0
  for (const context of browser.contexts()) for (const page of context.pages()) await page.screenshot({ path: `${output}/failure-${index++}.png`, fullPage: true }).catch(() => {})
  throw error
} finally { await browser.close() }
