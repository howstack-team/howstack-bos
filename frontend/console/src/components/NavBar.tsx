import defaultLogo from '../assets/logo.png'
import { setPresentation } from '../../../packages/console-theme-runtime'
import { useConsoleTheme } from '../ConsoleThemeContext'
import { useState } from 'react'
import { Avatar, Button, Dropdown, Link, Menu, Message, Tooltip } from '@arco-design/web-react'
import { IconClose, IconMenu, IconMoonFill, IconPoweroff, IconSunFill, IconUser } from '@arco-design/web-react/icon'
import { useHistory } from 'react-router-dom'
import { useMemberAuth } from '../auth/AuthContext'
import { consoleTopRoutes, paths } from '../routes'
import MessageInbox from './MessageInbox'

/** 控制台、工单和费用使用普通链接跳转，不显示当前页面选中状态。 */
export default function NavBar({ narrow, dark, mobileMenuOpen, onThemeChange, onMobileMenuToggle }: {
  narrow: boolean; dark: boolean; mobileMenuOpen: boolean; onThemeChange: () => void; onMobileMenuToggle: () => void
}) {
  const template = useConsoleTheme()
  const { member, logout } = useMemberAuth()
  const history = useHistory()
  const [signingOut, setSigningOut] = useState(false)
  async function signOut() {
    if (signingOut) return
    setSigningOut(true)
    try { await logout(); history.replace(paths.login) }
    catch (error) { Message.error(error instanceof Error ? error.message : '退出失败，请稍后重试') }
    finally { setSigningOut(false) }
  }
  const droplist = <Menu onClickMenuItem={(key) => {
    if (key === 'profile') history.push(paths.profile)
    if (key === 'logout') void signOut()
    if (key === 'pc' || key === 'mobile' || key === 'auto') setPresentation(key)
  }}>
    <Menu.Item key="profile"><IconUser className="navbar-dropdown-icon" />账户设置</Menu.Item>
    {template?.capabilities.includes('theme.presentation.split') && <Menu.SubMenu key="presentation" title="显示方式">
      <Menu.Item key="auto">自动选择</Menu.Item><Menu.Item key="pc">PC版</Menu.Item><Menu.Item key="mobile">移动端</Menu.Item>
    </Menu.SubMenu>}
    <Menu.Item key="logout" disabled={signingOut}><IconPoweroff className="navbar-dropdown-icon" />退出登录</Menu.Item>
  </Menu>
  function renderTopLink(route: (typeof consoleTopRoutes)[number]) {
    return <Link key={route.key} hoverable={false} href={`${template?.basePath || '/console'}${route.path}`}
      onClick={event => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
        event.preventDefault()
        history.push(route.path)
      }}>{route.name}</Link>
  }
  return <div className="console-navbar">
    <div className="navbar-brand">
      {narrow && <button type="button" id="console-mobile-menu-toggle" className="mobile-menu-toggle"
        aria-label={mobileMenuOpen ? '关闭导航菜单' : '打开导航菜单'} aria-expanded={mobileMenuOpen}
        aria-controls="console-mobile-navigation" onClick={onMobileMenuToggle}
        onKeyDown={(event) => { if (event.key === 'Escape' && mobileMenuOpen) onMobileMenuToggle() }}>
        {mobileMenuOpen ? <IconClose /> : <IconMenu />}
      </button>}
      <a href={`${template?.basePath || '/console'}/overview`} className="brand-link" title={template?.site.name} onClick={(event) => { event.preventDefault(); history.push(paths.overview) }}>
        <img src={template?.site.logo || defaultLogo} alt="" /><span>{template?.site.name || '会员中心'}</span>
      </a>
    </div>
    <nav className="console-top-navigation" aria-label="控制台顶部导航">
      {consoleTopRoutes.filter(route => route.key === 'overview').map(renderTopLink)}
    </nav>
    <nav className="console-top-shortcuts" aria-label="工单与费用导航">
      {consoleTopRoutes.filter(route => route.key !== 'overview').map(renderTopLink)}
    </nav>
    <div className="navbar-actions">
      <span className="navbar-action-item"><MessageInbox narrow={narrow} /></span>
      <span className="navbar-action-item"><Tooltip content={dark ? '切换浅色模式' : '切换深色模式'}>
        <Button type="secondary" shape="circle" className="navbar-icon-button"
          icon={dark ? <IconSunFill className="navbar-action-icon" /> : <IconMoonFill className="navbar-action-icon" />}
          aria-label={dark ? '切换浅色模式' : '切换深色模式'} onClick={onThemeChange} />
      </Tooltip></span>
      <span className="navbar-action-item"><Dropdown droplist={droplist} position="br" trigger="click">
        <button type="button" className="member-menu-trigger" aria-label="会员菜单" disabled={signingOut} aria-busy={signingOut}>
          <Avatar size={32} style={{ backgroundColor: 'rgb(var(--cyan-6))' }}>{(member?.nickname || '会员').slice(0, 1)}</Avatar>
        </button>
      </Dropdown></span>
    </div>
  </div>
}
