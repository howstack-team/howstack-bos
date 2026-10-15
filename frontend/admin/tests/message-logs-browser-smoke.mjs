import assert from 'node:assert/strict'
import { readFile, mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base=process.env.ADMIN_BASE_URL??'http://127.0.0.1:5175/admin',out='/tmp/howstack-message-logs-browser';await mkdir(out,{recursive:true})
const routes=await readFile(new URL('../src/routes.ts',import.meta.url),'utf8')
const permissions=[...new Set([...routes.matchAll(/['"](admin\.[\w.-]+)['"]/g)].map(m=>m[1])),'admin.message-logs.retry']
const browser=await chromium.launch({headless:true})
try{
 for(const width of [1440,390]){
  const context=await browser.newContext({viewport:{width,height:900}}),page=await context.newPage(),errors=[],queries=[],writes=[]
  page.on('pageerror',e=>errors.push(e.message))
  const deliveries=[{id:11,audience:'customer',account_id:1,group_key:'customer',group_name:'关联客户',read_at:null,channel:'message',status:'delivered',attempts:1,error_code:'',updated_at:'2026-10-07 16:00:00'},
   {id:12,audience:'admin',account_id:1,group_key:'support',group_name:'售后团队',read_at:null,channel:'email',channel_name:'SMTP',status:'failed',attempts:3,error_code:'provider_failed',updated_at:'2026-10-07 16:00:00'}]
  const log=()=>({id:1,name:'订单支付成功',scene:'order_paid',purpose:'notification',business_type:'order',business_id:7,created_at:'2026-10-07 16:00:00',status:deliveries[1].status==='failed'?'partial':'pending',delivery_states:Object.fromEntries(deliveries.map(d=>[d.status,1])),groups:deliveries.map(d=>({id:d.group_key,name:d.group_name,audience:d.audience,channel:d.channel,count:1}))})
  await page.route('**/api/admin/v1/**',async route=>{
   const req=route.request(),url=new URL(req.url()),path=url.pathname.replace('/api/admin/v1','');let data={data:[],total:0}
   if(path==='/auth/me')data={user:{id:1,username:'mock',nickname:'模拟管理员',is_founder:false},permissions}
   else if(path==='/messages/summary')data={unread:0,categories:{},topics:{}}
   else if(path==='/system-updates/notice')data={available:false}
   else if(path==='/message-logs'){
    queries.push(url.searchParams.toString());const match=deliveries.some(d=>(!url.searchParams.get('channel')||d.channel===url.searchParams.get('channel'))&&(!url.searchParams.get('delivery_status')||d.status===url.searchParams.get('delivery_status'))&&(!url.searchParams.get('audience')||d.audience===url.searchParams.get('audience')))
    const fits=match&&(!url.searchParams.get('status')||log().status===url.searchParams.get('status'));data={data:fits?[log()]:[],total:fits?1:0,scenes:[{code:'order_paid',name:'订单支付成功'}]}
   }else if(path==='/message-logs/1')data={...log(),deliveries,attempts:[{delivery_id:12,attempt_no:3,status:'failed',error_code:'provider_failed',duration_ms:82,created_at:'2026-10-07 16:00:00',finished_at:'2026-10-07 16:00:01'}]}
   else if(path==='/message-logs/deliveries/12/retry'){writes.push(path);deliveries[1].status='pending';data=null}
   await route.fulfill({json:{code:0,message:'ok',data,request_id:'mock'}})
  })
  await page.goto(`${base}/audit/messages?audience=admin&business_id=7&delivery_status=failed&business_type=order`)
  await page.waitForURL(url=>!['audience','business_id','delivery_status','business_type'].some(key=>url.searchParams.has(key)))
  const card=page.locator('.page-card');await card.getByRole('table').getByText('部分受理',{exact:true}).waitFor()
  assert.equal(await card.getByText('售后团队',{exact:false}).count(),0)
  await card.getByText('客户、管理员', {exact:true}).waitFor()
  assert.equal(await card.getByRole('form',{name:'表格查询'}).count(),1)
  await page.screenshot({path:`${out}/list-${width}.png`,fullPage:true,animations:'disabled'})
  const form = width===1440 ? page.getByRole('form',{name:'表格查询'}) : page.getByRole('form',{name:'高级筛选'})
  if(width===390)await card.getByRole('button',{name:'筛选',exact:true}).click()
  else assert.equal(await card.getByRole('button', {name:/展开/}).count(), 0)
  const queryCountBeforeEditing = queries.length
  for(const [label,option] of [['消息场景','订单支付成功'],['发送渠道','邮件'],['发送结果','部分受理']]){
   await form.getByLabel(label,{exact:true}).click();await page.getByRole('option',{name:option,exact:true}).click()
  }
  await form.getByPlaceholder('开始日期', {exact:true}).click()
  await form.getByPlaceholder('开始日期', {exact:true}).fill('2026-10-07')
  await form.getByPlaceholder('开始日期', {exact:true}).press('Enter')
  await form.getByPlaceholder('结束日期', {exact:true}).click()
  await form.getByPlaceholder('结束日期', {exact:true}).fill('2026-10-07')
  await form.getByPlaceholder('结束日期', {exact:true}).press('Enter')
  assert.equal(queries.length, queryCountBeforeEditing, '填写条件不应提前查询')
  assert.equal(await form.getByLabel('业务ID', {exact:true}).count(), 0)
  assert.equal(await form.getByLabel('投递结果', {exact:true}).count(), 0)
  if(width===390)await page.locator('.arco-drawer:visible').getByRole('button',{name:'查询',exact:true}).click()
  else await form.getByRole('button',{name:'查询',exact:true}).click()
  await page.waitForURL(/channel=email/);await card.getByRole('table').getByText('部分受理',{exact:true}).waitFor()
  const url=new URL(page.url());assert.equal(url.searchParams.get('created_from'),'2026-10-07');assert.equal(url.searchParams.get('created_to'),'2026-10-07');assert.equal(url.searchParams.get('status'),'partial');assert.equal(url.searchParams.get('scene'),'order_paid');assert.equal(url.searchParams.has('audience'),false);assert.equal(url.searchParams.has('business_id'),false);assert.equal(url.searchParams.has('delivery_status'),false);assert.equal(url.searchParams.get('page'),'1')
  await card.getByRole('link',{name:'详情',exact:true}).click();const drawer=page.locator('.arco-drawer:visible')
  await drawer.getByText('渠道投递',{exact:true}).waitFor();await drawer.getByText('售后团队',{exact:true}).waitFor()
  assert.equal(await drawer.getByText('已受理表示厂商接受请求，不代表最终送达。结果未知时请先核实厂商记录；站内已读单独记录。',{exact:true}).count(),1)
  await drawer.getByText('SMTP', {exact:true}).waitFor()
  await drawer.getByRole('columnheader', {name:'失败原因',exact:true}).waitFor()
  assert.equal(await drawer.getByRole('columnheader', {name:'耗时',exact:true}).isVisible(), false)
  await page.screenshot({path:`${out}/detail-${width}.png`, animations:'disabled'})
  await drawer.getByText('尝试记录（1）', {exact:true}).click()
  await drawer.getByRole('columnheader', {name:'耗时',exact:true}).waitFor()
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${width} overflow`)
  await drawer.getByRole('link',{name:'重试',exact:true}).click();const confirm=page.locator('.arco-modal:visible');await confirm.getByRole('button',{name:'确定',exact:true}).click()
  await drawer.getByText('待发送',{exact:true}).first().waitFor();assert.deepEqual(writes,['/message-logs/deliveries/12/retry'])
  assert.ok(queries.some(q=>q.includes('channel=email')&&q.includes('status=partial')&&q.includes('scene=order_paid')))
  await drawer.locator('.arco-drawer-close-icon').click()
  await page.waitForURL(url=>!url.searchParams.has('message'))
  if(width===390)await card.getByRole('button',{name:/筛选/}).click()
  await (width===390 ? page.locator('.arco-drawer:visible') : form).getByRole('button',{name:'重置',exact:true}).click()
  await page.waitForURL(url=>!['status','scene','channel','created_from','created_to'].some(key=>url.searchParams.has(key)))
  if(width===1440){
   await card.getByRole('table').getByText('待发送',{exact:true}).waitFor()
   await card.getByRole('columnheader',{name:'总体结果',exact:true}).locator('.arco-table-filters').click()
   const popup=page.locator('.arco-table-filters-popup:visible');await popup.getByText('待发送',{exact:true}).click();await popup.getByRole('button',{name:'确定',exact:true}).click()
   await page.waitForURL(url=>url.searchParams.get('status')==='pending')
   assert.equal(await form.getByLabel('发送结果',{exact:true}).innerText(),'待发送')
   await card.getByRole('columnheader',{name:'记录时间',exact:true}).locator('.arco-table-cell-with-sorter').click()
   await page.waitForURL(url=>url.searchParams.get('sort_by')==='created_at')
   assert.equal(new URL(page.url()).searchParams.get('status'),'pending')
   await form.getByRole('button',{name:'重置',exact:true}).click();await page.waitForURL(url=>!url.searchParams.has('status')&&!url.searchParams.has('sort_by'))
  }
  assert.deepEqual(errors,[]);await context.close()
 }
}finally{await browser.close()}
console.log(JSON.stringify({passed:true,widths:[1440,390],mockFilteringDetailAndRetry:true,realWrites:0,screenshots:out}))
