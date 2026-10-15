import { StrictMode } from 'react'
import { ConfigProvider } from '@arco-design/web-react'
import zhCN from '@arco-design/web-react/es/locale/zh-CN'
import '@arco-design/web-react/es/_util/react-19-adapter'
import '@arco-design/web-react/dist/css/arco.css'
import '@arco-themes/react-arco-pro/css/arco.css'
import './style/auth-inputs.less'
import './style/console-navigation.less'
import { QueryClientProvider } from '@tanstack/react-query'
import { parseConsoleSite, themeRequest, type ConsoleThemeConfig } from '../../packages/console-theme-runtime'
import { configureApiBase } from './api/httpClient'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { ConsoleThemeProvider } from './ConsoleThemeProvider'
import App from './App.tsx'
import './index.css'
import { appComponentConfig } from './theme.ts'
import { createMemberQueryClient } from './api/queryClient'
import AuthProvider from './auth/AuthProvider'
import emptyStateImage from './assets/empty-state.png'
import loadingImage from './assets/loading.svg?url'

const loadingElement = <img src={loadingImage} className="app-loading-icon" alt="加载中" />

async function start() {
  const node = document.getElementById('console-theme-config');
  const runtime: ConsoleThemeConfig = node ? JSON.parse(node.textContent || '{}') : await themeRequest<ConsoleThemeConfig>({ apiBase: '/api/console/v1' } as ConsoleThemeConfig, '/bootstrap');
  if (runtime.contract !== '1' || runtime.basePath !== '/console' || runtime.apiBase !== '/api/console/v1') throw new Error('会员中心配置无效')
  runtime.site = parseConsoleSite(runtime.site)
  configureApiBase(runtime.apiBase)
  const queryClient = createMemberQueryClient()
  const root = createRoot(document.getElementById('root')!)
  root.render(
  <StrictMode>
    <ConfigProvider locale={zhCN} loadingElement={loadingElement} componentConfig={{ ...appComponentConfig,
      Empty: { imgSrc: emptyStateImage }, Spin: { element: loadingElement },
    }}>
      <QueryClientProvider client={queryClient}>
        <ConsoleThemeProvider initial={runtime}>
        <BrowserRouter basename={runtime.basePath}>
          <AuthProvider><App /></AuthProvider>
        </BrowserRouter>
        </ConsoleThemeProvider>
      </QueryClientProvider>
    </ConfigProvider>
  </StrictMode>,
)
}
void start().catch(error => { document.getElementById('root')!.textContent = error instanceof Error ? error.message : '会员中心加载失败' })
