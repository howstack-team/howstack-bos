import { useEffect, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { Drawer, Layout, Menu } from '@arco-design/web-react'
import { IconCloud, IconCustomerService, IconDashboard, IconDesktop, IconFile, IconGift, IconHistory, IconIdcard, IconList, IconMenuFold, IconMenuUnfold, IconMessage, IconRefresh, IconSafe, IconSettings, IconStamp, IconStorage, IconSwap } from '@arco-design/web-react/icon'
import { useLocation, useHistory } from 'react-router-dom'
import NavBar from './components/NavBar'
import BrandFooter from './components/BrandFooter'
import { useNarrowScreen } from './hooks/useNarrowScreen'
import { consoleMenuGroups, consoleRoutes, paths } from './routes'

const icons = {
  overview: <IconDashboard className="console-menu-icon" />, cloudServers: <IconCloud className="console-menu-icon" />,
  physicalServers: <IconDesktop className="console-menu-icon" />, colocation: <IconStorage className="console-menu-icon" />,
  recharge: <IconStamp className="console-menu-icon" />, transactions: <IconList className="console-menu-icon" />,
  orders: <IconFile className="console-menu-icon" />, refunds: <IconSwap className="console-menu-icon" />,
  invoices: <IconFile className="console-menu-icon" />, profile: <IconSettings className="console-menu-icon" />,
  tickets: <IconCustomerService className="console-menu-icon" />, wallet: <IconStamp className="console-menu-icon" />,
  renewals: <IconRefresh className="console-menu-icon" />, coupons: <IconGift className="console-menu-icon" />,
  security: <IconSafe className="console-menu-icon" />, verification: <IconIdcard className="console-menu-icon" />,
  loginHistory: <IconHistory className="console-menu-icon" />, messages: <IconMessage className="console-menu-icon" />,
}

/** 继承 Arco Pro 的顶栏、侧栏、菜单、内容和页脚布局；移动端使用原生 Drawer。 */
export default function ConsoleLayout({ children }: { children: ReactNode }) {
  const history = useHistory()
  const location = useLocation()
  const narrow = useNarrowScreen()
  const [collapsed, setCollapsed] = useState(false)
  const [mobileMenuPath, setMobileMenuPath] = useState<string | null>(null)
  const mobileNavigation = useRef<HTMLElement>(null)
  const mobileMenuOpen = narrow && mobileMenuPath === location.pathname
  const [dark, setDark] = useState(() => {
    try { return localStorage.getItem('console-theme') === 'dark' } catch { return false }
  })
  const pathname = location.pathname.replace(/\/+$/, '') || '/'
  const current = consoleRoutes.find((route) => route.path === pathname) ?? (pathname.startsWith(`${paths.orders}/`) ? consoleRoutes.find(route => route.key === 'orders') : undefined)

  useEffect(() => {
    document.body.setAttribute('arco-theme', dark ? 'dark' : 'light')
    try { localStorage.setItem('console-theme', dark ? 'dark' : 'light') } catch { /* 禁用存储时仍能切换当前页面主题。 */ }
    return () => { document.body.removeAttribute('arco-theme') }
  }, [dark])
  useEffect(() => {
    const media = window.matchMedia('(max-width: 768px)')
    const resetDesktopMenu = () => {
      if (!media.matches) { setMobileMenuPath(null); setCollapsed(false) }
    }
    media.addEventListener('change', resetDesktopMenu)
    return () => media.removeEventListener('change', resetDesktopMenu)
  }, [])

  function renderMenu(collapse = false) {
    return <Menu collapse={collapse} accordion={false} levelIndent={0} selectedKeys={current ? [current.key] : []} onClickMenuItem={(key) => {
      const route = consoleRoutes.find((item) => item.key === key)
      if (route) { history.push(route.path); setMobileMenuPath(null) }
    }}>
      {consoleRoutes.filter(route => route.menu && !route.group).map(route => <Menu.Item key={route.key}>{route.key in icons ? icons[route.key as keyof typeof icons] : null}{route.name}</Menu.Item>)}
      {consoleMenuGroups.map(group => <Menu.ItemGroup key={group.key} title={group.name}>
        {consoleRoutes.filter(route => route.menu && route.group === group.name).map(route => <Menu.Item key={route.key}>{route.key in icons ? icons[route.key as keyof typeof icons] : null}{route.name}</Menu.Item>)}
      </Menu.ItemGroup>)}
    </Menu>
  }

  return <Layout className="console-layout">
    <Layout.Header className="console-header"><NavBar narrow={narrow} dark={dark}
      mobileMenuOpen={mobileMenuOpen} onThemeChange={() => setDark((value) => !value)}
      onMobileMenuToggle={() => setMobileMenuPath((path) => path === location.pathname ? null : location.pathname)} /></Layout.Header>
    <Layout className="console-body">
      {!narrow && <Layout.Sider width={220} collapsedWidth={48} collapsed={collapsed} trigger={null} className="console-sider">
        <nav className="console-menu" aria-label="会员控制台导航">{renderMenu(collapsed)}</nav>
        <button type="button" className="sider-collapse" aria-expanded={!collapsed}
          aria-label={collapsed ? '展开侧边栏' : '收起侧边栏'} onClick={() => setCollapsed((value) => !value)}>
          {collapsed ? <IconMenuUnfold /> : <IconMenuFold />}
        </button>
      </Layout.Sider>}
      <Layout className="console-main" style={{ marginLeft: narrow ? 0 : collapsed ? 48 : 220 }}>
        <Layout.Content className="console-content"><main>{children}</main></Layout.Content>
        <Layout.Footer className="console-footer"><BrandFooter /></Layout.Footer>
      </Layout>
    </Layout>
    {/* 顶栏开关与后台一样保持可操作；通过抽屉回调转移、恢复焦点，保留 Escape 关闭。 */}
    <Drawer maskClosable title={null} closable={false} placement="left" width={260} visible={mobileMenuOpen}
      className="console-mobile-panel" wrapClassName="console-mobile-menu" zIndex={101} bodyStyle={{ padding: 0 }}
      style={{ background: 'var(--color-bg-2)', borderRight: '1px solid var(--color-border)' }}
      focusLock={false} footer={null} onCancel={() => setMobileMenuPath(null)}
      afterOpen={() => mobileNavigation.current?.focus()}
      afterClose={() => { if (window.matchMedia('(max-width: 768px)').matches) document.getElementById('console-mobile-menu-toggle')?.focus() }}>
      <nav id="console-mobile-navigation" ref={mobileNavigation} tabIndex={-1} aria-label="移动端会员导航">{renderMenu()}</nav>
    </Drawer>
  </Layout>
}
