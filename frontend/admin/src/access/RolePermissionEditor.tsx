import { useMemo, useState } from 'react'
import { Button, Checkbox, Empty, Radio, Table, Typography } from '@arco-design/web-react'
import type { TableColumnProps, TableRowSelectionProps } from '@arco-design/web-react'
import { IconExpand, IconShrink } from '@arco-design/web-react/icon'
import { routes } from '../routes.ts'
import { buildPermissionTree, permissionPages, permissionGroupKeys, type PermissionRow as Row, type PermissionPage as PageRow } from './permissionTree.ts'

interface Props {
  catalog: Record<string, { label: string; group: string }>
  value: string[]
  grantable: string[]
  isFounder: boolean
  onChange: (next: string[]) => void
}

/** 只展示已开放页面，保留其他历史授权。 */
export default function RolePermissionEditor({ catalog, value, grantable, isFounder, onChange }: Props) {
  const modules = useMemo(() => buildPermissionTree(routes, catalog), [catalog])
  const selected = useMemo(() => new Set(value), [value])
  const allowed = useMemo(() => new Set(grantable), [grantable])
  const [linked, setLinked] = useState(true)
  const [collapsed, setCollapsed] = useState<Set<string>>(() => new Set())
  const groupKeys = useMemo(() => permissionGroupKeys(modules), [modules])
  const pages = useMemo(() => permissionPages(modules), [modules])
  const expandedKeys = groupKeys.filter(key => !collapsed.has(key))
  const selectedMenuKeys = pages.filter(row => selected.has(row.viewCode)).map(row => row.key)

  const canGrantPage = (row: PageRow) => [row.viewCode, ...row.dependencies].every(code => isFounder || allowed.has(code) || selected.has(code))

  function changeMenu(next: Set<string>, row: PageRow, checked: boolean) {
    if (checked && !canGrantPage(row) || !checked && !isFounder && !allowed.has(row.viewCode) && !selected.has(row.viewCode)) return
    if (checked) { next.add(row.viewCode); row.dependencies.forEach(code => next.add(code)) }
    else next.delete(row.viewCode)
    if (linked) row.permissions.forEach(({ code }) => {
      if (isFounder || allowed.has(code) || selected.has(code)) {
        if (checked) next.add(code)
        else if (!pages.some(page => page.key !== row.key && next.has(page.viewCode) && page.permissions.some(option => option.code === code))) next.delete(code)
      }
    })
  }

  function toggleMenu(row: Row, checked: boolean) {
    const next = new Set(value)
    const pages = permissionPages([row])
    pages.forEach((page) => changeMenu(next, page, checked))
    onChange([...next])
  }

  function toggleAllMenus(checked: boolean) {
    const next = new Set(value)
    pages.forEach(row => changeMenu(next, row, checked))
    onChange([...next])
  }

  function togglePermission(code: string, checked: boolean, row: PageRow) {
    const next = new Set(value)
    if (checked) {
      next.add(code)
      if (linked && canGrantPage(row)) { next.add(row.viewCode); row.dependencies.forEach(dependency => next.add(dependency)) }
    } else {
      next.delete(code)
    }
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
            || linked && !selected.has(code) && !canGrantPage(row))}
          onChange={(checked) => togglePermission(code, checked, row)}>{label}</Checkbox>)}
      </div>,
    },
  ]
  const rowSelection: TableRowSelectionProps<Row> = {
    type: 'checkbox', checkStrictly: false, selectedRowKeys: selectedMenuKeys,
    checkboxProps: (row) => ({
      'aria-label': `选中菜单：${row.title}`,
      disabled: row.kind === 'page'
        ? !canGrantPage(row) && !selected.has(row.viewCode)
        : !permissionPages([row]).some(page => canGrantPage(page) || selected.has(page.viewCode)),
    }),
    onSelect: (checked, row) => toggleMenu(row, checked),
    onSelectAll: (checked) => toggleAllMenus(checked),
  }
  return <div className="permission-editor">
    <div className="permission-toolbar">
      <Radio.Group type="button" value={linked ? 'linked' : 'independent'} onChange={(mode) => setLinked(mode === 'linked')}
        options={[{ label: '节点关联', value: 'linked' }, { label: '节点独立', value: 'independent' }]} />
      <Button icon={expandedKeys.length ? <IconShrink /> : <IconExpand />}
        onClick={() => setCollapsed(expandedKeys.length ? new Set(groupKeys) : new Set())}>
        {expandedKeys.length ? '折叠' : '展开'}
      </Button>
    </div>
    <Typography.Paragraph type="secondary">
      {linked ? '节点关联：选中菜单时勾选可授权操作，勾选操作时选中菜单；取消菜单时清空其操作。' : '节点独立：菜单与操作权限分别勾选。'}
      当前仅展示已开放页面；历史授权仍予保留。云服务器资源操作为共用权限，各配置页的查看权限独立分配。
    </Typography.Paragraph>
    <div className="table-scroll"><Table<Row> rowKey="key" columns={columns} data={modules} rowSelection={rowSelection} pagination={false}
      expandedRowKeys={expandedKeys} onExpandedRowsChange={(keys) => setCollapsed(new Set(groupKeys.filter(key => !keys.includes(key))))}
      border scroll={{ x: 'max-content' }} /></div>
    {!isFounder && <Typography.Text type="secondary">灰色选项超出当前账号可授予的权限。</Typography.Text>}
  </div>
}
