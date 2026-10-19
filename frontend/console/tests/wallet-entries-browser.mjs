import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
import { consoleBootstrap } from './console-bootstrap-fixture.mjs'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
const output = '/tmp/bos-wallet-entries-browser'
await mkdir(output, { recursive: true })
try {
  for (const [mobile, width] of [[false, 1440], [true, 390], [true, 320]]) {
    const context = await browser.newContext({ viewport: { width, height: 960 }, isMobile: mobile, hasTouch: mobile })
    const page = await context.newPage(), errors = [], writes = [], queries = []
    page.on('pageerror', error => errors.push(error.message))
    const a = { id: '728461931', nickname: '资金会员甲', mobile: '13800138001', email: null }
    const b = { id: '728461932', nickname: '资金会员乙', mobile: '13800138002', email: null }
    let current = a, mode = 'normal', late = false, release, captured
    const capturedPromise = new Promise(resolve => { captured = resolve })
    await context.route('**/api/console/v1/**', async route => {
      const url = new URL(route.request().url()), path = url.pathname.replace('/api/console/v1', '')
      if (route.request().method() !== 'GET') writes.push(path)
      let data = {}, status = 200, message = 'ok'
      if (path === '/bootstrap') data = consoleBootstrap
      else if (path === '/auth/me') { data = current ? { user: current } : null; if (!current) status = 401 }
      else if (path === '/auth/options') data = { sms_login: false, password_reset: { mobile: false, email: false }, oauth_providers: [] }
      else if (path === '/wallet') data = { currency: 'CNY', available_minor: 700, frozen_minor: 0 }
      else if (path === '/messages/summary') data = { unread: 0 }
      else if (path === '/wallet/entries') {
        assert.equal(route.request().method(), 'GET')
        assert.deepEqual([...url.searchParams.keys()].sort(), ['page', 'page_size'])
        const owner = current, number = Number(url.searchParams.get('page')), size = Number(url.searchParams.get('page_size'))
        queries.push({ page: number, size, member: owner?.id })
        const total = mode === 'empty' ? 0 : 45
        data = { total, page: number, page_size: size, data: Array.from({ length: Math.max(0, Math.min(size, total - (number - 1) * size)) }, (_, index) => {
          const sequence = (number - 1) * size + index + 1
          return { entry_no: `${owner?.id === a.id ? 'A' : 'B'}-ENTRY-${String(sequence).padStart(4, '0')}`,
            entry_type: sequence === 2 ? 'spend' : sequence === 3 ? 'withdrawal_payout' : 'credit', amount_minor: sequence === 2 ? -1200 : sequence === 3 ? 0 : 10001,
            available_after_minor: 700, currency: 'CNY', business_type: sequence === 3 ? 'withdrawal' : 'recharge',
            business_no: sequence === 4 ? null : `BUSINESS-${'1234567890'.repeat(5)}-${sequence}`, created_at: '2026-10-19 12:00:00' }
        }) }
        if (mode === 'error') { status = 503; data = null; message = '专项模拟暂时不可用' }
        if (late && owner?.id === a.id) { late = false; captured(); await new Promise(resolve => { release = resolve }) }
      }
      try { await route.fulfill({ status, json: { code: status === 200 ? 0 : status * 100, message, data, request_id: 'wallet-fixture' } }) } catch { /* 会话切换取消的旧响应不再投递。 */ }
    })
    const base = `http://127.0.0.1:${mobile ? 5177 : 5176}/console`
    await page.goto(`${base}/finance/wallet`)
    await page.getByText('资金明细', { exact: true }).last().click()
    await page.getByText('A-ENTRY-0001', { exact: true }).waitFor()
    assert.deepEqual(queries.at(-1), { page: 1, size: 20, member: a.id })
    await page.getByText('¥+100.01', { exact: true }).first().waitFor()
    await page.getByText('¥-12.00', { exact: true }).waitFor()
    await page.getByText('¥0.00', { exact: true }).waitFor()
    assert.equal(await page.getByRole('button', { name: /删除|修改|冻结|打款/ }).count(), 0)
    if (mobile) await page.getByRole('button', { name: '下一页', exact: true }).click()
    else await page.locator('.arco-pagination-item-next').click()
    await page.getByText('A-ENTRY-0021', { exact: true }).waitFor()
    assert.equal(new URL(page.url()).searchParams.get('page'), '2')
    await page.reload()
    await page.getByText('A-ENTRY-0021', { exact: true }).waitFor()
    await page.goBack()
    await page.getByText('A-ENTRY-0001', { exact: true }).waitFor()
    if (!mobile) {
      await page.locator('.arco-pagination-option .arco-select').click()
      await page.getByText('50 条/页', { exact: true }).click()
      await page.getByText('A-ENTRY-0045', { exact: true }).waitFor()
      assert.equal(queries.at(-1).size, 50)
      assert.equal(queries.at(-1).page, 1)
    }
    if (!mobile) assert.equal(await page.locator('.console-main').evaluate(node => node.scrollLeft === 0 && node.scrollWidth <= node.clientWidth + 1), true, '横向滚动应限于表格，不能移动标题或遮住内容')
    await page.screenshot({ path: `${output}/${mobile ? 'mobile' : 'pc'}-${width}.png` })
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, '长业务单号不造成整页横向溢出')
    mode = 'empty'
    await page.getByRole('button', { name: '刷新明细', exact: true }).click()
    await page.getByText('暂无资金明细', { exact: true }).waitFor()
    mode = 'error'
    await page.getByRole('button', { name: '刷新明细', exact: true }).click()
    const feedback = mobile ? page.locator('.arco-toast-content').filter({ hasText: '专项模拟暂时不可用' }) : page.getByText('专项模拟暂时不可用', { exact: true })
    await feedback.waitFor()
    assert.equal(await feedback.count(), 1, '可见接口反馈只有一次（移动端另有读屏播报）')
    await page.getByText('资金明细暂时无法加载', { exact: true }).waitFor()
    mode = 'normal'
    await page.getByRole('button', { name: '重新加载', exact: true }).click()
    await page.getByText('A-ENTRY-0001', { exact: true }).waitFor()
    const second = await context.newPage()
    await second.goto(`${base}/finance/transactions`)
    await second.getByText('A-ENTRY-0001', { exact: true }).waitFor()
    late = true
    await page.getByRole('button', { name: '刷新明细', exact: true }).click()
    await capturedPromise
    current = b
    await second.evaluate(() => { localStorage.setItem('howstack.member-session', `change:${Math.random()}`); window.dispatchEvent(new Event('member:session-changed')) })
    await page.getByText('B-ENTRY-0001', { exact: true }).waitFor()
    release()
    assert.equal(await page.getByText('A-ENTRY-0001', { exact: true }).count(), 0)
    await second.getByText('B-ENTRY-0001', { exact: true }).waitFor()
    current = null
    await second.evaluate(() => { localStorage.setItem('howstack.member-session', `logout:${Math.random()}`); window.dispatchEvent(new Event('member:session-changed')) })
    await page.getByRole('button', { name: '登录', exact: true }).waitFor()
    assert.equal(await page.getByText('B-ENTRY-0001', { exact: true }).count(), 0)
    assert.deepEqual(writes, [], '只读明细验收不请求写接口')
    assert.deepEqual(errors, [])
    await context.close()
    console.log(`${mobile ? '移动端' : 'PC'} ${width}px：入口、金额、默认20条、分页刷新/后退、空态、错误重试、跨标签会话/迟到响应隔离、退出及无页面溢出通过（模拟 API）`)
  }
} finally { await browser.close() }
