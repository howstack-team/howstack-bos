import { useEffect, useRef } from 'react'
import Button from '@arco-design/mobile-react/esm/button'
import { useFeedback } from './feedback'
import { Redirect, Route, Switch, useHistory, useLocation } from 'react-router-dom'
import NavBar from '@arco-design/mobile-react/esm/nav-bar'
import TabBar from '@arco-design/mobile-react/esm/tab-bar'
import { useMemberAuth } from '../auth/AuthContext'
import { useConsoleTheme } from '../ConsoleThemeContext'
import { consolePageTitle } from '../../../packages/console-theme-runtime'
import { paths } from '../routePaths'
import { loginReturnPath } from '../utils/loginReturnPath'
import { LoginPage, RegisterPage, ResetPasswordPage } from './AuthPages'
import { MessagesPage, OverviewPage, PendingPage, ProfilePage, WalletPage } from './MemberPages'
import { memberLinks } from './navigation'
import { Brand, Busy, Footer, Glyph } from './ui'
import OAuthBindPage from './OAuthBindPage'
import RechargePage from './RechargePage'
import MobileCloudPurchasePage from './CloudPurchasePage'
import MobileMemberOrdersPage from './MemberOrdersPage'

const tabs = [
  { title: '总览', path: paths.overview, icon: <Glyph name="home" /> },
  { title: '服务', path: paths.cloudServers, icon: <Glyph name="services" /> },
  { title: '消息', path: paths.messages, icon: <Glyph name="messages" /> },
  { title: '我的', path: paths.profile, icon: <Glyph name="user" /> },
]
export default function MobileApp() {
  const auth = useMemberAuth(), location = useLocation(), history = useHistory(), theme = useConsoleTheme()!
  const feedback = useFeedback(), reportedError = useRef<unknown>(null)
  useEffect(() => {
    if (auth.error && reportedError.current !== auth.error) feedback(auth.error.message)
    reportedError.current = auth.error
  }, [auth.error, feedback])
  const pathname = location.pathname
  const name = ({ [paths.oauthBind]: '关联平台账号', [paths.login]: '会员登录', [paths.register]: '会员注册', '/forgot-password': '找回密码',
    [paths.overview]: '总览', [paths.messages]: '消息中心', [paths.profile]: '我的账户', [paths.wallet]: '资金账户', [paths.purchase]: '购买云服务器', [paths.orders]: '我的订单' } as Record<string, string>)[pathname]
    || (pathname.startsWith(`${paths.orders}/`) ? '订单详情' : '')
    || memberLinks.find(item => item.path === pathname)?.name || '会员中心'
  useEffect(() => { document.title = consolePageTitle(name, theme.site.name) }, [name, theme.site.name])
  useEffect(() => { window.scrollTo(0, 0) }, [pathname])
  if (auth.loading) return <Busy />
  if (auth.error) return <section className="m-status"><Button inline onClick={auth.retry}>重新加载</Button></section>
  if (pathname === paths.oauthBind) return <OAuthBindPage />
  if (pathname === paths.purchase) return <MobileCloudPurchasePage />
  const anonymousRoute = [paths.login, paths.register, '/forgot-password'].includes(pathname)
  if (!auth.member) return anonymousRoute ? <Switch>
    <Route path={paths.login} exact component={LoginPage} /><Route path={paths.register} exact component={RegisterPage} />
    <Route path="/forgot-password" exact component={ResetPasswordPage} />
  </Switch> : <Redirect to={{ pathname: paths.login, state: { from: `${pathname}${location.search}${location.hash}` } }} />
  if (anonymousRoute) return <Redirect to={loginReturnPath(location.state)} />
  if (pathname === '/') return <Redirect to={paths.overview} />
  const active = pathname === paths.overview ? 0 : pathname.startsWith('/services/') ? 1 : pathname === paths.messages ? 2 : 3
  return <div className="m-app"><header className="m-header"><NavBar title={pathname === paths.overview ? <Brand small /> : name}
    leftContent={pathname === paths.overview ? null : undefined} onClickLeft={() => history.push(paths.overview)} /></header>
    <main className="m-main"><Switch>
      <Route path={paths.overview} exact component={OverviewPage} /><Route path={paths.wallet} exact component={WalletPage} />
      <Route path={paths.recharge} exact component={RechargePage} /><Route path={paths.profile} exact component={ProfilePage} /><Route path={paths.messages} exact component={MessagesPage} />
      <Route path={paths.orders} exact component={MobileMemberOrdersPage} /><Route path={paths.orderDetail} exact component={MobileMemberOrdersPage} />
      <Route render={() => <PendingPage path={pathname} />} />
    </Switch><Footer /></main>
    <TabBar activeIndex={active} dataSource={tabs.map(item => ({ ...item, onClick: () => history.push(item.path) }))} className="m-bottom-nav" />
  </div>
}
