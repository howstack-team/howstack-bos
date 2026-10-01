import { Dropdown, Link, Menu, Space } from '@arco-design/web-react'
import { IconMore } from '@arco-design/web-react/icon'

/** 表格操作项；敏感操作在需要折叠时优先收纳到“更多”菜单。 */
export interface TableAction {
  key: string
  label: string
  onClick: () => void
  sensitive?: boolean
  danger?: boolean
}

/** 统一表格操作列的紧凑间距与溢出菜单。 */
export default function TableActions({ actions }: { actions: TableAction[] }) {
  if (actions.length === 0) return null

  const ordered = [...actions.filter((action) => !action.sensitive), ...actions.filter((action) => action.sensitive)]
  const visible = ordered.slice(0, 3)
  const more = ordered.slice(3)

  return <Space size="mini">
    {visible.map((action) => <Link key={action.key} href="#" status={action.danger ? 'error' : undefined}
      onClick={(event) => { event.preventDefault(); action.onClick() }}>{action.label}</Link>)}
    {more.length > 0 && <Dropdown trigger="click" position="br" droplist={
      <Menu onClickMenuItem={(key) => more.find((action) => action.key === key)?.onClick()}>
        {more.map((action) => <Menu.Item key={action.key}>{action.label}</Menu.Item>)}
      </Menu>
    }>
      <Link href="#" icon={<IconMore />} aria-label="更多操作" onClick={(event) => event.preventDefault()} />
    </Dropdown>}
  </Space>
}
