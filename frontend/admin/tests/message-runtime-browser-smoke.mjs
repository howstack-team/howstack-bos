import assert from 'node:assert/strict'
import { readFile, mkdir } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const out = '/tmp/howstack-message-runtime-browser'; await mkdir(out,{recursive:true})
const browser = await chromium.launch({headless:true})
const routes=await readFile(new URL('../src/routes.ts',import.meta.url),'utf8')
const permissions=[...new Set([...routes.matchAll(/['"](admin\.[\w.-]+)['"]/g)].map(m=>m[1]))]
for(const width of [1440,390]){
 const context=await browser.newContext({viewport:{width,height:900}});const p=await context.newPage();const errors=[];p.on('pageerror',e=>errors.push(e.message))
 const items=[{id:1,title:'您有新的订单：ORDER-ONE',body:'订单编号：ORDER-ONE\n金额：12.00',unread:true,created_at:'2026-10-07 15:00:00',category:'trade',topic:'new-order',business_type:'order',business_id:1},{id:2,title:'您有新的订单：ORDER-TWO',body:'订单编号：ORDER-TWO\n金额：24.00',unread:true,created_at:'2026-10-07 15:01:00',category:'trade',topic:'new-order',business_type:'order',business_id:2}]
 await p.route('**/api/admin/v1/**',async route=>{
 const req=route.request(),url=new URL(req.url()),path=url.pathname.replace('/api/admin/v1','');let data={data:[],total:0}
 if(path==='/auth/me')data={user:{id:1,username:'mock',nickname:'模拟管理员',is_founder:false},permissions}
 else if(path==='/system-updates/notice')data={available:false}
 else if(path==='/messages/summary'){const count=items.filter(item=>item.unread).length;data={unread:count,categories:{trade:count},topics:{'new-order':count}}}
 else if(path==='/messages'){const rows=items.filter(item=>(!url.searchParams.get('topic')||item.topic===url.searchParams.get('topic')));data={data:rows,total:rows.length}}
 else if(/^\/messages\/\d+\/read$/.test(path)){const id=Number(path.split('/')[2]);items.find(item=>item.id===id).unread=false;data=null}
 else if(path==='/messages/read-all'){items.forEach(item=>item.unread=false);data=null}
 await route.fulfill({json:{code:0,message:'成功',data,request_id:'message-mock'}})
 })
 await p.goto(`${base}/audit/messages`);await p.getByRole('button',{name:'通知',exact:true}).waitFor()
 await p.locator('.notification-center-trigger-badge').filter({hasText:'2'}).waitFor()
 await p.getByRole('button',{name:'通知',exact:true}).click();await p.getByText('您有新的订单：ORDER-ONE',{exact:true}).waitFor()
 await p.getByLabel('展开消息').first().click();await p.getByRole('link',{name:'查看详情',exact:true}).waitFor(); assert.equal(items.filter(i=>i.unread).length,1)
 await p.waitForTimeout(600); await p.screenshot({path:out+`/center-${width}.png`,fullPage:true}); assert.ok(await p.getByRole('link',{name:'查看详情',exact:true}).isVisible())
 assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${width} overflow`)
 await p.getByRole('button',{name:'全部已读',exact:true}).filter({visible:true}).first().click();await p.waitForFunction(()=>!document.querySelector('.notification-center-trigger-badge .arco-badge-number'))
 await p.reload();await p.getByRole('button',{name:'通知',exact:true}).waitFor();assert.equal(items.filter(i=>i.unread).length,0)
 await p.getByRole('button',{name:'通知',exact:true}).click();await p.getByText('您有新的订单：ORDER-ONE',{exact:true}).waitFor();assert.equal(await p.locator('.notification-center-dot.is-unread').count(),0)
 assert.deepEqual(errors,[]); await context.close()
}
await browser.close();console.log(JSON.stringify({passed:true,realWrites:0,mockUnreadAndReload:[1440,390],screenshots:out}))
