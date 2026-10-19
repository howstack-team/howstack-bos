import type { IRoute } from '../routes.ts'

/** 后端发布的真实权限目录；菜单分组本身不生成权限码。 */
export type PermissionCatalog = Record<string, { label: string; group: string }>
/** 页面中的一个可授权操作。 */
export interface PermissionOption { code: string; action: string; label: string }
/** 权限表的页面节点，授权值始终使用后端 viewCode。 */
export interface PermissionPage { kind: 'page'; key: string; title: string; permissions: PermissionOption[]; viewCode: string; dependencies: string[] }
/** 与导航菜单一致的分组节点，可包含下级分组。 */
export interface PermissionGroup { kind: 'module'; key: string; title: string; children: PermissionRow[] }
/** 权限表中用于展示层级的行。 */
export type PermissionRow = PermissionPage | PermissionGroup

const actionOrder = ['create', 'update', 'reply', 'close', 'publish', 'preview', 'test', 'sync', 'map', 'delete']
const actionLabels: Record<string, string> = { reply: '回复', close: '关闭', resolve: '确认未开通', provision: '重试开通', create: '新增', update: '修改', publish: '发布', preview: '预览', test: '测试连接', sync: '获取供应商资源', map: '设置资源对应', delete: '删除' }

/** 按路由递归保留菜单层级，仅展示在后端目录中存在的页面权限，移除空分组。 */
export function buildPermissionTree(items: readonly IRoute[], catalog: PermissionCatalog): PermissionRow[] {
  return items.flatMap((page): PermissionRow[] => {
    if (page.children?.length) {
      const children = buildPermissionTree(page.children, catalog)
      return children.length ? [{ kind: 'module', key: `module:${page.key}`, title: page.name, children }] : []
    }
    // 一个页面可分别授权销售配置和资源配置；保持独立行标识与真实权限码。
    const views = page.permission ? [page.permission] : [...new Set((page.requiredPermissions || [])
      .flatMap(({ resource }) => typeof resource === 'string' ? [resource] : []))]
    return views.flatMap((pageView): PermissionPage[] => {
      if (!pageView.endsWith('.view')) return []
      const prefix = pageView.slice(0, -'.view'.length)
      const options = Object.entries(catalog)
        .filter(([code]) => code.startsWith(`${prefix}.`))
        .map(([code, item]) => {
          const action = code.slice(prefix.length + 1)
          return { code, action, label: actionLabels[action] ?? item.label }
        })
      if (['admin.license.view', 'admin.app-center.view'].includes(pageView) && catalog['admin.platform.connect']) {
        options.push({ code: 'admin.platform.connect', action: 'connect', label: '连接皓量云擎账号' })
      }
      for (const code of page.operationPermissions ?? []) {
        if (catalog[code] && !options.some(option => option.code === code)) options.push({ code, action: code.split('.').at(-1) ?? code, label: catalog[code].label })
      }
      const viewCode = options.find(({ action }) => action === 'view')?.code
      if (!viewCode) return []
      const permissions = options.filter(({ action }) => action !== 'view').sort((a, b) => {
        const left = actionOrder.indexOf(a.action), right = actionOrder.indexOf(b.action)
        return (left < 0 ? actionOrder.length : left) - (right < 0 ? actionOrder.length : right) || a.code.localeCompare(b.code)
      })
      return [{ kind: 'page', key: views.length > 1 ? `${page.key}:${pageView}` : page.key,
        title: views.length > 1 ? catalog[pageView].group : page.name, permissions, viewCode, dependencies: page.permissionDependencies ?? [] }]
    })
  })
}

/** 获取所有后代页面，用于分组勾选联动与可授予权限判断。 */
export function permissionPages(rows: readonly PermissionRow[]): PermissionPage[] {
  return rows.flatMap(row => row.kind === 'module' ? permissionPages(row.children) : [row])
}

/** 获取全部层级的分组键，用于展开和折叠。 */
export function permissionGroupKeys(rows: readonly PermissionRow[]): string[] {
  return rows.flatMap(row => row.kind === 'module' ? [row.key, ...permissionGroupKeys(row.children)] : [])
}
