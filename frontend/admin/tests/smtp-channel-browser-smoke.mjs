import assert from 'node:assert/strict'
import { readFile, mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const schema = JSON.parse(await readFile(new URL('../../../backend/plugins/mail/smtp/configuration.schema.json', import.meta.url), 'utf8'))
const routes = await readFile(new URL('../src/routes.ts', import.meta.url), 'utf8')
const grants = [...new Set([...routes.matchAll(/['"](admin\.[\w.-]+)['"]/g)].map(m => m[1])), 'admin.channels.mail.create', 'admin.channels.mail.update', 'admin.channels.mail.delete', 'admin.channels.mail.test']
const platform = {id:'smtp', name:'SMTP 邮件', category:'mail', version:'1.0.0', configuration_fields:schema.fields, platform_available:true, template_review:{submit:false,query:false}}
let row = {id:1, category:'mail', name:'公司邮件', plugin_id:'smtp', platform_name:'SMTP 邮件', platform_version:'1.0.0', platform_available:true,
 configuration_current:true, status:0, is_default:false, sort_order:0, description:'', revision:1, created_at:'2026-10-06 12:00:00', updated_at:'2026-10-06 12:00:00'}
let configuration = {host:'smtp.example.test', port:465, encryption:'ssl', auth:true, username:'fixture@example.test', from_email:'fixture@example.test', from_name:'云业务通知', reply_to:'', timeout:10}
const errors=[], requests=[], writes=[]
let outcome='accepted', failOnce=false
const browser=await chromium.launch({headless:true})
const output='/tmp/howstack-smtp';await mkdir(output,{recursive:true})
try {
 const context=await browser.newContext({viewport:{width:1440,height:900}})
 await context.route('**/api/admin/v1/**',async route=>{
  const req=route.request(),path=new URL(req.url()).pathname.replace('/api/admin/v1',''),method=req.method()
  requests.push({path,method});let data={data:[],total:0}
  if(path==='/auth/me')data={user:{id:1,username:'mock',nickname:'测试管理员',is_founder:false},permissions:grants}
  else if(path==='/system-updates/notice')data={available:false}
  else if(path==='/channels/mail/platforms')data=[platform]
  else if(path==='/channels/mail/1/test'){
   const input=req.postDataJSON();writes.push({path,input})
   if(failOnce){failOnce=false;await route.abort('failed');return}
   data={status:outcome,message_id:`<${input.request_key}@howstack.local>`,message:outcome==='accepted'?'邮件服务器已接收，请到收件邮箱确认；接收成功不代表最终送达。':'发送结果未知，服务器可能已接收，请先确认邮箱或服务器记录，避免重复发送。'}
  }
  else if(path==='/channels/mail/1'&&method==='PUT'){
   const input=req.postDataJSON();writes.push({path,input});configuration={...input.configuration};delete configuration.password
   row={...row,...input,revision:row.revision+1};delete row.configuration
   data={...row,configuration,configured_secrets:{password:true},configuration_fields:schema.fields}
  }
  else if(path==='/channels/mail/1')data={...row,configuration,configured_secrets:{password:true},configuration_fields:schema.fields}
  else if(path==='/channels/mail')data={data:[row],total:1}
  await route.fulfill({json:{code:0,message:'成功',data,request_id:'smtp-browser-mock'}})
 })
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message))
 const field=(container,label)=>container.locator('.arco-form-item').filter({has:page.locator('label').getByText(label,{exact:true})}).first()
 const ready=()=>page.locator('.page-card tbody').getByText('公司邮件',{exact:true}).waitFor()
 await page.goto(`${base}/channels/mail`);await ready()
 await page.locator('.page-card tbody').getByText('测试发送',{exact:true}).click()
 const modal=page.locator('.arco-modal:visible');await modal.getByText('测试发送邮件',{exact:true}).waitFor()
 await field(modal,'收件邮箱').locator('input').fill('receiver@example.test')
 await modal.getByRole('button',{name:'发送测试邮件',exact:true}).click()
 await modal.getByText('邮件服务器已接收，请到收件邮箱确认；接收成功不代表最终送达。',{exact:true}).waitFor()
 assert.equal(writes.length,1);assert.equal(writes[0].input.revision,1);assert.match(writes[0].input.request_key,/^[a-f0-9-]{36}$/)
 await modal.getByRole('button',{name:'关闭',exact:true}).click();await modal.waitFor({state:'hidden'})
 await page.locator('.page-card tbody').getByText('编辑',{exact:true}).click()
 const drawer=page.locator('.arco-drawer:visible');await field(drawer,'邮件服务器').locator('input').waitFor()
 assert.equal(await field(drawer,'密码或授权码').locator('input').inputValue(),'')
 assert.equal(await field(drawer,'服务器端口').locator('.arco-input-number-mode-button').count(),1)
 assert(await field(drawer,'账号认证').getByRole('switch').isChecked())
 await page.waitForFunction(()=>Array.from(document.querySelectorAll('.arco-drawer')).some(el=>{const r=el.getBoundingClientRect();return r.height>0&&r.width>=500&&r.x<innerWidth-500&&r.right<=innerWidth+1}))
 await page.screenshot({path:`${output}/smtp-editor-desktop.png`})
 await drawer.getByRole('button',{name:'保存',exact:true}).click();await drawer.waitFor({state:'hidden'})
 assert.equal(writes[1].input.configuration.password,'');assert.equal(writes[1].input.configuration.timeout,10)
 await page.setViewportSize({width:390,height:844});await page.goto(`${base}/channels/mail`);await ready()
 await page.waitForFunction(()=>document.documentElement.scrollWidth<=innerWidth)
 await page.locator('.page-card tbody').getByText('测试发送',{exact:true}).click()
 await field(modal,'收件邮箱').locator('input').waitFor();await field(modal,'收件邮箱').locator('input').fill('receiver@example.test')
 failOnce=true;await modal.getByRole('button',{name:'发送测试邮件',exact:true}).click()
 await modal.getByRole('button',{name:'查询发送结果',exact:true}).waitFor();const firstKey=writes.at(-1).input.request_key
 outcome='unknown';await modal.getByRole('button',{name:'查询发送结果',exact:true}).click()
 await modal.getByText('发送结果未知，服务器可能已接收，请先确认邮箱或服务器记录，避免重复发送。',{exact:true}).waitFor()
 assert.equal(writes.at(-1).input.request_key,firstKey)
 const bounds=await modal.boundingBox();assert(bounds.x>=0&&bounds.x+bounds.width<=391)
 await page.screenshot({path:`${output}/smtp-test-mobile.png`})
 await modal.getByRole('button',{name:'关闭',exact:true}).click();await modal.waitFor({state:'hidden'})
 // 权限独立：查看+编辑不能获得测试发送权限。
 const viewer=await browser.newContext({viewport:{width:1440,height:900}})
 await viewer.route('**/api/admin/v1/**',async route=>{
  const path=new URL(route.request().url()).pathname;let data={data:[row],total:1}
  if(path.endsWith('/auth/me'))data={user:{id:2,username:'viewer',nickname:'查看人',is_founder:false},permissions:['admin.channels.mail.view','admin.channels.mail.update']}
  else if(path.endsWith('/platforms'))data=[platform]
  else if(path.endsWith('/notice'))data={available:false}
  await route.fulfill({json:{code:0,message:'成功',data,request_id:'viewer-mock'}})
 })
 const view=await viewer.newPage();await view.goto(`${base}/channels/mail`);await view.locator('tbody').getByText('公司邮件',{exact:true}).waitFor()
 assert.equal(await view.locator('tbody').getByText('测试发送',{exact:true}).count(),0)
 assert.deepEqual(errors,[])
 console.log(JSON.stringify({base,desktop:true,mobile390:true,testLink:true,secretRedacted:true,timeoutKeepsSameKey:true,independentPermission:true,mockWrites:writes.length,realApiWrites:0,errors},null,2))
}finally{await browser.close()}
