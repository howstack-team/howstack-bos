import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { QueryClientProvider } from '@tanstack/react-query'
import ContextProvider from '@arco-design/mobile-react/esm/context-provider'
import { setMobileRootPixel } from './rootPixel'
import { parseConsoleSite, themeRequest, type ConsoleThemeConfig } from '../../../packages/console-theme-runtime'
import { configureApiBase } from '../api/httpClient'
import { createMemberQueryClient } from '../api/queryClient'
import AuthProvider from '../auth/AuthProvider'
import { ConsoleThemeProvider } from '../ConsoleThemeProvider'
import { FeedbackProvider } from './ui'
import MobileApp from './MobileApp'
import './style.less'

/** 移动端独立挂载；会话、品牌及 API 与 PC 共享，组件及样式单独构建。 */
async function start() {
  const node = document.getElementById('console-theme-config')
  const runtime: ConsoleThemeConfig = node ? JSON.parse(node.textContent || '{}') : await themeRequest<ConsoleThemeConfig>({ apiBase: '/api/console/v1' } as ConsoleThemeConfig, '/bootstrap')
  if (runtime.contract !== '1' || runtime.basePath !== '/console' || runtime.apiBase !== '/api/console/v1') throw new Error('会员中心配置无效')
  runtime.site = parseConsoleSite(runtime.site)
  configureApiBase(runtime.apiBase)
  setMobileRootPixel()
  createRoot(document.getElementById('root')!).render(
    <StrictMode><ContextProvider createRoot={createRoot}>
      <QueryClientProvider client={createMemberQueryClient()}><ConsoleThemeProvider initial={runtime}>
        <BrowserRouter basename={runtime.basePath}><AuthProvider><FeedbackProvider><MobileApp /></FeedbackProvider></AuthProvider></BrowserRouter>
      </ConsoleThemeProvider></QueryClientProvider>
    </ContextProvider></StrictMode>,
  )
}
void start().catch(error => { document.getElementById('root')!.textContent = error instanceof Error ? error.message : '会员中心加载失败' })
