import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const errors = [], writes = []
await mkdir('/tmp/howstack-order-ui', { recursive: true })
const allPermissions = ['view','create','pay','cancel','update'].map(action => `admin.page.order-list.${action}`).concat(['admin.finance.refunds.view','admin.finance.refunds.create','admin.finance.invoices.view','admin.finance.invoices.create'])
const resource = (id, name, extra = {}) => ({ id, name, status: 1, ...extra })
const options = { regions: [resource(1,'宁波')], zones: [resource(1,'宁波一区')], lines: [resource(1,'电信')], flavor_groups: [resource(1,'通用型')], flavors: [resource(1,'2核2G',{ group_id:1 })], image_types:[resource(1,'Ubuntu')], images:[resource(1,'Ubuntu 24',{ type_id:1, min_disk_gib:20, default_username:'root', default_port:22, login_methods:['password'] })], disk_types:[resource(1,'SSD',{ min_gib:20,max_gib:100,step_gib:1 })], protections:[] }
async function setup(readOnly = false) {
  const context = await browser.newContext(), page = await context.newPage()
  page.on('pageerror', error => errors.push(error.message))
  const row = { id:1, order_no:'ORDER-TEST', member_id:1, member_mobile_snapshot:'13800138001', member_nickname_snapshot:'测试会员', order_type:'purchase',status:'pending_payment',payment_status:'unpaid',currency:'CNY',subtotal_minor:1000,discount_minor:200,payable_minor:800,paid_minor:0,refunded_minor:0,remark:'',paid_at:null,completed_at:null,cancelled_at:null,created_at:'2026-10-08 12:00:00',updated_at:'2026-10-08 12:00:00' }
  await page.route('**/api/admin/v1/**', async route => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/admin/v1','')
    let data = null
    if (path === '/auth/me') data = { user:{ id:1,username:'mock',nickname:'模拟管理员',is_founder:false }, permissions:readOnly ? ['admin.page.order-list.view'] : allPermissions }
    else if (path === '/orders/products') data = [{id:1,name:'云服务器',product_type:'cloud_server',version_id:1}]
    else if (path === '/orders/members' || path.endsWith('/members')) data = [{id:1,label:'测试会员（ID：1）'}]
    else if (path.endsWith('/options')) data = [{id:1,label:'ORDER-TEST · 实例 1',available_minor:800}]
    else if (path === '/orders/preview') {
      const input = request.postDataJSON(), selection = input.selection
      data = input.mode === 'options' ? {schema_version:'2.0.0',configuration:selection,options,invalid_fields:{},cycles:['month'],quantity_limit:3,system_disk_included_gib:20,storage_policy:{default_type_id:1,default_size_gib:20,max_data_disks:1},durations:[{months:1,label:'月付'}],network:{},line_network:selection.line_id ? {egress:{min:0,max:100,default:5,included:5,step:1},ingress:{min:0,max:100,default:0,included:0,step:1},ip:{mode:'range',min:1,max:4,default:1,included:1,step:1}} : null} : {configuration:{...selection,login_method:'password'},currency:'CNY',quantity:selection.quantity,unit_amount_minor:800,amount_minor:800*selection.quantity,subtotal_minor:1000*selection.quantity,discount_minor:200*selection.quantity,unit_breakdown:[]}
    } else if (path === '/orders' && request.method() === 'GET') data = {data:[row],total:1}
    else if (path === '/orders/1' && request.method() === 'GET') data = {...row,items:[{id:1,line_no:1,product_type:'cloud_server',product_type_version:'2.0.0',sales_version:'1',product_name:'云服务器',quantity:1,unit_price_minor:1000,total_minor:1000}],units:[{id:1,order_item_id:1,unit_index:1,service_id:null,allocated_minor:800,refunded_minor:0,reserved_refund_minor:0}]}
    else if (request.method() === 'POST') {
      const body = request.postDataJSON(); writes.push({path,body}); data = {id:1}
      if (path === '/orders') { assert.match(body.request_key,/^[a-f0-9-]{36}$/); assert.equal(body.expected_amount_minor,800); assert.ok(body.selection.region_id); }
      if (path.endsWith('/pay')) {row.status='paid';row.payment_status='paid';row.paid_minor=800}
      if (path.endsWith('/cancel')) row.status='cancelled'
      if (path.endsWith('/update')) row.remark=body.remark
    }
    await route.fulfill({json:{code:0,message:'ok',data,request_id:'order-browser'}})
  })
  return {context,page}
}
async function choose(drawer,label,text) {
  const field = drawer.locator('.arco-form-item').filter({has:drawer.page().getByText(label,{exact:true})})
  await field.locator('.arco-select').click()
  await drawer.page().locator('.arco-select-option').filter({hasText:text}).click()
}
try {
  for (const width of [1440,390]) {
    const {context,page} = await setup(); await page.setViewportSize({width,height:1000}); await page.goto(`${base}/orders`)
    await page.getByRole('button',{name:'创建订单',exact:true}).click()
    const drawer = page.locator('.arco-drawer').filter({has:page.locator('.arco-drawer-header-title',{hasText:'创建订单'})})
    await choose(drawer,'会员','测试会员'); await choose(drawer,'产品','云服务器')
    for (const [label,text] of [['地域','宁波'],['可用区','宁波一区'],['线路','电信'],['规格组','通用型'],['规格','2核2G'],['镜像类型','Ubuntu'],['镜像','Ubuntu 24']]) await choose(drawer,label,text)
    await drawer.getByRole('button',{name:'计算费用',exact:true}).click()
    await drawer.getByText('费用预览',{exact:true}).waitFor()
    await page.waitForTimeout(400)
    await drawer.getByText('费用预览',{exact:true}).scrollIntoViewIfNeeded()
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth))
    await page.screenshot({path:`/tmp/howstack-order-ui/create-${width}.png`,fullPage:true})
    await drawer.getByRole('button',{name:'创建订单',exact:true}).click()
    await drawer.waitFor({state:'hidden'})
    await page.getByText('实例金额明细',{exact:true}).waitFor()
    await page.locator('.arco-drawer').filter({has:page.locator('.arco-drawer-header-title',{hasText:'订单详情'})}).locator('.arco-drawer-close-icon').click()
    await page.getByRole('link',{name:'余额付款',exact:true}).click()
    const modal = page.locator('.arco-modal').filter({has:page.getByText('确认余额付款',{exact:true})})
    await modal.getByRole('button',{name:'确定',exact:true}).click(); await modal.waitFor({state:'hidden'})
    await page.getByRole('link',{name:'详情',exact:true}).click()
    await page.getByRole('button',{name:'申请开票',exact:true}).click()
    const finance = page.locator('.arco-drawer').filter({has:page.locator('.arco-drawer-header-title',{hasText:'申请开票'})})
    await finance.locator('.arco-select-view-value-mirror').filter({hasText:'测试会员（ID：1）'}).waitFor()
    await finance.locator('.arco-select-view-value').filter({hasText:/ORDER-TEST · 实例 1/}).waitFor()
    await finance.locator('.arco-drawer-close-icon').click()
    await page.getByRole('link',{name:'备注',exact:true}).click()
    const remarkModal = page.locator('.arco-modal').filter({has:page.getByText('编辑订单备注',{exact:true})})
    await remarkModal.getByPlaceholder('请输入订单备注（选填）').fill('已核实')
    await remarkModal.getByRole('button',{name:'确定',exact:true}).click(); await remarkModal.waitFor({state:'hidden'})
    await page.screenshot({path:`/tmp/howstack-order-ui/list-${width}.png`,fullPage:true})
    await context.close()
  }
  const {context,page} = await setup(true); await page.goto(`${base}/orders`); await page.getByRole('link',{name:'详情',exact:true}).waitFor()
  for (const name of ['创建订单','余额付款','取消','备注']) assert.equal(await page.getByRole(name==='创建订单'?'button':'link',{name,exact:true}).count(),0)
  await context.close(); assert.deepEqual(errors,[])
  console.log(JSON.stringify({mocked:true,realWrites:0,widths:[1440,390],writes:writes.length,screenshots:'/tmp/howstack-order-ui'}))
} finally {await browser.close()}
