import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const out='/tmp/howstack-message-console-browser'; await mkdir(out,{recursive:true}); const browser=await chromium.launch({headless:true})
for(const width of [1440,390]){
 const context=await browser.newContext({viewport:{width,height:900}}),p=await context.newPage(); const errors=[]; p.on('pageerror',e=>errors.push(e.message)); let unread=true
 await context.route('**/api/console/v1/**',async route=>{const path=new URL(route.request().url()).pathname.replace('/api/console/v1','');let data=null
 if(path==='/auth/me')data={user:{id:'72846193',email:null,mobile:'13800138000',nickname:'测试会员'}}
 else if(path==='/wallet')data={currency:'CNY',available_minor:1200,frozen_minor:0}
 else if(path==='/messages/summary')data={unread:unread?1:0,categories:{},topics:{}}
 else if(path==='/messages')data={data:[{id:1,title:'订单支付成功',body:'订单编号：TEST-ORDER\n金额：12.00',unread,created_at:'2026-10-07 15:00:00'}],total:1}
 else if(path==='/messages/1/read'||path==='/messages/read-all')unread=false
 await route.fulfill({json:{code:0,message:'成功',data}})
 })
 await p.goto(`${process.env.CONSOLE_BASE_URL ?? 'http://127.0.0.1:5176/console'}/finance/wallet`); await p.getByLabel('1条未读消息').waitFor()
 await p.getByRole('button',{name:'通知',exact:true}).click();await p.getByText('订单支付成功',{exact:true}).click()
 await p.getByText('订单编号：TEST-ORDER',{exact:false}).waitFor(); await p.getByLabel('0条未读消息').waitFor();await p.waitForTimeout(500)
 await p.screenshot({path:`${out}/inbox-${width}.png`,fullPage:true})
 assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth))
 await p.reload();await p.getByRole('button',{name:'通知',exact:true}).waitFor();await p.getByLabel('0条未读消息').waitFor();assert.equal(unread,false);assert.deepEqual(errors,[])
 await context.close()
}
await browser.close();console.log(JSON.stringify({passed:true,widths:[1440,390],realWrites:0,externalMessages:0,screenshots:out}))
