import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { mkdir, readFile } from 'node:fs/promises'
import { resolve } from 'node:path'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const origin = process.env.BACKEND_BASE_URL ?? 'http://127.0.0.1:8989'
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const fixtures = process.env.FILE_PREVIEW_FIXTURES ?? '/tmp/howstack-preview-fixtures'
const output = process.env.FILE_PREVIEW_OUTPUT ?? '/tmp/howstack-file-previews'
const backend = resolve(import.meta.dirname, '../../../backend')
const auth = JSON.parse(execFileSync('php', ['-r', `require 'vendor/autoload.php'; Dotenv\\Dotenv::createUnsafeImmutable(getcwd())->safeLoad(); support\\App::loadAllConfig(['route']); $account=support\\Container::get(app\\modules\\admin\\auth\\contract\\AdminAccountRepositoryInterface::class)->findById(1); $token=support\\Container::get(app\\modules\\admin\\auth\\contract\\AdminAccessTokenServiceInterface::class)->issue($account)->value; echo json_encode(['name'=>config('authentication.admin.cookie_name','hlyq_admin_token'),'value'=>$token]);`], { cwd: backend, encoding: 'utf8' }))
// Playwright 默认隐藏原生滚动条；本用例需要验证用户实际能看到、拖动滚动条。
const browser = await chromium.launch({ headless: true, ignoreDefaultArgs: ['--hide-scrollbars'] })
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, permissions: ['clipboard-read', 'clipboard-write'] })
await context.addCookies([{ name: auth.name, value: auth.value, url: origin, httpOnly: true, sameSite: 'Lax' }])
const page = await context.newPage(), errors = [], created = [], headers = { Origin: origin }
const prefix = `preview-check-${Date.now()}-`
page.on('pageerror', error => errors.push(error.message))
const external = []
page.on('request', request => { if (/unpkg|cdn\.jsdelivr/.test(request.url())) external.push(request.url()) })
// 故意延迟 Excel 样式，验证首次打开时不能在样式未就绪前测量点击层。
await page.route(/(?:js-preview|vue-office).*excel.*\.css/, async route => {
  if (route.request().resourceType() === 'stylesheet') await new Promise(resolve => setTimeout(resolve, 400))
  await route.continue()
})
async function closePreview() {
  await page.getByRole('button', { name: '关闭', exact: true }).click({ timeout: 6000 })
  await page.waitForFunction(() => !document.querySelector('.media-preview-player') && !document.querySelector('.document-preview-body'))
}
try {
  await mkdir(output, { recursive: true })
  const files = []
  for (const extension of ['pdf', 'docx', 'xls', 'xlsx', 'mp3', 'mp4']) {
    const name = `${prefix}${extension}.${extension}`, buffer = await readFile(`${fixtures}/preview.${extension}`)
    const response = await context.request.post(`${origin}/api/admin/v1/files`, { headers, multipart: { file: { name, mimeType: 'application/octet-stream', buffer } } })
    const result = await response.json()
    assert.equal(response.status(), 201, `${extension}: ${result.message}`)
    created.push(result.data.id); files.push({ ...result.data, name, extension, buffer })
  }
  for (const file of files.filter(file => ['mp3', 'mp4'].includes(file.extension))) {
    const full = await context.request.get(origin + file.url)
    assert.equal(full.status(), 200)
    assert.deepEqual(await full.body(), file.buffer)
    assert.equal(full.headers()['accept-ranges'], 'bytes')
    const signed = full.url()
    for (const [range, start, end] of [['bytes=0-31', 0, 32], ['bytes=100-', 100, file.buffer.length], ['bytes=-64', file.buffer.length - 64, file.buffer.length]]) {
      const response = await context.request.get(signed, { headers: { Range: range } })
      assert.equal(response.status(), 206)
      assert.equal(response.headers()['content-range'], `bytes ${start}-${end - 1}/${file.buffer.length}`)
      assert.deepEqual(await response.body(), file.buffer.subarray(start, end))
    }
    assert.equal((await context.request.get(signed, { headers: { Range: 'bytes=9999999999999999-' } })).status(), 416)
    assert.equal((await context.request.get(signed, { headers: { Range: 'bytes=0-31', 'If-Range': 'invalid-validator' } })).status(), 200)
    const invalid = new URL(signed); invalid.searchParams.set('signature', 'a'.repeat(64))
    assert.equal((await context.request.get(invalid.href, { headers: { Range: 'bytes=0-31' } })).status(), 403)
  }
  await page.goto(`${base}/content/files?name=${prefix}`)
  await page.getByRole('article').first().waitFor()
  for (const extension of ['pdf', 'docx', 'xls', 'xlsx']) {
    console.log(`文档预览验证：${extension}`)
    const file = files.find(file => file.extension === extension)
    await page.getByRole('button', { name: `预览 ${file.name}`, exact: true }).click()
    await page.locator('.document-preview-body').waitFor()
    await page.waitForFunction(() => !!document.querySelector('.arco-modal.zoomModal-enter-done'))
    await page.waitForFunction(() => !document.querySelector('.document-preview-loading'), null, { timeout: 40000 })
    assert.equal(await page.locator('.document-preview-error').count(), 0)
    assert.equal(await page.locator('.arco-modal').count(), 1)
    assert.equal(await page.locator('.arco-drawer').count(), 0)
    const frame = page.frameLocator('iframe[title="文档预览"]')
    if (extension === 'docx') await frame.getByText('Preview text remains readable.').waitFor()
    else await frame.locator('canvas').first().waitFor({ timeout: 8000 })
    if (extension === 'pdf') {
      const rendered = await frame.locator('canvas').first().evaluate(canvas => {
        const { data } = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height)
        for (let i = 0; i < data.length; i += 4) if (data[i + 3] > 0 && data[i] < 220 && data[i + 1] < 220 && data[i + 2] < 220) return true
        return false
      })
      assert.ok(rendered, 'PDF 必须渲染实际内容')
    }
    if (['xls', 'xlsx'].includes(extension)) {
      const covered = await frame.locator('.x-spreadsheet-overlayer').evaluate(layer => {
        const canvas = layer.parentElement.querySelector('canvas')
        return Math.abs(layer.clientHeight - canvas.clientHeight) <= 1 && Math.abs(layer.clientWidth - canvas.clientWidth) <= 1
      })
      assert.ok(covered, '点击层必须覆盖整个表格，不能只有顶部几行可操作')
      // 表头高25px、行高24px，点击第二行第一/第二列，验证真实选中事件。
      await frame.locator('.x-spreadsheet-overlayer').click({ position: { x: extension === 'xls' ? 180 : 100, y: 61 } })
      const value = extension === 'xls' ? '123' : 'Product 1'
      await page.waitForFunction(value => document.querySelector('input[aria-label="选中单元格内容"]')?.value === value, value)
      assert.equal(await page.getByRole('textbox', { name: '选中单元格内容', exact: true }).inputValue(), value)
      await page.getByRole('button', { name: '复制单元格内容', exact: true }).click()
      assert.equal(await page.evaluate(() => navigator.clipboard.readText()), value)
      const refresh = page.waitForResponse(response => response.url().includes('/auth/me') && response.status() === 200)
      await page.evaluate(() => window.dispatchEvent(new Event('admin:access-changed')))
      await refresh
      // 等待刷新后 React 提交，必须保留已打开的弹层和选中状态。
      await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))))
      assert.equal(await page.locator('.arco-modal').count(), 1)
      assert.equal(await page.getByRole('textbox', { name: '选中单元格内容', exact: true }).inputValue(), value)
    }
    await page.screenshot({ path: `${output}/${extension}-desktop.png` })
    await closePreview()
    if (extension === 'xlsx') {
      await page.setViewportSize({ width: 390, height: 844 })
      await page.getByRole('button', { name: `预览 ${file.name}`, exact: true }).click()
      await page.waitForFunction(() => !!document.querySelector('.arco-modal.zoomModal-enter-done') && !document.querySelector('.document-preview-loading'))
      await page.frameLocator('iframe[title="文档预览"]').locator('.x-spreadsheet-overlayer').click({ position: { x: 100, y: 61 } })
      await page.waitForFunction(() => document.querySelector('input[aria-label="选中单元格内容"]')?.value === 'Product 1')
      const bounds = await page.locator('.arco-modal').boundingBox()
      assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= 390)
      await page.screenshot({ path: `${output}/xlsx-narrow.png` })
      await closePreview()
      await page.setViewportSize({ width: 1440, height: 1000 })
    }
  }
  for (const extension of ['mp3', 'mp4']) {
    console.log(`媒体预览验证：${extension}`)
    const file = files.find(file => file.extension === extension)
    await page.getByRole('button', { name: `预览 ${file.name}`, exact: true }).click()
    const media = page.locator(`.media-preview-player ${extension === 'mp3' ? 'audio' : 'video'}`)
    await media.waitFor({ state: 'attached' })
    await page.waitForFunction(() => !!document.querySelector('.arco-modal.zoomModal-enter-done'))
    assert.ok(await media.evaluate(el => el.paused), '不自动播放')
    if (extension === 'mp3') {
      assert.equal(await page.locator('.media-preview-player .xgplayer-controls').evaluate(el => getComputedStyle(el).backgroundImage), 'none', '音频控制栏不使用视频黑色渐变')
      assert.ok((await page.locator('.media-preview-player').boundingBox()).height <= 60, '音乐预览使用紧凑控制栏')
    }
    if (extension === 'mp4') await page.locator('.media-preview-player .xgplayer-start').click({ timeout: 8000 })
    else await page.locator('.media-preview-player .xgplayer-play').click({ timeout: 8000 })
    await page.waitForFunction(() => document.querySelector('.media-preview-player audio, .media-preview-player video')?.currentTime > 0.2)
    await media.evaluate(el => { el.currentTime = 8; window.previewTestMedia = el })
    await page.waitForFunction(() => { const el = document.querySelector('.media-preview-player audio, .media-preview-player video'); return !el.seeking && el.currentTime >= 8 })
    assert.equal(await page.locator('.media-preview .arco-alert').count(), 0)
    await page.screenshot({ path: `${output}/${extension}-desktop.png` })
    await closePreview()
    assert.ok(await page.evaluate(() => window.previewTestMedia.paused), '关闭后停止播放')
    assert.equal(await page.locator('.media-preview-player audio, .media-preview-player video').count(), 0)
    await page.setViewportSize({ width: 390, height: 844 })
    await page.getByRole('button', { name: `预览 ${file.name}`, exact: true }).click()
    await page.locator('.media-preview-player .xgplayer-play').waitFor()
    await page.waitForFunction(() => !!document.querySelector('.arco-modal.zoomModal-enter-done'))
    await page.screenshot({ path: `${output}/${extension}-narrow.png` })
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
    await closePreview()
    await page.setViewportSize({ width: 1440, height: 1000 })
  }
  if (process.env.EXCEL_PREVIEW_NAME) {
    const name = process.env.EXCEL_PREVIEW_NAME
    await page.goto(`${base}/content/files?name=${encodeURIComponent(name)}`)
    await page.getByRole('button', { name: `预览 ${name}`, exact: true }).click()
    await page.waitForFunction(() => !!document.querySelector('.arco-modal.zoomModal-enter-done') && !!document.querySelector('.document-preview-body') && !document.querySelector('.document-preview-loading'), null, { timeout: 60000 })
    assert.equal(await page.locator('.document-preview-error').count(), 0)
    const frame = page.frameLocator('iframe[title="文档预览"]')
    const layer = frame.locator('.x-spreadsheet-overlayer')
    const vertical = frame.locator('.x-spreadsheet-scrollbar.vertical')
    const horizontal = frame.locator('.x-spreadsheet-scrollbar.horizontal')
    const verticalBox = await vertical.boundingBox()
    const horizontalBox = await horizontal.boundingBox()
    assert.ok(verticalBox.width >= 14 && horizontalBox.height >= 14, '横纵滚动条必须有足够宽度用于拖动')
    await layer.click({ position: { x: 900, y: 400 } })
    await page.waitForFunction(() => document.querySelector('.document-preview-cell .arco-typography')?.textContent === 'K16')
    const topBefore = await frame.locator('.x-spreadsheet-scrollbar.vertical').evaluate(el => el.scrollTop)
    await layer.hover({ position: { x: 400, y: 300 } })
    await page.mouse.wheel(0, 600)
    await page.waitForFunction(before => document.querySelector('iframe[title="文档预览"]').contentDocument.querySelector('.x-spreadsheet-scrollbar.vertical').scrollTop > before, topBefore)
    await page.mouse.wheel(600, 0)
    await page.waitForFunction(() => document.querySelector('iframe[title="文档预览"]').contentDocument.querySelector('.x-spreadsheet-scrollbar.horizontal').scrollLeft > 0)
    await layer.click({ position: { x: 900, y: 400 } })
    await page.waitForFunction(() => document.querySelector('.document-preview-cell .arco-typography')?.textContent !== 'K16')
    assert.ok((await page.getByRole('textbox', { name: '选中单元格内容', exact: true }).inputValue()).length > 0)
    await horizontal.evaluate(el => { el.dataset.beforeDrag = String(el.scrollLeft) })
    await vertical.evaluate(el => { el.dataset.beforeDrag = String(el.scrollTop) })
    await page.mouse.move(horizontalBox.x + 150, horizontalBox.y + horizontalBox.height / 2)
    await page.mouse.down()
    await page.mouse.move(horizontalBox.x + 230, horizontalBox.y + horizontalBox.height / 2, { steps: 8 })
    await page.mouse.up()
    assert.ok(await horizontal.evaluate(el => el.scrollLeft !== Number(el.dataset.beforeDrag)), '横向滚动条应可拖动')
    await page.mouse.move(verticalBox.x + verticalBox.width / 2, verticalBox.y + 8)
    await page.mouse.down()
    await page.mouse.move(verticalBox.x + verticalBox.width / 2, verticalBox.y + 90, { steps: 8 })
    await page.mouse.up()
    assert.ok(await vertical.evaluate(el => el.scrollTop !== Number(el.dataset.beforeDrag)), '纵向滚动条应可拖动')
    await page.screenshot({ path: `${output}/existing-excel-scroll.png` })
    await closePreview()
    console.log('已上传长表格通过：中下部单元格点击、纵向及横向滚轮、滚动后重新选中；原文件未修改。')
  }
  assert.deepEqual(errors, [])
  assert.deepEqual(external, [])
  console.log('真实上传与浏览器通过：PDF/DOCX/XLS/XLSX 弹窗渲染，Excel 单元格选中与复制、权限刷新保留预览，MP3/MP4 播放、定位进度、关闭销毁，桌面/390px，无外部 CDN；签名校验及 Range 200/206/416。')
} catch (error) {
  await page.screenshot({ path: `${output}/failure.png` })
  console.error('浏览器错误：', errors)
  console.error(String(error.message).replace(/hlyq_admin_token=[^\s]+/g, 'hlyq_admin_token=[redacted]').replace(/signature=[a-f0-9]+/g, 'signature=[redacted]'))
  console.error(String(error.stack).split('\n').filter(line => line.includes('file-preview-browser.mjs')).join('\n'))
  process.exitCode = 1
} finally {
  try {
    if (created.length) {
      const response = await context.request.post(`${origin}/api/admin/v1/files/delete`, { headers, data: { ids: created } })
      const result = await response.json(); assert.equal(response.status(), 200, result.message); assert.equal(result.data.failed.length, 0)
    }
  } finally { await browser.close() }
}
