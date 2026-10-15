import assert from 'node:assert/strict'
import test from 'node:test'
import { buildPermissionTree, permissionGroupKeys, permissionPages, type PermissionCatalog } from '../src/access/permissionTree.ts'

const menu = [{ name: '系统管理', key: 'system', children: [
  { name: '组织与权限', key: 'system/organization', children: [
    { name: '系统用户', key: 'system/administrators', permission: 'admin.administrators.view' },
    { name: '部门管理', key: 'system/departments', permission: 'admin.departments.view' },
    { name: '角色权限', key: 'system/roles', permission: 'admin.roles.view' },
  ] },
] }]
const catalog: PermissionCatalog = Object.fromEntries(['administrators', 'departments', 'roles'].flatMap(resource =>
  ['view', 'create', 'update', 'delete'].map(action => [`admin.${resource}.${action}`, { label: action, group: '权限管理' }])))

test('头像菜单中的 API 文档入口仍可在角色权限中授予', () => {
  const rows = buildPermissionTree([{ name: 'API 文档', key: 'system/api-docs', ignore: true, external: true, permission: 'admin.api-docs.view' }],
    { 'admin.api-docs.view': { label: '查看 API 文档', group: '开发工具' } })
  assert.deepEqual(permissionPages(rows).map(page => page.viewCode), ['admin.api-docs.view'])
})

test('组织分组保留完整层级，分组不虚构后端权限码', () => {
  const rows = buildPermissionTree(menu, catalog)
  assert.deepEqual(permissionGroupKeys(rows), ['module:system', 'module:system/organization'])
  const root = rows[0]
  assert.equal(root.kind, 'module')
  if (root.kind !== 'module') return
  assert.equal(root.children[0].title, '组织与权限')
  assert.deepEqual(permissionPages(root.children).map(page => page.viewCode), [
    'admin.administrators.view', 'admin.departments.view', 'admin.roles.view',
  ])
  assert.ok(permissionPages(rows).flatMap(page => [page.viewCode, ...page.permissions.map(item => item.code)]).every(code => code in catalog))
})

test('后端未开放的页面及空分组不显示，仍可分配已开放页面的操作', () => {
  assert.deepEqual(buildPermissionTree(menu, {}), [])
  const rows = buildPermissionTree(menu, { 'admin.roles.view': catalog['admin.roles.view'], 'admin.roles.update': catalog['admin.roles.update'] })
  assert.deepEqual(permissionPages(rows).map(page => page.title), ['角色权限'])
  assert.deepEqual(permissionPages(rows)[0].permissions.map(item => item.code), ['admin.roles.update'])
})

test('同一页面的多个授权范围保持独立标识与权限值', () => {
  const rows = buildPermissionTree([{ name: '云服务器', key: 'products/cloud-servers', requiredPermissions: [
    { resource: 'admin.cloud-products.view', actions: ['read'] },
    { resource: 'admin.compute-resources.view', actions: ['read'] },
  ] }], {
    'admin.cloud-products.view': { label: '查看', group: '购买配置' },
    'admin.compute-resources.view': { label: '查看', group: '配置资源' },
  })
  assert.equal(new Set(rows.map(row => row.key)).size, 2)
  assert.deepEqual(permissionPages(rows).map(page => page.title), ['购买配置', '配置资源'])
})


test('云服务器三级菜单可以独立授予查看权限，并保留业务操作及读取依赖', () => {
  const rows = buildPermissionTree([{name:'产品管理',key:'products',children:[{name:'云服务器',key:'products/cloud-servers',children:[
    {name:'部署与规格',key:'products/cloud-servers/deployment',permission:'admin.cloud-configuration.deployment.view',permissionDependencies:['admin.compute-resources.view'],operationPermissions:['admin.compute-resources.update']},
    {name:'购买与计费',key:'products/cloud-servers/purchase',permission:'admin.cloud-configuration.purchase.view',permissionDependencies:['admin.cloud-products.view'],operationPermissions:['admin.cloud-products.publish']},
  ]}]}],Object.fromEntries(['admin.cloud-configuration.deployment.view','admin.cloud-configuration.purchase.view','admin.compute-resources.view','admin.compute-resources.update','admin.cloud-products.view','admin.cloud-products.publish'].map(code=>[code,{label:code,group:'云服务器'}])))
  assert.deepEqual(permissionGroupKeys(rows), ['module:products','module:products/cloud-servers'])
  const pages = permissionPages(rows)
  assert.deepEqual(pages.map(page=>page.viewCode),['admin.cloud-configuration.deployment.view','admin.cloud-configuration.purchase.view'])
  assert.deepEqual(pages[0].dependencies,['admin.compute-resources.view'])
  assert.deepEqual(pages[1].permissions.map(option=>option.code),['admin.cloud-products.publish'])
})
