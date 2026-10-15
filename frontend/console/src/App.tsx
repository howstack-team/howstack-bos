import { Suspense, useEffect, useRef } from 'react'
import { useConsoleTheme } from './ConsoleThemeContext'
import { consolePageTitle } from '../../packages/console-theme-runtime'
import { Button, Message } from '@arco-design/web-react'
import { Redirect, Route, Switch, useLocation } from 'react-router-dom'
import { useMemberAuth } from './auth/AuthContext'
import PageLoading from './components/PageLoading'
import ConsoleLayout from './layout'
import LoginPage from './pages/LoginPage'
import RegisterPage from './pages/RegisterPage'
import NotFoundPage from './pages/NotFoundPage'
import { consoleRoutes, paths } from './routes'
import { loginReturnPath } from './utils/loginReturnPath'
import './App.css'
import OAuthBindPage from './pages/OAuthBindPage'
import CloudPurchasePage from './pages/CloudPurchasePage'

/** 会员会话入口；未登录时保留完整访问路径，成功后恢复。 */
export default function App() {
  const { member, loading, error, retry } = useMemberAuth()
  const reportedError = useRef<unknown>(null)
  useEffect(() => {
    if (error && !member && reportedError.current !== error) Message.error(error.message)
    reportedError.current = error
  }, [error, member])
  const location = useLocation()
  const pathname = location.pathname.replace(/\/+$/, '') || '/'
  const platform = useConsoleTheme()?.site.name ?? ''
  const pageName = pathname === paths.oauthBind ? '关联平台账号' : pathname === paths.login ? '会员登录' : pathname === paths.register ? '会员注册'
    : pathname === paths.purchase ? '购买云服务器' : pathname.startsWith(`${paths.orders}/`) ? '订单详情'
    : pathname === '/' ? '控制台' : consoleRoutes.find(route => route.path === pathname)?.name ?? '页面不存在'
  useEffect(() => { document.title = consolePageTitle(pageName, platform) }, [pageName, platform])
  if (loading) return <PageLoading fullPage />
  if (error && !member) return <main className="member-loading">
    <Button type="primary" onClick={retry}>重新加载</Button>
  </main>
  if (pathname === paths.oauthBind) return <OAuthBindPage />
  if (pathname === paths.purchase) return member ? <ConsoleLayout><CloudPurchasePage /></ConsoleLayout> : <CloudPurchasePage />
  if (!member) return pathname === paths.register ? <RegisterPage /> : pathname === paths.login ? <LoginPage /> : <Redirect
    to={{ pathname: paths.login, state: { from: `${location.pathname}${location.search}${location.hash}` } }} />
  if (pathname === paths.login || pathname === paths.register) return <Redirect to={loginReturnPath(location.state)} />
  return <ConsoleLayout>
    <Suspense fallback={<PageLoading />}>
      <Switch>
        <Route exact path="/"><Redirect to={paths.overview} /></Route>
        {consoleRoutes.map(({ path, component: Page }) => <Route exact key={path} path={path} component={Page} />)}
        <Route path="*" component={NotFoundPage} />
      </Switch>
    </Suspense>
  </ConsoleLayout>
}
