import { useMemo, useState } from 'react'
import { Button, Checkbox, Empty, Radio, Space, Table, Typography } from '@arco-design/web-react'
import type { TableColumnProps, TableRowSelectionProps } from '@arco-design/web-react'
import { IconExpand, IconShrink } from '@arco-design/web-react/icon'
import { routes, type IRoute } from '../routes.ts'

interface PermissionOption { code: string; action: string; label: string }
interface PageRow { kind: 'page'; key: string; title: string; permissions: PermissionOption[]; viewCode: string }
interface ModuleRow { kind: 'module'; key: string; title: string; children: PageRow[] }
type Row = PageRow | ModuleRow
interface Props {
  catalog: Record<string, { label: string; group: string }>
  value: string[]
  grantable: string[]
  isFounder: boolean
  onChange: (next: string[]) => void
}

const actionOrder = ['create', 'update', 'delete']
const actionLabels: Record<string, string> = { create: '新增', update: '修改', delete: '删除' }

function leafPages(items: IRoute[]): IRoute[] {
  return items.flatMap((item) => item.children?.length ? leafPages(item.children) : [item])
}

function modulesFrom(catalog: Props['catalog']): ModuleRow[] {
  const modules = new Map<string, ModuleRow>()
  for (const section of routes) for (const page of leafPages(section.children ?? [])) {
    if (!page.permission?.endsWith('.view')) continue
    const prefix = page.permission.slice(0, -'.view'.length)
    const permissionCodes = Object.entries(catalog)
      .filter(([code]) => code.startsWith(`${prefix}.`))
      .map(([code, item]) => {
        const action = code.slice(prefix.length + 1)
        return { code, action, label: actionLabels[action] ?? item.label }
      })
    const viewCode = permissionCodes.find(({ action }) => action === 'view')?.code
    const permissions = permissionCodes.filter(({ action }) => action !== 'view')
      .sort((a, b) => {
        const left = actionOrder.indexOf(a.action)
        const right = actionOrder.indexOf(b.action)
        return (left < 0 ? actionOrder.length : left) - (right < 0 ? actionOrder.length : right)
          || a.code.localeCompare(b.code)
      })
    if (!viewCode) continue
    const module = modules.get(section.key) ?? {
      kind: 'module', key: `module:${section.key}`, title: section.name, children: [],
    } satisfies ModuleRow
    module.children.push({
      // 页面共享权限码时仍需独立的表格行标识；授权值继续使用 viewCode。
      kind: 'page', key: page.key, title: page.name, permissions, viewCode,
    })
    modules.set(section.key, module)
  }
  return [...modules.values()]
}

/** 只展示已开放页面，保留其他历史授权。 */
export default function RolePermissionEditor({ catalog, value, grantable, isFounder, onChange }: Props) {
  const modules = useMemo(() => modulesFrom(catalog), [catalog])
  const selected = useMemo(() => new Set(value), [value])
  const allowed = useMemo(() => new Set(grantable), [grantable])
  const [linked, setLinked] = useState(true)
  const [collapsed, setCollapsed] = useState<Set<string>>(() => new Set())
  const expandedKeys = modules.filter((module) => !collapsed.has(module.key)).map((module) => module.key)
  const selectedMenuKeys = modules.flatMap((module) => module.children.filter((row) => selected.has(row.viewCode)).map((row) => row.key))
  const editableCodes = modules.flatMap((module) => module.children.flatMap((row) => [row.viewCode, ...row.permissions.map(({ code }) => code)]))
    .filter((code) => isFounder || allowed.has(code) || selected.has(code))

  function changeMenu(next: Set<string>, row: PageRow, checked: boolean) {
    if (!isFounder && !allowed.has(row.viewCode) && !selected.has(row.viewCode)) return
    if (checked) next.add(row.viewCode)
    else next.delete(row.viewCode)
    if (linked) row.permissions.forEach(({ code }) => {
      if (isFounder || allowed.has(code) || selected.has(code)) {
        if (checked) next.add(code)
        else next.delete(code)
      }
    })
  }

  function toggleMenu(row: PageRow | ModuleRow, checked: boolean) {
    const next = new Set(value)
    const pages = row.kind === 'module' ? row.children : [row]
    pages.forEach((page) => changeMenu(next, page, checked))
    onChange([...next])
  }

  function toggleAllMenus(checked: boolean) {
    const next = new Set(value)
    modules.forEach((module) => module.children.forEach((row) => changeMenu(next, row, checked)))
    onChange([...next])
  }

  function togglePermission(code: string, checked: boolean, row: PageRow) {
    const next = new Set(value)
    if (checked) {
      next.add(code)
      if (linked && (isFounder || allowed.has(row.viewCode) || selected.has(row.viewCode))) next.add(row.viewCode)
    } else {
      next.delete(code)
    }
    onChange([...next])
  }

  function toggleAll(checked: boolean) {
    const next = new Set(value)
    editableCodes.forEach((code) => checked ? next.add(code) : next.delete(code))
    onChange([...next])
  }

  if (!modules.length) return <Empty description="暂无可分配的权限" />
  const columns: TableColumnProps<Row>[] = [
    {
      title: '菜单', dataIndex: 'title', width: 220, ellipsis: true,
    },
    {
      title: '权限',
      render: (_, row) => row.kind === 'module' || row.permissions.length === 0 ? '-' : <div className="permission-options">
        {row.permissions.map(({ code, label }) => <Checkbox key={code} checked={selected.has(code)}
          disabled={!isFounder && (!allowed.has(code) && !selected.has(code)
            || linked && !selected.has(code) && !allowed.has(row.viewCode) && !selected.has(row.viewCode))}
          onChange={(checked) => togglePermission(code, checked, row)}>{label}</Checkbox>)}
      </div>,
    },
  ]
  const rowSelection: TableRowSelectionProps<Row> = {
    type: 'checkbox', checkStrictly: false, selectedRowKeys: selectedMenuKeys,
    checkboxProps: (row) => ({
      'aria-label': `选中菜单：${row.title}`,
      disabled: row.kind === 'page'
        ? !isFounder && !allowed.has(row.viewCode) && !selected.has(row.viewCode)
        : !row.children.some((page) => isFounder || allowed.has(page.viewCode) || selected.has(page.viewCode)),
    }),
    onSelect: (checked, row) => toggleMenu(row, checked),
    onSelectAll: (checked) => toggleAllMenus(checked),
  }
  return <div className="permission-editor">
    <div className="permission-toolbar">
      <Radio.Group type="button" value={linked ? 'linked' : 'independent'} onChange={(mode) => setLinked(mode === 'linked')}
        options={[{ label: '节点关联', value: 'linked' }, { label: '节点独立', value: 'independent' }]} />
      <Space size="mini">
        <Button icon={expandedKeys.length ? <IconShrink /> : <IconExpand />}
          onClick={() => setCollapsed(expandedKeys.length ? new Set(modules.map((module) => module.key)) : new Set())}>
          {expandedKeys.length ? '折叠' : '展开'}
        </Button>
        <Button disabled={editableCodes.every((code) => selected.has(code))} onClick={() => toggleAll(true)}>全选</Button>
        <Button disabled={editableCodes.every((code) => !selected.has(code))} onClick={() => toggleAll(false)}>清空</Button>
      </Space>
    </div>
    <Typography.Paragraph type="secondary">
      {linked ? '节点关联：选中菜单时勾选可授权操作，勾选操作时选中菜单；取消菜单时清空其操作。' : '节点独立：菜单与操作权限分别勾选。'}
      当前仅展示已开放页面；历史授权仍予保留。
    </Typography.Paragraph>
    <div className="table-scroll"><Table<Row> rowKey="key" columns={columns} data={modules} rowSelection={rowSelection} pagination={false}
      expandedRowKeys={expandedKeys} onExpandedRowsChange={(keys) => setCollapsed(new Set(modules.filter((module) => !keys.includes(module.key)).map((module) => module.key)))}
      border scroll={{ x: 'max-content' }} /></div>
    {!isFounder && <Typography.Text type="secondary">灰色选项超出当前账号可授予的权限。</Typography.Text>}
  </div>
}
