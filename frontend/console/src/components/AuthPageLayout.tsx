import defaultLogo from '../assets/logo.png'
import { useConsoleTheme } from '../ConsoleThemeContext'
import type { ReactNode } from 'react'
import { Divider, Link } from '@arco-design/web-react'
import { IconArrowLeft } from '@arco-design/web-react/icon'
import cloudScene from '../assets/login-cloud-scene-v2.webp'
import './AuthPageLayout.css'
import BrandFooter from './BrandFooter'

interface AuthPageLayoutProps {
  /** 卡片内业务页面标题的 ID，用于可访问名称。 */
  titleId: string
  /** 注册字段较多，桌面短窗口使用对应的纵向间距。 */
  variant?: 'login' | 'register'
  /** 登录或注册页面提供的标题、表单及提示。 */
  children: ReactNode
}

/** 登录和注册共用品牌顶栏、背景、响应式卡片及自然滚动的协议页脚。 */
export default function AuthPageLayout({ titleId, variant = 'login', children }: AuthPageLayoutProps) {
  const template = useConsoleTheme()
  return <div className={`console-login${variant === 'register' ? ' console-register' : ''}`}>
    <div className="console-login-scene" aria-hidden="true" style={{ backgroundImage: `url(${cloudScene})` }} />
    <header className="console-login-top">
      <div className="console-login-top-inner">
        <a className="console-login-brand" href="/" aria-label={`${template?.site.name || '平台'}首页`} title={template?.site.name}>
          <img src={template?.site.logo || defaultLogo} alt="" /><span>{template?.site.name || '会员中心'}</span>
        </a>
        <nav className="console-login-top-actions" aria-label="账号页面导航">
          <Link href="/" icon={<IconArrowLeft style={{ fontSize: 16 }} />}
            style={{ fontSize: 'inherit', lineHeight: '32px', padding: '0 8px', color: 'var(--color-text-2)' }}>返回官网</Link>
        </nav>
      </div>
    </header>
    <main className="console-login-main">
      <section className="console-login-story" aria-label="云计算服务">
        <h2>专注于更好的计算体验</h2>
        <p>稳定 · 安全 · 高效 · 可控</p>
        <div className="console-login-accent" />
        <span className="console-login-caption">TECHNOLOGY DRIVES<br />A BETTER TOMORROW</span>
      </section>
      <section className="console-login-card" aria-labelledby={titleId}>{children}</section>
    </main>
    <footer className="console-login-footer">
      <BrandFooter />
      <nav className="console-login-agreements" aria-label="法律协议">
        {/* 协议地址待提供，空链接不刷新当前账号表单。 */}
        <Link href="" style={{ color: 'inherit', fontSize: 'inherit', lineHeight: '24px' }}
          onClick={event => event.preventDefault()}>服务协议</Link>
        <Divider type="vertical" />
        <Link href="" style={{ color: 'inherit', fontSize: 'inherit', lineHeight: '24px' }}
          onClick={event => event.preventDefault()}>隐私政策</Link>
      </nav>
    </footer>
  </div>
}
