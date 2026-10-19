import assert from 'node:assert/strict'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const browser = await chromium.launch({ headless: true })
let page
try {
 const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
 page = await context.newPage()
 const errors = []; page.on('pageerror', error => errors.push(error.message))
 const kinds=['regions','zones','lines','flavor_groups','flavors','image_types','images','disk_types','protections','network_policies']
 const row=(id,name,extra={})=>({id,name,code:`item-${id}`,description:'',sort:0,status:1,...extra})
 const resources=Object.fromEntries(kinds.map(kind=>[kind,[]]))
 resources.regions=[row(1,'华东')]; resources.zones=[row(11,'华东一区',{region_id:1}),row(12,'华东二区',{region_id:1})]
 resources.flavor_groups=[row(2,'通用硬件')]
 resources.flavors=[row(31,'共享规格',{zone_id:11,zone_ids:[11,12],group_id:2,cpu:2,memory_mib:2048,architecture:'x86_64',prices:{month:2000},zone_prices:{12:{month:4500}}})]
 resources.disk_types=[row(51,'SSD',{zone_id:11,zone_ids:[11,12],min_gib:20,max_gib:200,step_gib:10,max_count:2,prices:{month:100},zone_prices:{12:{month:200}}})]
 const draft={schema_version:'2.0.0',billing_mode:'prepaid',resource_ids:Object.fromEntries(kinds.map(kind=>[kind,resources[kind].map(r=>r.id)])),cycles:['month'],quantity_limit:10,setup_minor:0,price_overrides:{},network:{},provider_connection_id:0,delivery_rule_id:0,network_policy_id:0,zone_durations:[],storage_policy:{default_type_id:51,default_size_gib:20,max_data_disks:2},trial_policy:{enabled:false},refund_policy:{enabled:false},renewal_policy:{pricing:'current',auto_renew_enabled:false}}
 const configuration={id:3,product_id:1,name:'云服务器',code:'cloud-server',description:'',revision:1,status:1,published_version_id:7,draft}
 const permissions=['admin.cloud-configuration.specification.view','admin.cloud-configuration.storage.view','admin.compute-resources.view','admin.compute-resources.update','admin.cloud-products.view','admin.cloud-products.update']
 const writes=[]
 await page.route('**/api/admin/v1/**',async route=>{
  const path=new URL(route.request().url()).pathname.replace('/api/admin/v1','')
  let data=null
  if(path==='/auth/me')data={user:{id:1,username:'fixture',nickname:'测试管理员',is_founder:false},permissions}
  else if(path.startsWith('/cloud-servers/sections/'))data={configuration,choices:[configuration],default_configuration_id:3,references:{resources,connections:[],delivery_rules:[]},inventory:[]}
  else if(path.startsWith('/compute-resources/')&&route.request().method()==='PUT'){
   const kind=path.split('/')[2],body=route.request().postDataJSON(); writes.push({kind,body});data={...resources[kind][0],...body};resources[kind]=[data]
  }
  await route.fulfill({json:{code:0,message:'ok',data,request_id:'zone-price-admin'}})
 })
 for(const [section,kind,zonePrice] of [['specification','flavors','45.00'],['storage','disk_types','2.00']]){
  await page.goto(`http://127.0.0.1:5175/admin/products/cloud-servers/${section}?resource=${kind}&zone=12`)
  await page.getByText('编辑',{exact:true}).click()
  await page.getByText('按可用区定价',{exact:true}).waitFor()
  const input=label=>page.getByLabel(label,{exact:true}).locator('xpath=self::input | .//input').first()
  assert.equal(await input('华东二区月单价').inputValue(),zonePrice)
  assert.equal(await input('华东一区月单价').inputValue(),'')
  await input('华东一区月单价').fill('0')
  await input('华东一区月单价').blur()
  await input('华东二区月单价').fill(kind==='flavors'?'55':'3')
  await input('华东二区月单价').blur()
  await page.getByRole('button',{name:'确定',exact:true}).click()
  await page.getByText('按可用区定价',{exact:true}).waitFor({state:'hidden'})
  const last=writes.at(-1);assert.equal(last.kind,kind);assert.equal(last.body.zone_prices['11'].month,0);assert.equal(last.body.zone_prices['12'].month,kind==='flavors'?5500:300)
  await page.getByText('编辑',{exact:true}).click()
  assert.equal(await input('华东一区月单价').inputValue(),'0.00')
  await input('华东二区月单价').fill('')
  await input('华东二区月单价').blur()
  await page.getByRole('button',{name:'确定',exact:true}).click()
  await page.getByText('按可用区定价',{exact:true}).waitFor({state:'hidden'})
  assert.equal(writes.at(-1).body.zone_prices['12'],undefined)
  await page.getByText('编辑',{exact:true}).click()
  for(const width of [1440,390]){
   await page.setViewportSize({width,height:1000})
   await page.waitForTimeout(400)
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true)
   await page.screenshot({path:`/tmp/zone-admin-${kind}-${width}.png`,fullPage:true,animations:'disabled'})
  }
  await page.keyboard.press('Escape')
  await page.setViewportSize({width:1440,height:1000})
 }
 assert.deepEqual(errors,[])
 console.log('后台：规格/存储独立价回填、零价、元转分、清空继承及桌面/390px抽屉通过（模拟接口）')
} catch(error){if(page)console.error((await page.locator('body').innerText()).slice(-2000));throw error}finally{await browser.close()}
