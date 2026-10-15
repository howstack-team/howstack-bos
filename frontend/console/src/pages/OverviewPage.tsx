import { useConsoleTheme } from '../ConsoleThemeContext'
import type { MouseEvent, ReactNode } from 'react'
import { useRef, useState } from 'react'
import { Avatar, Button, Card, Drawer, Link, Space, Statistic, Typography } from '@arco-design/web-react'
import { IconArrowRight, IconBook, IconCalendar, IconCloud, IconCustomerService, IconDesktop, IconFile, IconNotification, IconRefresh, IconSafe, IconStamp, IconStorage } from '@arco-design/web-react/icon'
import { useHistory } from 'react-router-dom'
import { useMemberAuth } from '../auth/AuthContext'
import WalletSummary from '../components/WalletSummary'
import { consoleMenuGroups, consoleRoutes, paths } from '../routes'
import { maskEmail, maskMobile } from '../utils/memberDisplay'
import './OverviewPage.css'

const products = [
  { name: '云服务器', path: paths.cloudServers, icon: <IconCloud /> },
  { name: '物理服务器', path: paths.physicalServers, icon: <IconDesktop /> },
  { name: '服务器托管', path: paths.colocation, icon: <IconStorage /> },
]
const shortcuts = [
  { name: '账户充值', path: paths.recharge, icon: <IconStamp /> },
  { name: '订单管理', path: paths.orders, icon: <IconFile /> },
  { name: '提交工单', path: paths.tickets, icon: <IconCustomerService /> },
  { name: '产品续费', path: paths.renewals, icon: <IconRefresh /> },
  { name: '发票管理', path: paths.invoices, icon: <IconFile /> },
  { name: '安全设置', path: paths.security, icon: <IconSafe /> },
]

function cardTitle(title: string) {
  return <Typography.Title heading={6} style={{ margin: 0 }}>{title}</Typography.Title>
}

/** 会员首页：账户使用本人资料和余额；未开放的产品、消费统计不展示虚构数量。 */
export default function OverviewPage() {
  const template = useConsoleTheme()
  const { member } = useMemberAuth()
  const history = useHistory()
  const [allShortcutsOpen, setAllShortcutsOpen] = useState(false)
  const allShortcutsContent = useRef<HTMLDivElement>(null)
  const nickname = member?.nickname || '会员'
  const today = new Intl.DateTimeFormat('zh-CN', { year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' }).format(new Date())
  function navigate(event: MouseEvent<HTMLAnchorElement>, path: string) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
    event.preventDefault()
    history.push(path)
  }
  function pageLink(name: string, path: string, icon?: ReactNode) {
    return <Link href={`${template?.basePath || '/console'}${path}`} icon={icon} hoverable={false}
      onClick={event => navigate(event, path)}>{name}</Link>
  }
  return <div className="page-stack overview-page">
    <section aria-label="欢迎卡片">
      <Card className="console-page-card overview-welcome" bodyStyle={{ padding: 24 }}>
        <div className="overview-welcome-row">
          <div className="overview-welcome-member">
            <Avatar size={52} style={{ backgroundColor: 'rgb(var(--primary-6))', flexShrink: 0 }}>{nickname.slice(0, 1)}</Avatar>
            <div className="overview-welcome-copy">
              <Typography.Title heading={4} style={{ margin: '0 0 8px' }}>欢迎回来，{nickname}</Typography.Title>
              <Typography.Text type="secondary">您的资源、账户与服务，从这里开始。</Typography.Text>
            </div>
          </div>
          <div className="overview-welcome-date"><IconCalendar aria-hidden="true" /><span>{today}</span></div>
        </div>
        <div className="overview-member-meta">
          <span>会员编号：{member?.id ?? '-'}</span>
          <span>手机：{maskMobile(member?.mobile ?? '')}</span>
          <span>邮箱：{maskEmail(member?.email ?? '')}</span>
          {pageLink('账户设置', paths.profile, <IconArrowRight />)}
        </div>
      </Card>
    </section>
    <div className="overview-columns">
      <div className="overview-main-column">
        <section aria-label="账户概览">
          <Card className="console-page-card" title={cardTitle('账户概览')}
            extra={pageLink('资金账户', paths.wallet, <IconArrowRight />)}>
            <WalletSummary />
            <div className="overview-card-footer">
              <Typography.Text type="secondary">结算币种：人民币 CNY</Typography.Text>
              <Space wrap>
                <Button type="primary" icon={<IconStamp />} onClick={() => history.push(paths.recharge)}>充值</Button>
                {pageLink('资金明细', paths.transactions)}
              </Space>
            </div>
          </Card>
        </section>
        <section aria-label="我的产品">
          <Card className="console-page-card" title={cardTitle('我的产品')}
            extra={pageLink('续费管理', paths.renewals, <IconArrowRight />)}>
            <div className="overview-products">
              {products.map(product => <div className="overview-product" key={product.path}>
                <span className="overview-product-icon" aria-hidden="true">{product.icon}</span>
                <Statistic title={product.name} value="--" styleValue={{ fontSize: 24 }} />
                {pageLink('查看产品', product.path)}
              </div>)}
            </div>
            <div className="overview-section-note">产品信息暂未开放，您可通过上方入口进入对应产品页面。</div>
          </Card>
        </section>
        <section aria-label="费用概览">
          <Card className="console-page-card" title={cardTitle('费用概览')}
            extra={pageLink('订单管理', paths.orders, <IconArrowRight />)}>
            <div className="overview-expenses">
              {['本月消费（元）', '待付金额（元）', '待续费金额（元）'].map(title => <Statistic key={title} title={<span className="overview-expense-title">{title}</span>} value="--" styleValue={{ fontSize: 24 }} />)}
            </div>
            <div className="overview-card-footer">
              <Typography.Text type="secondary">费用统计暂未开放</Typography.Text>
              {pageLink('查看资金明细', paths.transactions)}
            </div>
          </Card>
        </section>
      </div>
      <div className="overview-aside-column">
        <section aria-label="快捷操作">
          <Card className="console-page-card" title={cardTitle('快捷入口')}
            extra={<Button id="overview-more-shortcuts" type="text" size="small" onClick={() => setAllShortcutsOpen(true)}>查看更多</Button>}>
            <div className="overview-shortcuts">
              {shortcuts.map((shortcut, index) => <Button key={shortcut.path} type="text"
                className={index === 0 ? 'overview-shortcut overview-shortcut-primary' : 'overview-shortcut'}
                onClick={() => history.push(shortcut.path)}>
                <span className="overview-shortcut-content"><span className="overview-shortcut-icon" aria-hidden="true">{shortcut.icon}</span><span>{shortcut.name}</span></span>
              </Button>)}
            </div>
          </Card>
          <Drawer title="全部快捷入口" visible={allShortcutsOpen} onCancel={() => setAllShortcutsOpen(false)}
            maskClosable focusLock autoFocus={false} width="min(560px, calc(100vw - 24px))" footer={null}
            afterOpen={() => allShortcutsContent.current?.focus()}
            afterClose={() => document.getElementById('overview-more-shortcuts')?.focus()}>
            <div ref={allShortcutsContent} tabIndex={-1} className="overview-all-shortcuts">
              {consoleMenuGroups.map(group => <div key={group.key}>
                {cardTitle(group.name)}
                <div className="overview-all-shortcut-group">
                  {consoleRoutes.filter(route => route.menu && route.group === group.name).map(route => <Button key={route.key}
                    onClick={() => { setAllShortcutsOpen(false); history.push(route.path) }}>{route.name}</Button>)}
                </div>
              </div>)}
            </div>
          </Drawer>
        </section>
        <section aria-label="帮助文档">
          <Card className="console-page-card" title={cardTitle('帮助文档')}>
            <div className="overview-content-intro"><span className="overview-content-icon" aria-hidden="true"><IconBook /></span>
              <div><Typography.Text bold>产品指南与常见问题</Typography.Text><Typography.Paragraph type="secondary" style={{ margin: '8px 0 0' }}>了解产品使用、账户管理与费用说明，帮助您快速上手。</Typography.Paragraph></div>
            </div>
            <div className="overview-content-link"><Link href="/" target="_blank" rel="noopener noreferrer" icon={<IconArrowRight />} hoverable={false}>前往官网查阅</Link></div>
          </Card>
        </section>
        <section aria-label="最新公告">
          <Card className="console-page-card" title={cardTitle('最新公告')}>
            <div className="overview-content-intro"><span className="overview-content-icon" aria-hidden="true"><IconNotification /></span>
              <div><Typography.Text bold>关注最新服务动态</Typography.Text><Typography.Paragraph type="secondary" style={{ margin: '8px 0 0' }}>产品动态、服务通知与最新公告，请前往官网查看。</Typography.Paragraph></div>
            </div>
            <div className="overview-content-link"><Link href="/" target="_blank" rel="noopener noreferrer" icon={<IconArrowRight />} hoverable={false}>前往官网查看</Link></div>
          </Card>
        </section>
      </div>
    </div>
  </div>
}
