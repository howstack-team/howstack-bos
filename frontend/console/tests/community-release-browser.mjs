import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
import { consoleBootstrap } from './console-bootstrap-fixture.mjs'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
await mkdir('/tmp/bos-community-scope', { recursive: true })
try {
  for (const [mobile, width] of [[false, 1440], [true, 390], [true, 320]]) {
    const context = await browser.newContext({ viewport: { width, height: 960 }, isMobile: mobile, hasTouch: mobile })
    const page = await context.newPage(), errors = [], writes = []
    page.on('pageerror', error => errors.push(error.message))
    await context.route('**/api/console/v1/**', async route => {
      const path = new URL(route.request().url()).pathname.replace('/api/console/v1', '')
      if (route.request().method() !== 'GET') writes.push(path)
      let data = {}
      if (path === '/bootstrap') data = consoleBootstrap
      else if (path === '/auth/me') data = { user: { id: '728461931', nickname: '范围验收会员', mobile: '13800138000', email: '' } }
      else if (path === '/wallet') data = { currency: 'CNY', available_minor: 10000, frozen_minor: 0 }
      else if (path === '/messages/summary') data = { unread: 0 }
      else if (path === '/messages') data = { data: [{ id: 1, title: '范围验收消息', body: '已有消息继续可读', created_at: '2026-10-18 12:00:00', unread: false }], total: 1 }
      await route.fulfill({ json: { code: 0, message: 'ok', data, request_id: 'scope-fixture' } })
    })
    const base = `http://127.0.0.1:${mobile ? 5177 : 5176}/console`
    await page.goto(`${base}/overview`)
    await page.getByText(mobile ? '账户总览' : '欢迎回来，范围验收会员', { exact: true }).waitFor()
    for (const text of ['物理服务器', '服务器托管', '续费管理', '产品续费', '发票管理', '安全设置', '优惠券']) assert.equal(await page.getByText(text, { exact: true }).count(), 0, text)
    assert.ok(await page.getByText(mobile ? '工单支持' : '提交工单', { exact: true }).count())
    if (mobile) {
      await page.getByText('我的', { exact: true }).click()
      await page.getByText('范围验收会员', { exact: true }).waitFor()
      for (const text of ['安全设置', '实名认证', '登录记录', '发票管理', '优惠券']) assert.equal(await page.getByText(text, { exact: true }).count(), 0)
      assert.ok(await page.getByText('工单管理', { exact: true }).count())
    } else {
      await page.getByRole('button', { name: '查看更多', exact: true }).click()
      for (const text of ['物理服务器', '发票管理', '安全设置']) assert.equal(await page.getByText(text, { exact: true }).count(), 0)
      await page.mouse.click(10, 150)
      await page.getByText('全部快捷入口', { exact: true }).waitFor({ state: 'hidden' })
    }
    await page.screenshot({ path: `/tmp/bos-community-scope/${mobile ? 'mobile' : 'pc'}-${width}.png`, fullPage: true })
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false)
    for (const path of ['/account/security', '/finance/invoices', '/services/physical-servers', '/services/renewals']) {
      await page.goto(`${base}${path}`)
      await page.getByText('页面不存在', { exact: true }).waitFor()
    }
    await page.goto(`${base}/account/messages`)
    await page.getByText('范围验收消息', { exact: true }).waitFor()
    await page.goto(`${base}/support/tickets`)
    await page.getByText('工单管理', { exact: true }).last().waitFor()
    assert.equal(await page.getByText('页面不存在', { exact: true }).count(), 0, '工单保留')
    assert.deepEqual(writes, [], '范围检查不产生业务写入')
    assert.deepEqual(errors, [])
    await context.close()
    console.log(`${mobile ? '移动' : 'PC'} ${width}px：主导航/快捷入口收口、暂缓直达拒绝、工单保留、真实消息组件接入及无溢出通过（模拟 API）`)
  }
} finally { await browser.close() }
