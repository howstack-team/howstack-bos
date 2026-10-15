import assert from 'node:assert/strict'
import { readFile, mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = '/tmp/howstack-channels'
await mkdir(output, { recursive: true })
const defaultLabels = { sms: '主用通道', mail: '主用通道', storage: '默认存储' }
const categories = { payment: '支付通道', sms: '短信通道', mail: '邮件通道', identity: '实名认证', storage: '文件存储', oauth: '第三方登录', captcha: '人机验证' }
const routes = await readFile(new URL('../src/routes.ts', import.meta.url), 'utf8')
const permissions = [...new Set([...routes.matchAll(/['"](admin\.[\w.-]+)['"]/g)].map(m => m[1]))]
for (const category of Object.keys(categories)) for (const action of ['update']) permissions.push(`admin.channels.${category}.${action}`)
const requests = [], writes = [], errors = []
let fixture = false
const rows = []
const fields = [
 {key:'endpoint',label:'服务地址',type:'url',required:true,max_length:512},
 {key:'api_key',label:'密钥',type:'password',required:false,max_length:128},
 {key:'count',label:'超时时间',type:'integer',required:true,minimum:0,maximum:60,default:0,unit:'秒'},
 {key:'flag',label:'附加设置',type:'boolean',required:true,default:false},
]
const platform = category => ({ id:`${category}_mock`,name:'演示服务平台',category,version:'1.0.0',platform_available:true,template_review:{submit:false,query:false},configuration_fields:category==='storage' ? Array.from({length:13},(_,i)=>({key:`field_${i}`,label:`配置项 ${i+1}`,type:'text',required:false,max_length:64,default:'示例'})) : fields })
const detail = row => ({ ...row,configuration:Object.fromEntries(Object.entries(row.configuration).filter(([k])=>k!=='api_key')),configured_secrets:row.category==='storage'?{}:{api_key:true},configuration_fields:platform(row.category).configuration_fields })
async function mock(route, grants=permissions) {
 const req=route.request(),url=new URL(req.url()),path=url.pathname.replace('/api/admin/v1',''),method=req.method()
 requests.push({path,method,params:Object.fromEntries(url.searchParams)})
 let data={data:[],total:0}
 if(path==='/auth/me') data={user:{id:1,username:'mock',nickname:'模拟管理员',is_founder:false},permissions:grants}
 else if(path==='/system-updates/notice') data={available:false}
 else if(path.startsWith('/channels/')) {
  const [, ,category,part] = path.split('/')
  if (part==='platforms' && method==='GET') data=fixture?[platform(category)]:[]
  else if(method==='PUT') {
   const input=req.postDataJSON(); writes.push({path,method,input})
   const old=rows.find(r=>r.id===Number(part))
   const row={...old,...input,id:old?.id??rows.length+1,category,name:'演示服务平台',plugin_id:`${category}_mock`,configured:true,platform_name:'演示服务平台',platform_version:'1.0.0',platform_available:true,configuration_current:true,revision:input.revision+1,created_at:'2026-10-06 12:00:00',updated_at:'2026-10-06 12:00:00'}
   const index=rows.findIndex(r=>r.id===row.id);if(index>=0)rows[index]=row;else rows.push(row)
   data=detail(row)
  } else if(method==='PATCH') {
   const input=req.postDataJSON();writes.push({path,method,input})
   const row=rows.find(r=>r.id===Number(part));row.status=input.status;row.revision++;if(!row.status)row.is_default=false
   data=detail(row)
  } else if(part) data=detail(rows.find(r=>r.id===Number(part)))
  else {
   const configured=rows.filter(r=>r.category===category)
   const defaults=fixture&&!configured.length?[{id:0,category,configured:false,name:'演示服务平台',plugin_id:`${category}_mock`,platform_name:'演示服务平台',platform_version:'1.0.0',platform_available:true,configuration_current:false,status:0,is_default:false,sort_order:0,description:'',revision:0,created_at:'',updated_at:'',configuration:{}}]:[]
   let filtered=[...configured,...defaults].filter(r=>(!url.searchParams.get('name')||r.platform_name.includes(url.searchParams.get('name'))) && (!url.searchParams.has('status')||String(r.status)===url.searchParams.get('status')))
   if(url.searchParams.get('sort_by')) filtered=filtered.toSorted((a,b)=>(a.sort_order-b.sort_order)*(url.searchParams.get('sort_order')==='desc'?-1:1))
   data={data:filtered,total:filtered.length,default_label:defaultLabels[category]??null}
  }
 }
 await route.fulfill({json:{code:0,message:'成功',data,request_id:'channel-mock'}})
}
const browser=await chromium.launch({headless:true})
try {
 const context=await browser.newContext({viewport:{width:1440,height:900}})
 await context.route('**/api/admin/v1/**',route=>mock(route))
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message))
 const card=()=>page.locator('.page-card')
 const ready=()=>page.waitForFunction(()=>document.querySelector('.page-card .page-toolbar button')&&!document.querySelector('.page-card .page-toolbar button').disabled)
 const noOverflow=()=>page.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth,{},{timeout:5000})
 const field=(container,label)=>container.locator('.arco-form-item').filter({has:page.locator('label').getByText(label,{exact:true})}).first()
 for(const [category,label] of Object.entries(categories)) {
  await page.goto(`${base}/channels/${category}`);await ready()
  await card().getByRole('heading',{name:label,exact:true}).waitFor()
  await card().getByText('暂无通道插件，请安装对应应用。',{exact:true}).waitFor()
  assert.equal(await card().getByRole('button',{name:'新增通道',exact:true}).count(),0)
  assert.equal(await card().getByRole('form',{name:'表格查询',exact:true}).count(),0)
  await noOverflow()
 }
 await page.screenshot({path:`${output}/empty-desktop.png`})
 fixture=true
 for (const category of Object.keys(categories)) {
  await page.goto(`${base}/channels/${category}`);await ready()
  const label = defaultLabels[category]
  assert.equal(await card().getByRole('columnheader',{name:label??'默认通道',exact:true}).count(),label?1:0)
  await card().locator('tbody').getByText('配置',{exact:true}).click()
  const container=page.locator(category==='storage'?'.page-form-editor':'.arco-drawer:visible')
  await container.waitFor()
  for(const value of ['主用通道','默认存储','默认通道']) assert.equal(await field(container,value).count(),value===label?1:0)
  if(category==='storage') await container.getByRole('button',{name:'返回列表',exact:true}).click()
  else await container.getByRole('button',{name:'取消',exact:true}).click()
 }
 await page.goto(`${base}/channels/sms`);await ready()
 await card().locator('tbody').getByText('未配置',{exact:true}).waitFor()
 await card().locator('tbody').getByRole('link',{name:'启用',exact:true}).click()
 const drawer=page.locator('.arco-drawer:visible')
 assert.equal(await field(drawer,'通道名称').count(),0)
 assert.equal(await field(drawer,'服务平台').count(),0)
 await field(drawer,'服务地址').locator('input').fill('https://mock.example.test')
 await field(drawer,'密钥').locator('input').fill('mock-private-value')
 const state=field(drawer,'状态').getByRole('switch'),defaultState=field(drawer,'主用通道').getByRole('switch')
 assert(await defaultState.isDisabled())
 await state.click();assert(!(await defaultState.isDisabled()));await defaultState.click()
 assert.equal(await field(drawer,'超时时间').locator('.arco-input-number-mode-button').count(),1)
 await drawer.getByRole('button',{name:'保存',exact:true}).click()
 await drawer.waitFor({state:'hidden'})
 await card().locator('tbody').getByText('演示服务平台',{exact:true}).waitFor()
 assert.equal(writes[0].method,'PUT');assert.equal(writes[0].path,'/channels/sms/platforms/sms_mock');
 assert.equal(writes[0].input.status,1);assert.equal(writes[0].input.is_default,true)
 assert.equal(writes[0].input.configuration.count,0);assert.equal(writes[0].input.configuration.flag,false)
 await card().locator('tbody').getByText('配置',{exact:true}).click()
 await field(drawer,'密钥').getByText('已设置，留空保持原设置',{exact:true}).waitFor()
 assert.equal(await field(drawer,'密钥').locator('input').inputValue(),'')
 assert(await state.isChecked());assert(await defaultState.isChecked())
 await state.click();assert(await defaultState.isDisabled());assert(!(await defaultState.isChecked()))
 await drawer.getByRole('button',{name:'保存',exact:true}).click();await drawer.waitFor({state:'hidden'})
 assert.equal(writes[1].input.configuration.api_key,'');assert.equal(writes[1].input.revision,1)
 await card().locator('tbody').getByRole('link',{name:'启用',exact:true}).click()
 await page.locator('.arco-modal:visible').getByRole('button',{name:'确定',exact:true}).click()
 await card().locator('tbody').getByRole('link',{name:'禁用',exact:true}).click()
 await page.locator('.arco-modal:visible').getByRole('button',{name:'确定',exact:true}).click()
 assert.equal(writes.at(-1).method,'PATCH');assert.equal(writes.at(-1).input.status,0)
 await card().locator('tbody').getByText('配置',{exact:true}).click()
 await page.reload()
 await field(drawer,'密钥').getByText('已设置，留空保持原设置',{exact:true}).waitFor()
 assert.equal(new URL(page.url()).searchParams.get('configure'),'sms_mock')
 await drawer.getByRole('button',{name:'取消',exact:true}).click();await drawer.waitFor({state:'hidden'})
 await card().locator('tbody').getByText('演示服务平台',{exact:true}).waitFor()
 await page.screenshot({path:`${output}/payment-desktop.png`})
 await page.getByRole('button',{name:'页面配置',exact:true}).click()
 await page.getByText('双列布局',{exact:true}).click()
 await page.mouse.click(10,120)
 await page.getByText('双列布局',{exact:true}).waitFor({state:'hidden'})
 const secondary=page.getByRole('navigation',{name:'二级导航',exact:true})
 await secondary.waitFor()
 for(const label of Object.values(categories)) assert.equal(await secondary.getByText(label,{exact:true}).count(),1)
 await page.screenshot({path:`${output}/double-desktop.png`})
 await page.getByRole('button',{name:'页面配置',exact:true}).click()
 await page.getByText('经典布局',{exact:true}).click()
 await page.mouse.click(10,120)
 await page.getByText('经典布局',{exact:true}).waitFor({state:'hidden'})
 await page.setViewportSize({width:390,height:844})
 await page.waitForFunction(()=>{const r=document.querySelector('.page-card')?.getBoundingClientRect();return r&&r.x<=24&&r.width>=innerWidth-48})
 await noOverflow();await page.screenshot({path:`${output}/payment-390.png`})
 await card().locator('tbody').getByText('配置',{exact:true}).click()
 await field(drawer,'密钥').locator('input').waitFor()
 await page.waitForFunction(()=>{return Array.from(document.querySelectorAll('.arco-drawer')).some(e=>{const r=e.getBoundingClientRect();return r.x<=24&&r.width>=innerWidth-32&&r.height>0})})
 await noOverflow();await page.screenshot({path:`${output}/drawer-390.png`})
 await drawer.locator('.arco-drawer-close-icon').click();await drawer.waitFor({state:'hidden'})
 await page.setViewportSize({width:1440,height:900})
 await page.goto(`${base}/channels/storage`);await ready()
 await card().locator('tbody').getByText('配置',{exact:true}).click()
 await page.locator('.page-form-editor').waitFor()
 assert.equal(await field(page.locator('.page-form-editor'),'配置项 1').locator('input').inputValue(),'示例')
 await card().locator('.table-scroll').first().waitFor({state:'hidden'})
 assert.equal(await card().locator('.table-scroll').first().isVisible(),false,'复杂配置隐藏列表而非追加到列表底部')
 assert.equal(await page.locator('.arco-drawer:visible').count(),0)
 await page.locator('.page-form-editor').getByRole('button',{name:'返回列表',exact:true}).click()
 await card().locator('.table-scroll').first().waitFor();assert(!new URL(page.url()).searchParams.has('configure'))
 const viewer=await browser.newContext({viewport:{width:1440,height:900}})
 await viewer.route('**/api/admin/v1/**',route=>mock(route,['admin.channels.sms.view']))
 const readOnly=await viewer.newPage();readOnly.on('pageerror',e=>errors.push(e.message))
 await readOnly.goto(`${base}/channels/sms`)
 await readOnly.locator('.page-card tbody').getByText('查看配置',{exact:true}).click()
 await readOnly.locator('.arco-drawer:visible').getByText('已设置，留空保持原设置',{exact:true}).waitFor()
 assert.equal(await readOnly.getByRole('button',{name:'新增通道',exact:true}).count(),0)
 assert.equal(await readOnly.locator('.arco-drawer:visible').getByRole('button',{name:'保存',exact:true}).count(),0)
 assert(await readOnly.locator('.arco-drawer:visible input').first().isDisabled())
 assert(!requests.some(req=>req.method==='POST'&&/^\/channels\/[^/]+$/.test(req.path)),'没有新增通道请求')
 assert(!requests.some(req=>req.method==='DELETE'),'没有删除通道请求')
 assert.deepEqual(errors,[])
 console.log(JSON.stringify({passed:true,categories:Object.keys(categories).length,widths:[1440,390],mockWrites:writes.length,realWrites:0,screenshots:output}))
} finally {await browser.close()}
