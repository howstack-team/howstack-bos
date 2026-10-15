import { useState, type ReactNode } from 'react'
import { Button, Card, Empty, Typography } from '@arco-design/web-react'
import { useHistory } from 'react-router-dom'

/** 工作台快捷入口，只包含当前管理员有查看权限的既有页面。 */
export interface WorkplaceShortcut { name: string; path: string; icon: ReactNode }
interface WorkplaceShortcutsProps { entries: readonly WorkplaceShortcut[] }

/** 采用 Arco Pro 的三列图标入口；查看更多展开当前权限允许的管理入口。 */
export default function WorkplaceShortcuts({ entries }: WorkplaceShortcutsProps) {
  const history = useHistory()
  const [expanded, setExpanded] = useState(false)
  const visible = expanded ? entries : entries.slice(0, 6)
  function grid(items: readonly WorkplaceShortcut[]) {
    return <div className="workplace-shortcuts">{items.map(entry => <button type="button" className="workplace-shortcut" key={entry.path} onClick={() => history.push(entry.path)}>
      <span className="workplace-shortcut-icon" aria-hidden="true">{entry.icon}</span><span className="workplace-shortcut-label">{entry.name}</span>
    </button>)}</div>
  }
  return <section aria-label="快捷操作"><Card className="workplace-card">
    <div className="workplace-shortcuts-heading"><Typography.Title heading={6} style={{ margin: 0 }}>快捷操作</Typography.Title>
      {entries.length > 6 && <Button type="text" size="mini" style={{ padding: 0, background: 'transparent', fontSize: 14 }} aria-expanded={expanded} aria-controls="workplace-shortcuts-list" onClick={() => setExpanded(value => !value)}>{expanded ? '收起' : '查看更多'}</Button>}
    </div>
    <div id="workplace-shortcuts-list">{entries.length ? grid(visible) : <Empty description="暂无可用的快捷操作" />}</div>
  </Card></section>
}
