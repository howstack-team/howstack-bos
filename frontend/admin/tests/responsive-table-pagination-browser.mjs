import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = '/tmp/howstack-responsive-pagination'
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
try {
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } })
  const page = await context.newPage()
  const errors = []
  let total = 194
  const requests = []
  const roles = Array.from({length:134}, (_, index) => ({ id:index+1, name:`分页角色 ${index+1}`, code:`role-${index+1}`,
    description:'分页测试', status:1, data_scope:'self', permissions:[], department_ids:[], is_system:false }))
  page.on('pageerror', error => errors.push(error.message))
  await context.route('**/api/admin/v1/**', route => {
    const url = new URL(route.request().url())
    const path = url.pathname.replace('/api/admin/v1','')
    let data = null
    if (path === '/auth/me') data = {user:{id:1,username:'mock',nickname:'模拟管理员',is_founder:false},
      permissions:['admin.page.operation-logs.view','admin.roles.view','admin.departments.view']}
    else if (path === '/operation-logs') {
      const current = Number(url.searchParams.get('page'))
      const size = Number(url.searchParams.get('page_size'))
      requests.push({current,size})
      data = {total,data:Array.from({length:Math.max(0,Math.min(size,total-(current-1)*size))},(_,index)=>({
        id:(current-1)*size+index+1,operated_at:'2026-10-06 12:00:00',operator_nickname:'模拟管理员',
        module:'系统管理',operation:'修改角色',status:1,client_ip:'127.0.0.1',client_ip_location:'本地',duration_ms:20,
      }))}
    } else if (path === '/roles') data = roles
    else if (path === '/departments') data = []
    else if (path === '/permissions') data = {}
    return route.fulfill({json:{code:0,message:'成功',data,request_id:'responsive-pagination-test'}})
  })
  const pagination = page.locator('.responsive-table-pagination')
  const controls = pagination.locator('.responsive-table-pagination-controls')
  const sizeSelect = pagination.getByLabel('每页条数')
  function waitForPage(current, size) {
    return page.waitForResponse(response => {
      const url = new URL(response.url())
      return url.pathname.endsWith('/operation-logs') && url.searchParams.get('page') === String(current)
        && url.searchParams.get('page_size') === String(size)
    })
  }
  async function selectSize(size, server = false) {
    const response = server ? page.waitForResponse(response => {
      const url = new URL(response.url())
      return url.pathname.endsWith('/operation-logs') && url.searchParams.get('page_size') === String(size)
        && url.searchParams.get('page') === '1'
    }) : null
    await sizeSelect.click()
    const option = page.getByRole('option',{name:`${size} 条/页`,exact:true,includeHidden:true})
    await option.click()
    await option.waitFor({state:'detached'})
    if (response) await response
  }
  async function checkTableScrolling() {
    assert.deepEqual(await page.locator('.table-scroll').evaluateAll(wrappers => wrappers.flatMap(wrapper =>
      Array.from(wrapper.querySelectorAll('*')).concat(wrapper).filter(element =>
        ['auto', 'scroll'].includes(getComputedStyle(element).overflowY) && element.scrollHeight > element.clientHeight
      ).map(element => element.className))), [], '普通列表不能产生内部纵向滚动条')
    assert(await page.locator('.table-scroll').evaluate(wrapper => {
      const scroller = Array.from(wrapper.querySelectorAll('*')).find(element =>
        ['auto', 'scroll'].includes(getComputedStyle(element).overflowX) && element.scrollWidth > element.clientWidth)
      if (!scroller) return false
      scroller.scrollLeft = 40
      const moved = scroller.scrollLeft > 0
      scroller.scrollLeft = 0
      return moved
    }), '长表格仍应支持横向滚动')
  }
  async function checkBounds() {
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth))
    await checkTableScrolling()
    const wrapper = await pagination.boundingBox()
    const summary = await pagination.locator('.responsive-table-pagination-summary').boundingBox()
    const pager = await controls.boundingBox()
    assert(summary.y+summary.height<=pager.y)
    assert(await pagination.evaluate(el=>el.scrollWidth<=el.clientWidth))
    assert(wrapper.x>=0 && wrapper.x+wrapper.width<=page.viewportSize().width)
    assert(await pagination.locator('.responsive-table-pagination-total').evaluate(el=>el.clientHeight<=28))
  }
  await page.goto(`${base}/audit/operations`)
  await pagination.getByText('共 194 条',{exact:true}).waitFor()
  await checkBounds()
  await page.screenshot({path:`${output}/operations-390.png`,fullPage:true})
  const secondPage = waitForPage(2,10)
  await controls.getByLabel('下一页',{exact:true}).click()
  await secondPage
  await page.waitForFunction(()=>document.querySelector('.responsive-table-pagination-controls input')?.value==='2')
  assert.deepEqual(requests.at(-1),{current:2,size:10})
  await controls.getByRole('textbox').fill('20')
  const lastPage = waitForPage(20,10)
  await controls.getByRole('textbox').press('Enter')
  await lastPage
  await page.waitForFunction(()=>document.querySelector('tbody')?.rows.length===4)
  assert.deepEqual(requests.at(-1),{current:20,size:10})
  for (const size of [20,50,100,10]) {
    await selectSize(size, true)
    await page.waitForFunction(size=>document.querySelector('tbody')?.rows.length===size,size)
    assert.deepEqual(requests.at(-1),{current:1,size})
    if (size === 100) {
      await page.evaluate(() => window.scrollTo(0, 0))
      const tableBounds = await page.locator('.table-scroll').boundingBox()
      await page.mouse.move(tableBounds.x + 40, Math.max(200, tableBounds.y + 80))
      await page.mouse.wheel(0, 400)
      await page.waitForFunction(() => document.scrollingElement.scrollTop > 0)
      assert.equal(await page.locator('.table-scroll').evaluate(element => element.scrollTop), 0)
      await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight))
    }
  }
  await page.setViewportSize({width:320,height:844})
  await checkBounds()
  await page.screenshot({path:`${output}/operations-320.png`,fullPage:true})
  total = 99999999
  await page.reload()
  await pagination.getByText('共 99999999 条',{exact:true}).waitFor()
  await checkBounds()
  await page.screenshot({path:`${output}/large-total-320.png`,fullPage:true})
  await page.setViewportSize({width:940,height:900})
  await pagination.locator('.responsive-table-pagination-compact').waitFor()
  await checkBounds()
  await page.screenshot({path:`${output}/narrow-content-940.png`,fullPage:true})
  await page.setViewportSize({width:1440,height:900})
  await page.waitForFunction(()=>!document.querySelector('.responsive-table-pagination-compact'))
  const desktopPage = waitForPage(2,10)
  await pagination.getByLabel('第 2 页',{exact:true}).click()
  await desktopPage
  await page.waitForFunction(()=>document.querySelector('.responsive-table-pagination [aria-current="true"]')?.getAttribute('aria-label')==='第 2 页')
  assert.deepEqual(requests.at(-1),{current:2,size:10})
  await checkTableScrolling()
  await page.screenshot({path:`${output}/operations-1440.png`,fullPage:true})
  console.log('通过：194条与海量记录、320px/390px布局、服务端翻页与跳转、四种条数及重置、恢复桌面分页')
  await page.setViewportSize({width:390,height:844})
  await page.goto(`${base}/roles`)
  await pagination.getByText('共 134 条',{exact:true}).waitFor()
  assert.equal(await page.locator('tbody tr').count(),10)
  await controls.getByLabel('下一页',{exact:true}).click()
  await page.locator('tbody').getByText('分页角色 11',{exact:true}).waitFor()
  await selectSize(50)
  await page.locator('tbody').getByText('分页角色 1',{exact:true}).waitFor()
  assert.equal(await page.locator('tbody tr').count(),50)
  await checkBounds()
  await page.screenshot({path:`${output}/roles-390.png`,fullPage:true})
  assert.deepEqual(errors,[])
  console.log('通过：客户端表格仍正确切片，条数切换重置首屏；表格保留横向滚动、纵向由页面滚动，无运行错误')
  await context.close()
} finally {await browser.close()}
