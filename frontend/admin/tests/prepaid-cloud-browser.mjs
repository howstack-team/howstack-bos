import assert from 'node:assert/strict'
import { mkdir, readFile } from 'node:fs/promises'
const { chromium } = await import(process.env.HOWSTACK_PLAYWRIGHT_MODULE ?? 'playwright')
const base = process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:5175/admin'
const output = '/tmp/howstack-cloud-workspace'; await mkdir(output, { recursive: true })
const routes = await readFile(new URL('../src/routes.ts', import.meta.url), 'utf8')
const permissions = ['admin.compute-resources.create', 'admin.compute-resources.update', 'admin.cloud-products.create', 'admin.cloud-products.update', 'admin.cloud-products.preview', 'admin.cloud-products.publish', ...new Set([...routes.matchAll(/['"](admin\.[\w.-]+)['"]/g)].map(m => m[1]))]
const bandwidth = { min: 0, max: 100, step: 1, default: 5, included: 5, price_minor: 100, pricing_mode: 'flat', tiers: [] }
const network = { billing_mode: 'fixed', egress: bandwidth, ingress: { ...bandwidth, default: 100, included: 100 }, ip: { mode: 'options', min: 1, max: 4, step: 1, default: 1, included: 1, price_minor: 200, options: [1, 2, 4] } }
const common = { id: 1, code: 'test', name: '测试资源', sort: 0, status: 1, description: '' }
const resources = { regions: [{ ...common, name: '上海' }], zones: [{ ...common, region_id: 1, name: '上海一区' }, { ...common, id: 2, code: 'zone-two', region_id: 1, name: '上海二区' }], lines: [{ ...common, zone_id: 1, zone_ids: [1, 2], network_configuration: network, name: 'BGP线路' }], flavor_groups: [{ ...common, name: 'AMD系列', cpu_model: 'EPYC' }], flavors: [{ ...common, name: '2核2G', zone_ids: [1, 2], group_id: 1, cpu: 2, memory_mib: 2048, prices: { month: 5000 } }], image_types: [{ ...common, name: 'Ubuntu', icon_url: '/logo.png' }], images: [{ ...common, zone_ids: [1, 2], type_id: 1, min_cpu: 1, min_memory_mib: 128, min_disk_gib: 20, default_username: 'root', default_port: 22, login_methods: ['password'], name: 'Ubuntu 24.04' }], disk_types: [{ ...common, zone_ids: [1, 2], min_gib: 20, max_gib: 1024, step_gib: 10, max_count: 2, prices: { month: 100 }, name: 'SSD' }], protections: [], network_policies: [] }
const draft = {schema_version:'2.0.0',billing_mode:'prepaid',resource_ids:Object.fromEntries(Object.entries(resources).map(([kind,rows])=>[kind,rows.map(row=>row.id)])),cycles:['month'],quantity_limit:10,setup_minor:0,system_disk_included_gib:40,provider_connection_id:0,delivery_rule_id:0,network_policy_id:0,price_overrides:{},network:{},trial_policy:{enabled:false},refund_policy:{enabled:false},renewal_policy:{},durations:[{months:1,label:'月付',discount_kind:'rate',rate_bps:10000},{months:12,label:'年付',discount_kind:'billed_months',billed_months:10}],storage_policy:{default_type_id:1,default_size_gib:40,max_data_disks:1},trial_scope:{},zone_provider_bindings:[]}
const provider={id:1,name:'测试供应商',status:1,adapter_available:true,adapter_accepting:true,requires_retest:false,capabilities:{product_types:['cloud_server']}}
const baseOffer={id:1,name:'云服务器',code:'cloud',description:'',status:0,revision:1,published_version_id:null,draft,created_at:'2026-10-08 10:00:00'}
const browser=await chromium.launch({headless:true}),errors=[],saves=[],resourceSaves=[];let stockSaves=0,publishes=0
async function assertEventually(check) { for(let i=0;i<40;i++){try{return await check()}catch(error){if(i===39)throw error;await new Promise(resolve=>setTimeout(resolve,100))}} }
async function setup({readonly=false,empty=false,multiple=false,sections,layout,setupMinor=0}={}) {
 const context=await browser.newContext(),page=await context.newPage();page.on('pageerror',error=>errors.push(error.message))
 if(layout)await context.addInitScript(value=>localStorage.setItem('admin-layout-style',value),layout)
 const granted=permissions.filter(code=>(!readonly||code.endsWith('.view')||code.endsWith('.preview'))&&(!sections||!code.startsWith('admin.cloud-configuration.')||sections.some(section=>code===`admin.cloud-configuration.${section}.view`)))
 const sectionRequests=[]
 let current=empty?null:structuredClone({...baseOffer,draft:{...draft,setup_minor:setupMinor}}),defaultId=multiple?null:current?.id??null,pools=[],failNextSave=false
 const choices=()=>multiple?[current??baseOffer,{...baseOffer,id:2,name:'历史配置二'}]:current?[current]:[]
 await page.route('**/api/admin/v1/**',async route=>{
  const path=new URL(route.request().url()).pathname.replace('/api/admin/v1',''),method=route.request().method();let data=null
  if(path==='/auth/me')data={user:{id:1,username:'mock',nickname:'模拟管理员',is_founder:false},permissions:granted}
  if(path==='/compute-resources')data=resources
  if(/^\/compute-resources\/[a-z_]+(?:\/\d+)?$/.test(path)&&['PUT','POST'].includes(method)){
   const [,kind,id]=path.split('/').slice(1),body=route.request().postDataJSON();resourceSaves.push({kind,id,body})
   data={...(resources[kind].find(row=>row.id===Number(id))??common),...body,id:id?Number(id):Math.max(0,...resources[kind].map(row=>row.id))+1}
   if(kind==='regions'&&body.country_code!==undefined)data.country_code=body.country_code.trim().toUpperCase()
   if(kind==='lines'&&body.test_ip!==undefined)data.test_ip=body.test_ip.trim()
   if(id)resources[kind]=resources[kind].map(row=>row.id===data.id?data:row);else resources[kind].push(data)
  }
  if(path.startsWith('/cloud-servers/sections/'))sectionRequests.push(path)
  if(path.startsWith('/cloud-servers/sections/'))data={configuration:multiple&&!defaultId?null:current,choices:choices(),default_configuration_id:defaultId,references:{resources,connections:[provider],delivery_rules:[]},inventory:pools}
  if(path==='/cloud-products/references')data={resources,connections:[provider],delivery_rules:[]}
  if(path==='/cloud-servers/configuration'||path==='/cloud-servers/configuration/live'){
   if(method==='PUT'&&failNextSave){failNextSave=false;await route.fulfill({status:409,json:{code:40900,message:'配置已被其他管理员修改',data:null,request_id:'workspace-conflict'}});return}
   if(method==='PUT'){const body=route.request().postDataJSON();saves.push(body);assert.equal(body.expected_configuration_id,defaultId);current={...baseOffer,...body,id:body.configuration_id??1,revision:body.revision+1,status:1,published_version_id:1,configuration_ready:true,has_unpublished_changes:false};defaultId=current.id}
   data=method==='GET'?{configuration:multiple&&!defaultId?null:current,choices:choices(),default_configuration_id:defaultId}:current
  }
  if(/^\/cloud-products\/\d+$/.test(path))data={...baseOffer,id:Number(path.split('/').at(-1)),name:'历史配置二'}
  if(path==='/cloud-products/1/publish'){publishes++;assert.equal(route.request().postDataJSON().enable,true);current={...current,status:1,published_version_id:1,has_unpublished_changes:false};data=current}
  if(path==='/cloud-products/1/versions')data=[]
  if(path==='/cloud-servers/inventory'){
   if(method==='PUT'){stockSaves++;const body=route.request().postDataJSON();assert.equal(body.zone_id,1);pools=[{id:1,...body,revision:body.revision+1,occupied:0,reserved:0,available:body.capacity}]}
   data=method==='GET'?pools:pools[0]
  }
  if(path==='/cloud-products/1/preview'){
   const body=route.request().postDataJSON()
   if(body.mode==='options'){
    const policy=current?.draft??draft,zoneId=body.selection.zone_id
    const periods=zoneId?(policy.zone_durations?policy.zone_durations.find(row=>row.zone_id===zoneId)?.durations??[]:policy.durations):[]
    const configuration={...body.selection},invalid_fields={}
    if(configuration.duration_months!==undefined&&!periods.some(row=>row.months===configuration.duration_months)){delete configuration.duration_months;invalid_fields.duration_months='周期不属于当前可用区'}
    data={schema_version:'2.0.0',configuration,options:resources,invalid_fields,cycles:['month'],quantity_limit:10,system_disk_included_gib:40,durations:periods,storage_policy:draft.storage_policy,line_network:body.selection.line_id?network:null}
   }
   else if(body.mode==='price'){assert.equal(body.selection.network.egress_mbps,5);data={amount_minor:50300,unit_amount_minor:50300,quantity:1,currency:'CNY',unit_breakdown:[{key:'compute',label:'计算规格',quantity:1,unit_price_minor:5000,amount_minor:50000}]}}
  }
  await route.fulfill({json:{code:0,message:'ok',data,request_id:'workspace-browser'}})
 })
 return {context,page,sectionRequests,failNextConfigurationSave:()=>{failNextSave=true}}
}
async function selectTab(page, name) {
 if (page.viewportSize().width <= 768) await page.getByRole('button', {name:'打开导航菜单',exact:true}).click()
 await page.getByRole('menuitem', {name,exact:true}).click()
 const section = {'地域和可用区':'deployment','规格与套餐':'specification','线路和网络':'network','镜像和存储':'storage','购买与计费':'purchase'}[name]
 await assertEventually(() => assert.equal(new URL(page.url()).pathname, `/admin/products/cloud-servers/${section}`))
 await page.locator('.cloud-workspace > .cloud-workspace-panel').waitFor()
 if (page.viewportSize().width <= 768) { await page.keyboard.press('Escape'); await page.getByRole('button',{name:'打开导航菜单',exact:true}).waitFor() }
}

try {
 const {page}=await setup()
 for(const width of [1440,390]){
  await page.setViewportSize({width,height:900});await page.goto(`${base}/products/cloud-servers`)
  await page.getByRole('complementary',{name:'地域与可用区导航'}).waitFor();assert.equal(await page.getByRole('tab').count(),0)
  assert.equal(await page.getByText('旧版网络',{exact:true}).count(),0);assert.equal(await page.getByText('销售方案',{exact:true}).count(),0)
  const zoneRow=page.getByRole('row').filter({hasText:'上海一区'})
  await zoneRow.getByText('配置提供者',{exact:true}).click()
  const providerDrawer=page.locator('.arco-drawer:visible')
  await providerDrawer.locator('.cloud-provider-form .arco-select').click();await page.locator('.arco-select-option').filter({hasText:/^测试供应商$/}).click()
  await providerDrawer.getByText('高级参数',{exact:true}).click()
  const advanced=page.locator('.arco-modal:visible')
  await advanced.waitFor();assert.equal(await page.locator('.arco-drawer:visible').count(),1)
  await advanced.getByRole('button',{name:'取消',exact:true}).click()
  await providerDrawer.getByRole('button',{name:'完成',exact:true}).click()
  await providerDrawer.waitFor({state:'hidden'})
  await selectTab(page,'购买与计费');await page.getByPlaceholder('请输入数量上限').fill('7')
  await selectTab(page,'地域和可用区');await selectTab(page,'购买与计费')
  assert.equal(await page.getByPlaceholder('请输入数量上限').inputValue(),'7')
  await page.getByRole('button',{name:'保存配置',exact:true}).click();await page.getByText('配置已保存并生效',{exact:true}).waitFor()
  assert.equal(saves.at(-1).draft.quantity_limit,7);assert.equal(saves.at(-1).draft.zone_provider_bindings[0].connection_id,1)
  assert.equal(saves.at(-1).draft.setup_minor,0)
  assert.equal(await page.getByRole('spinbutton',{name:'每台一次性费用'}).count(),0)
  assert.equal(await page.getByText('不参与周期优惠。',{exact:true}).count(),0)
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.screenshot({path:`${output}/purchase-${width}.png`,fullPage:true})
  await selectTab(page,'地域和可用区');await page.screenshot({path:`${output}/deployment-${width}.png`,fullPage:true})
  await selectTab(page,'规格与套餐');await page.getByText('2核2G',{exact:true}).waitFor();await page.screenshot({path:`${output}/specification-${width}.png`,fullPage:true})
  await page.getByText('库存',{exact:true}).click();const stock=page.locator('.arco-drawer').filter({hasText:'库存 ·'})
  await stock.getByPlaceholder('请输入库存总配额').fill('3');await stock.getByRole('button',{name:'确定',exact:true}).click();await page.getByText('库存已保存',{exact:true}).waitFor();await stock.getByRole('button',{name:'取消',exact:true}).click();await page.getByText('3 台',{exact:true}).waitFor()
  await selectTab(page,'镜像和存储');await page.getByText('Ubuntu 24.04',{exact:true}).waitFor()
  await page.screenshot({path:`${output}/storage-${width}.png`,fullPage:true})
  await page.locator('.cloud-context-categories').getByText('存储类型',{exact:true}).click()
  await page.getByText('系统盘与数据盘规则',{exact:true}).waitFor()
  await assertEventually(async()=>assert.ok(await page.getByRole('radio',{name:'存储类型',exact:true}).isChecked()))
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth))
  await page.screenshot({path:`${output}/storage-rules-${width}.png`,fullPage:true})
  await selectTab(page,'线路和网络');await page.screenshot({path:`${output}/network-${width}.png`,fullPage:true})
  await page.getByText('编辑',{exact:true}).click();await page.getByText('上行带宽',{exact:true}).waitFor();await page.getByText('下行带宽',{exact:true}).waitFor()
  assert.equal(new URL(page.url()).pathname,'/admin/products/cloud-servers/network');assert.equal(await page.locator('.arco-drawer:visible').count(),1);assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.screenshot({path:`${output}/line-${width}.png`,fullPage:true})
  await page.locator('.arco-drawer:visible').getByRole('button',{name:'取消',exact:true}).click();await selectTab(page,'购买与计费');await page.getByRole('button',{name:'购买预览',exact:true}).click()
  const preview=page.locator('.arco-drawer').filter({hasText:'购买预览 · 当前配置'})
  for(const [label,value] of [['地域','上海'],['可用区','上海一区'],['线路','BGP线路'],['规格组','AMD系列'],['规格','2核2G'],['镜像类型','Ubuntu'],['镜像','Ubuntu 24.04']]){
   await preview.locator('.arco-select').filter({has:page.getByPlaceholder(`请选择${label}`,{exact:true})}).click();await page.locator('.arco-select-option').filter({hasText:new RegExp(`^${value}$`)}).click()
  }
  await preview.getByText('默认用户 root · 登录端口 22',{exact:true}).waitFor();await preview.getByRole('button',{name:'计算费用',exact:true}).click();await preview.getByText('每台费用',{exact:true}).waitFor()
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.screenshot({path:`${output}/preview-${width}.png`})
  await preview.locator('.arco-drawer-close-icon').click()
 }
 assert.equal(await page.getByRole('button',{name:'发布配置',exact:true}).count(),0);assert.equal(publishes,0)
 // 管理抽屉内的编辑必须保持 Modal，取消保留父抽屉，保存只关闭子编辑。
 const nested=await setup();await nested.page.goto(`${base}/products/cloud-servers?tab=deployment&manage=regions`)
 const management=nested.page.locator('.arco-drawer:visible')
 await management.getByText('上海',{exact:true}).waitFor()
 assert.equal(new URL(nested.page.url()).pathname,'/admin/products/cloud-servers/deployment');assert.equal(new URL(nested.page.url()).searchParams.has('tab'),false)
 await management.getByText('编辑',{exact:true}).click()
 const modal=nested.page.locator('.arco-modal:visible')
 await modal.getByPlaceholder('请输入名称',{exact:true}).waitFor()
 assert.equal(await nested.page.locator('.arco-drawer:visible').count(),1)
 await modal.getByRole('button',{name:'取消',exact:true}).click();await modal.waitFor({state:'hidden'})
 await management.getByText('编辑',{exact:true}).click()
 await modal.getByPlaceholder('请输入名称',{exact:true}).fill('华东地域')
 await modal.getByRole('button',{name:'确定',exact:true}).click();await modal.waitFor({state:'hidden'})
 await management.getByText('华东地域',{exact:true}).waitFor();assert.equal(await nested.page.locator('.arco-drawer:visible').count(),1)
 assert.equal(new URL(nested.page.url()).searchParams.get('manage'),'regions');assert.equal(new URL(nested.page.url()).searchParams.has('edit'),false)
 await management.locator('.arco-drawer-close-icon').click();await management.waitFor({state:'hidden'})
 await nested.context.close()
 // 旧全局周期展开为独立可用区配置，修改第二区不能影响第一区。
 for(const width of [1440,390]){
  const scoped=await setup(),scopedPage=scoped.page
  await scopedPage.setViewportSize({width,height:900});await scopedPage.goto(`${base}/products/cloud-servers?tab=purchase&zone=1`)
  const names=scopedPage.getByRole('textbox',{name:'周期显示名称'})
  await names.first().waitFor();assert.equal(await names.count(),2)
  await names.first().fill('一区月付')
  const zoneSelect=scopedPage.locator('.cloud-period-context .arco-cascader')
  await zoneSelect.click();await scopedPage.locator('.arco-cascader-list-item').getByText(resources.regions[0].name,{exact:true}).click();await scopedPage.locator('.arco-cascader-list-item').getByText('上海二区',{exact:true}).click()
  assert.equal(new URL(scopedPage.url()).searchParams.get('zone'),'2')
  assert.equal(await names.first().inputValue(),'月付');await names.first().fill('二区月付')
  await scopedPage.getByRole('spinbutton',{name:'折扣比例'}).fill('80')
  await selectTab(scopedPage,'地域和可用区');await selectTab(scopedPage,'购买与计费')
  assert.equal(await names.first().inputValue(),'二区月付')
  await zoneSelect.click();await scopedPage.locator('.arco-cascader-list-item').getByText(resources.regions[0].name,{exact:true}).click();await scopedPage.locator('.arco-cascader-list-item').getByText('上海一区',{exact:true}).click()
  assert.equal(await names.first().inputValue(),'一区月付');assert.equal(await scopedPage.getByRole('spinbutton',{name:'折扣比例'}).inputValue(),'100.00')
  await scopedPage.getByRole('button',{name:'保存配置',exact:true}).click();await scopedPage.getByText('配置已保存并生效',{exact:true}).waitFor()
  const saved=saves.at(-1).draft;assert.equal(saved.durations,undefined);assert.equal(saved.zone_durations.length,2)
  assert.equal(saved.zone_durations[0].durations[0].label,'一区月付');assert.equal(saved.zone_durations[0].durations[0].rate_bps,10000)
  assert.equal(saved.zone_durations[1].durations[0].label,'二区月付');assert.equal(saved.zone_durations[1].durations[0].rate_bps,8000)
  await scopedPage.reload();await names.first().waitFor();assert.equal(await names.first().inputValue(),'一区月付')
  assert.ok(await scopedPage.evaluate(()=>document.documentElement.scrollWidth<=innerWidth))
  await scopedPage.screenshot({path:`${output}/zone-periods-${width}.png`,fullPage:true})
  await scopedPage.getByRole('button',{name:'购买预览',exact:true}).click()
  const preview=scopedPage.locator('.arco-drawer:visible')
  assert.ok(await preview.getByPlaceholder('请先选择可用区',{exact:true}).isDisabled())
  for(const [label,value] of [['地域',resources.regions[0].name],['可用区','上海一区']]){
   await preview.locator('.arco-select').filter({has:scopedPage.getByPlaceholder(`请选择${label}`,{exact:true})}).click();await scopedPage.locator('.arco-select-option').filter({hasText:new RegExp(`^${value}$`)}).click()
  }
  const periodSelect=preview.locator('.arco-form-item').filter({has:scopedPage.getByText('付费周期',{exact:true})}).locator('.arco-select')
  await periodSelect.click();await scopedPage.locator('.arco-select-option').filter({hasText:/年付/}).click()
  await preview.locator('.arco-form-item').filter({has:scopedPage.getByText('可用区',{exact:true})}).locator('.arco-select').click();await scopedPage.locator('.arco-select-option').filter({hasText:/^上海二区$/}).click()
  await assertEventually(async()=>assert.equal(await periodSelect.innerText(),'二区月付（1个月）'))
  await scoped.context.close()
 }
 const read=await setup({readonly:true});await read.page.goto(`${base}/products/cloud-servers?tab=purchase`);await read.page.getByText('购买限制',{exact:true}).waitFor();assert.equal(await read.page.getByRole('button',{name:'保存配置',exact:true}).count(),0);assert.ok(await read.page.getByRole('button',{name:'增加周期',exact:true}).isDisabled())
 // 非零历史费用不能被隐藏收费或默默清零，只能主动取消后保存。
 for(const width of [1440,390]){
  const historical=await setup({setupMinor:350}),p=historical.page
  await p.setViewportSize({width,height:900});const before=saves.length
  await p.goto(`${base}/products/cloud-servers/purchase`)
  await p.getByText('当前历史配置仍包含每台 ¥3.50 的一次性费用，继续保存将保留此费用。',{exact:true}).waitFor()
  assert.equal(saves.length,before)
  await p.getByRole('button',{name:'保存配置',exact:true}).click();await p.getByText('配置已保存并生效',{exact:true}).waitFor();assert.equal(saves.at(-1).draft.setup_minor,350)
  await p.getByText('取消一次性费用',{exact:true}).click()
  assert.equal(await p.getByText('取消一次性费用',{exact:true}).count(),0)
  await p.getByRole('button',{name:'保存配置',exact:true}).click();await assertEventually(()=>assert.equal(saves.at(-1).draft.setup_minor,0))
  assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth))
  await historical.context.close()
 }
 const historicalReadonly=await setup({readonly:true,setupMinor:350})
 await historicalReadonly.page.goto(`${base}/products/cloud-servers/purchase`)
 await historicalReadonly.page.getByText('当前历史配置仍包含每台 ¥3.50 的一次性费用，继续保存将保留此费用。',{exact:true}).waitFor()
 assert.equal(await historicalReadonly.page.getByText('取消一次性费用',{exact:true}).count(),0)
 await historicalReadonly.context.close()
 const multi=await setup({multiple:true});await multi.page.goto(`${base}/products/cloud-servers`);await multi.page.getByPlaceholder('请选择需要使用的配置').waitFor();const before=saves.length
 await multi.page.locator('.arco-select').filter({has:multi.page.getByPlaceholder('请选择需要使用的配置')}).click();await multi.page.locator('.arco-select-option').filter({hasText:/^历史配置二$/}).click();await selectTab(multi.page,'购买与计费');await multi.page.getByPlaceholder('请输入数量上限').waitFor();assert.equal(saves.length,before)
 assert.equal(new URL(multi.page.url()).searchParams.get('configuration'),'2');await multi.page.getByRole('button',{name:'保存配置',exact:true}).click();await multi.page.getByText('配置已保存并生效',{exact:true}).waitFor();assert.equal(saves.at(-1).configuration_id,2)
 const empty=await setup({empty:true});const count=saves.length;await empty.page.goto(`${base}/products/cloud-servers/purchase`);await empty.page.getByText('尚未保存配置',{exact:true}).waitFor();assert.equal(saves.length,count)
 await empty.page.getByRole('button',{name:'保存配置',exact:true}).click();await empty.page.getByText('配置已保存并生效',{exact:true}).waitFor();assert.equal(saves.at(-1).configuration_id,null)
 // 页面访问权限控制菜单及直接地址，不允许未授权页面发起配置读取。
 const limited=await setup({readonly:true,sections:['purchase']})
 await limited.page.goto(`${base}/products/cloud-servers`);await limited.page.getByText('购买限制',{exact:true}).waitFor()
 assert.equal(new URL(limited.page.url()).pathname,'/admin/products/cloud-servers/purchase')
 assert.equal(await limited.page.getByRole('menuitem',{name:'地域和可用区',exact:true}).count(),0)
 await limited.page.goto(`${base}/products/cloud-servers/deployment`);await limited.page.locator('.arco-result-icon').waitFor()
 assert.equal(await limited.page.locator('.cloud-workspace').count(),0)
 assert.ok(limited.sectionRequests.every(path=>path.endsWith('/purchase')))
 await limited.context.close()
 // 全局入口只在购买与计费；资源页更改后才出现保存入口，失败保留输入。
 for(const width of [1440,390]){
  const actions=await setup(),p=actions.page
  await p.setViewportSize({width,height:900});const before=saves.length
  await p.goto(`${base}/products/cloud-servers/deployment`)
  for(const [section,label] of [['deployment','地域和可用区'],['specification','规格与套餐'],['network','线路和网络'],['storage','镜像和存储']]){
   if(section!=='deployment')await selectTab(p,label)
   await p.locator('.cloud-workspace-heading').getByText(label,{exact:true}).waitFor()
   assert.equal(await p.getByRole('button',{name:'购买预览',exact:true}).count(),0)
   assert.equal(await p.getByRole('button',{name:'保存配置',exact:true}).count(),0)
   assert.equal(await p.getByRole('button',{name:'配置辅助操作',exact:true}).count(),0)
   assert.equal(await p.getByRole('region',{name:'待保存的配置更改'}).count(),0)
   assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth))
   await p.screenshot({path:`${output}/resource-actions-${section}-${width}.png`,fullPage:true})
  }
  assert.equal(saves.length,before)
  await selectTab(p,'线路和网络');const participation=p.getByRole('row').filter({hasText:'BGP线路'}).locator('.arco-checkbox'),participating=participation.locator('input[type="checkbox"]')
  await participation.click()
  const savebar=p.getByRole('region',{name:'待保存的配置更改'})
  await savebar.waitFor();assert.equal(saves.length,before)
  actions.failNextConfigurationSave();await savebar.getByRole('button',{name:'保存更改',exact:true}).click()
  await p.getByText('配置已被其他管理员修改',{exact:true}).waitFor();assert.equal(await participating.isChecked(),false)
  await savebar.waitFor();assert.equal(saves.length,before)
  await p.screenshot({path:`${output}/pending-save-${width}.png`,fullPage:true})
  await savebar.getByRole('button',{name:'保存更改',exact:true}).click();await savebar.waitFor({state:'hidden'})
  assert.deepEqual(saves.at(-1).draft.resource_ids.lines,[])
  await participation.click();await savebar.getByRole('button',{name:'保存更改',exact:true}).click();await savebar.waitFor({state:'hidden'})
  await selectTab(p,'购买与计费');await p.getByRole('button',{name:'购买预览',exact:true}).waitFor()
  await p.getByRole('button',{name:'配置辅助操作',exact:true}).click();await p.getByRole('menuitem',{name:'配置记录',exact:true}).click()
  await p.locator('.arco-drawer:visible').getByText('配置记录',{exact:true}).waitFor()
  await p.locator('.arco-drawer:visible .arco-drawer-close-icon').click()
  await actions.context.close()
 }
 // 双列布局同样显示三级菜单，切换时保留草稿和可用区上下文。
 const double=await setup({layout:'double-column'})
 await double.page.goto(`${base}/products/cloud-servers/purchase?zone=2`);await double.page.getByPlaceholder('请输入数量上限').fill('9')
 await selectTab(double.page,'地域和可用区');await selectTab(double.page,'购买与计费')
 assert.equal(await double.page.getByPlaceholder('请输入数量上限').inputValue(),'9')
 assert.equal(new URL(double.page.url()).searchParams.get('zone'),'2')
 await double.page.screenshot({path:`${output}/double-column-1440.png`,fullPage:true});await double.context.close()
 // 关联可用区用地域 → 可用区多选，回填路径准确且只提交叶子 ID。
 for(const width of [1440,390]) {
  const associated=await setup(),ap=associated.page
  await ap.setViewportSize({width,height:900});await ap.goto(`${base}/products/cloud-servers/deployment?manage=regions`)
  const regionManagement=ap.locator('.arco-drawer:visible'),regionModal=ap.locator('.arco-modal:visible')
  await regionManagement.getByText('编辑',{exact:true}).click()
  const country=regionModal.getByPlaceholder('例如 CN、US（选填）')
  await country.fill('c');const beforeInvalid=resourceSaves.length
  await regionModal.getByRole('button',{name:'确定',exact:true}).click();await regionModal.getByText('请输入两位英文字母',{exact:true}).waitFor()
  assert.equal(resourceSaves.length,beforeInvalid)
  await country.fill('cn');await regionModal.getByRole('button',{name:'确定',exact:true}).click();await regionModal.waitFor({state:'hidden'})
  await regionManagement.getByText('CN',{exact:true}).waitFor()
  await regionManagement.getByText('编辑',{exact:true}).click();await country.waitFor();assert.equal(await country.inputValue(),'CN')
  assert.ok(await ap.evaluate(()=>document.documentElement.scrollWidth<=innerWidth))
  await ap.screenshot({animations:'disabled',path:`${output}/region-country-${width}.png`})
  await country.fill('');await regionModal.getByRole('button',{name:'确定',exact:true}).click();await regionModal.waitFor({state:'hidden'})
  assert.equal(resourceSaves.at(-1).body.country_code,'')
  await ap.setViewportSize({width,height:900});await ap.goto(`${base}/products/cloud-servers/network?edit=1`)
  const editor=ap.locator('.arco-drawer:visible'),cascader=editor.locator('.arco-cascader')
  await cascader.waitFor();assert.ok((await cascader.innerText()).includes(`${resources.regions[0].name} / 上海一区`))
  await editor.getByPlaceholder('例如 192.0.2.1、2001:db8::1（选填）').fill('2001:db8::1')
  await cascader.click()
  await ap.locator('.arco-cascader-list-item').getByText(resources.regions[0].name,{exact:true}).click()
  const second=ap.locator('.arco-cascader-list-item').filter({hasText:/^上海二区$/})
  assert.ok(await second.getByRole('checkbox').isChecked())
  await second.locator('.arco-checkbox').click();await assertEventually(async()=>assert.equal((await cascader.innerText()).includes('上海二区'),false))
  await cascader.scrollIntoViewIfNeeded()
  await ap.screenshot({animations:'disabled',path:`${output}/zone-cascader-${width}.png`})
  assert.ok(await ap.evaluate(()=>document.documentElement.scrollWidth<=innerWidth))
  await editor.getByRole('button',{name:'确定',exact:true}).click();await editor.waitFor({state:'hidden'})
  assert.deepEqual(resourceSaves.at(-1).body.zone_ids,[1])
  assert.equal(resourceSaves.at(-1).body.test_ip,'2001:db8::1')
  // 下次回填只保留已保存的区；选择地域复选框应展开为该地域的两个可用区 ID。
  await ap.getByText('2001:db8::1',{exact:true}).waitFor()
  await ap.getByText('编辑',{exact:true}).click();await cascader.waitFor()
  assert.equal(await editor.getByPlaceholder('例如 192.0.2.1、2001:db8::1（选填）').inputValue(),'2001:db8::1')
  await editor.getByPlaceholder('例如 192.0.2.1、2001:db8::1（选填）').fill('')
  await cascader.click()
  const parent=ap.locator('.arco-cascader-list-item').filter({hasText:new RegExp(`^${resources.regions[0].name}$`)})
  await parent.locator('.arco-checkbox').click()
  await editor.getByRole('button',{name:'确定',exact:true}).click();await editor.waitFor({state:'hidden'})
  assert.deepEqual(resourceSaves.at(-1).body.zone_ids.sort(),[1,2])
  assert.equal(resourceSaves.at(-1).body.test_ip,'')
  await associated.context.close()
 }
 // 地域切换必须限定可用区列表，新建默认归当前地域；刷新不得覆盖购买草稿。
 resources.regions.push({...common,id:2,code:'hangzhou',name:'杭州'})
 resources.zones.push({...common,id:3,code:'hangzhou-one',region_id:2,name:'杭州一区'})
 const regional=await setup(),rp=regional.page
 await rp.goto(`${base}/products/cloud-servers/deployment`)
 await rp.getByRole('complementary',{name:'地域与可用区导航'}).getByText('杭州',{exact:true}).click()
 await rp.getByRole('row').filter({hasText:'杭州一区'}).waitFor()
 assert.equal(await rp.getByRole('row').filter({hasText:'上海一区'}).count(),0)
 await rp.getByRole('button',{name:'新建可用区',exact:true}).click()
 const zoneEditor=rp.locator('.arco-drawer:visible')
 await zoneEditor.getByPlaceholder('请输入名称').waitFor()
 assert.equal(await zoneEditor.locator('.arco-form-item').filter({has:rp.getByText('地域',{exact:true})}).locator('.arco-select').innerText(),'杭州')
 await zoneEditor.getByRole('button',{name:'取消',exact:true}).click()
 await selectTab(rp,'购买与计费');await rp.getByPlaceholder('请输入数量上限').fill('8')
 await selectTab(rp,'地域和可用区');const reads=regional.sectionRequests.length
 await rp.getByRole('button',{name:'刷新',exact:true}).click()
 await assertEventually(()=>assert.ok(regional.sectionRequests.length>reads))
 await selectTab(rp,'购买与计费');assert.equal(await rp.getByPlaceholder('请输入数量上限').inputValue(),'8')
 await regional.context.close()
 assert.equal(stockSaves,2);assert.deepEqual(errors,[])
 console.log(JSON.stringify({passed:true,mocked:true,realWrites:0,widths:[1440,390],areas:5,crossPagePreservation:true,providerSave:true,stockSaves,publishes,explicitExistingSelection:true,readonlyControls:true,nestedModal:true,zonePeriodsIndependent:true,previewZoneReset:true,pageAccessDenied:true,doubleColumnNavigation:true,regionScoped:true,refreshPreservesDraft:true,zoneCascader:true,associationLeafIds:true,output}))
}catch(error){for(const context of browser.contexts())for(const page of context.pages()){await page.screenshot({path:`${output}/failure-${browser.contexts().indexOf(context)}.png`});console.error((await page.locator('body').innerText()).slice(-1800))}throw error}finally{await browser.close()}
