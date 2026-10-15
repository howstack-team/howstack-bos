import { useFeedback } from './feedback'
import { useState } from 'react'
import { useHistory } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import Button from '@arco-design/mobile-react/esm/button'
import Cell from '@arco-design/mobile-react/esm/cell'
import Dialog from '@arco-design/mobile-react/esm/dialog'
import { useMemberAuth } from '../auth/AuthContext'
import { useWallet } from '../hooks/useWallet'
import { minorToYuanText } from '../utils/money'
import { maskEmail, maskMobile } from '../utils/memberDisplay'
import { useConsoleTheme } from '../ConsoleThemeContext'
import { setPresentation } from '../../../packages/console-theme-runtime'
import { paths } from '../routePaths'
import { messageApi, type InboxMessage } from '../api/messageApi'
import { Busy, EmptyState, Glyph, Status } from './ui'

import { memberLinks } from './navigation'
const failureText = (error: unknown) => error instanceof Error ? error.message : '操作失败，请重试'
function LinkCell({ label, to, icon }: { label: string; to: string; icon?: React.ReactNode }) {
  const history = useHistory()
  // 原生 button 提供键盘及辅助技术语义，Cell 负责移动样式。
  return <button className="m-cell-button" onClick={() => history.push(to)}><Cell label={label} icon={icon} showArrow /></button>
}
export function OverviewPage() {
  const member = useMemberAuth().member!
  const history = useHistory(), balance = useWallet()
  return <div className="m-overview"><section className="m-greeting"><h1>账户总览</h1><p>您好，{member.nickname || '会员'}</p><span className="m-muted">会员 ID {member.id}</span></section>
    <section className="m-panel m-balance"><div className="m-row"><span>可用余额</span><span className="m-balance-icon"><Glyph name="wallet" /></span></div>
      {balance.isPending ? <Busy /> : balance.isError ? <Status text="余额加载失败" retry={() => { void balance.refetch() }} /> : <>
        <div className="m-amount"><small>¥</small>{minorToYuanText(balance.data.available_minor)}</div>
      </>}
      <div className="m-balance-footer">{balance.data && !balance.isError && <p className="m-muted">冻结金额 ¥{minorToYuanText(balance.data.frozen_minor)}</p>}<Button type="default" inline size="small" onClick={() => history.push(paths.wallet)}>查看资金账户</Button></div>
    </section>
    <section className="m-panel m-overview-shortcuts"><h2>快捷入口</h2><div className="m-shortcuts">
      {[{ to: paths.cloudServers, label: '我的服务', icon: 'services' }, { to: paths.orders, label: '我的订单', icon: 'wallet' },
        { to: paths.messages, label: '消息中心', icon: 'messages' }, { to: paths.tickets, label: '工单支持', icon: 'user' }].map(item =>
        <button key={item.to} onClick={() => history.push(item.to)}><span className="m-shortcut-icon"><Glyph name={item.icon as 'services' | 'wallet' | 'messages' | 'user'} /></span><span>{item.label}</span></button>)}
    </div></section><section className="m-panel m-cell-panel m-overview-account"><h2>账户管理</h2><LinkCell label="账户资料" to={paths.profile} icon={<Glyph name="user" />} /><LinkCell label="安全设置" to={paths.security} icon={<Glyph name="shield" />} /></section>
  </div>
}
export function WalletPage() {
  const balance = useWallet()
  return <><section className="m-panel"><h2>资金账户</h2>{balance.isPending ? <Busy /> : balance.isError ? <Status text="资金信息加载失败" retry={() => { void balance.refetch() }} /> : <>
    <p className="m-muted">可用余额</p><div className="m-amount"><small>¥</small>{minorToYuanText(balance.data.available_minor)}</div>
    <Cell label="冻结金额" text={`¥${minorToYuanText(balance.data.frozen_minor)}`} /><Cell label="账户币种" text="人民币 CNY" />
  </>}</section><section className="m-panel m-cell-panel">{memberLinks.filter(item => item.group === '财务管理').map(item => <LinkCell key={item.path} label={item.name} to={item.path} />)}</section></>
}
export function ProfilePage() {
  const { member, logout } = useMemberAuth(), theme = useConsoleTheme()!, feedback = useFeedback()
  const [confirm, setConfirm] = useState(false), [busy, setBusy] = useState(false)
  return <><section className="m-panel m-profile"><div className="m-avatar"><Glyph name="user" /></div><h1>{member!.nickname || '会员'}</h1><p className="m-muted">会员 ID {member!.id}</p></section>
    <section className="m-panel m-cell-panel"><Cell label="手机号" text={maskMobile(member!.mobile ?? '')} /><Cell label="邮箱" text={maskEmail(member!.email ?? '')} /></section>
    <section className="m-panel m-cell-panel"><LinkCell label="资金账户" to={paths.wallet} icon={<Glyph name="wallet" />} />
      {memberLinks.filter(item => ['账户管理', '服务与帮助'].includes(item.group)).map(item => <LinkCell key={item.path} label={item.name} to={item.path} />)}
      {theme.capabilities.includes('theme.presentation.split') && <button className="m-cell-button" onClick={() => setPresentation('pc')}><Cell label="使用 PC 版" showArrow /></button>}
    </section><Button type="default" onClick={() => setConfirm(true)}>退出登录</Button>
    <Dialog title="退出登录" visible={confirm} close={() => { if (!busy) setConfirm(false) }} maskClosable={!busy} footer={[
      { content: '取消', disabled: busy, onClick: () => setConfirm(false) },
      { content: busy ? '正在退出…' : '退出', disabled: busy, onClick: async () => {
        if (busy) return true
        setBusy(true)
        try { await logout() } catch (error) { feedback(failureText(error)) } finally { setBusy(false) }
        return true
      } },
    ]}>退出后需要重新登录才能管理您的服务。</Dialog>
  </>
}
export function PendingPage({ path }: { path: string }) {
  const history = useHistory()
  const route = memberLinks.find(item => item.path === path)
  if (!route) return <Status title="页面不存在" text="请通过底部导航返回会员中心" />
  return <><section className="m-panel"><Status title={route.name} text="此功能的会员接口暂未接入" /></section>
    {path === paths.cloudServers && <section className="m-panel"><Button onClick={() => history.push(paths.purchase)}>购买云服务器</Button></section>}
    {route.group === '产品与服务' && <section className="m-panel m-cell-panel"><h2>产品与服务</h2>{memberLinks.filter(item => item.group === route.group).map(item => <LinkCell key={item.path} label={item.name} to={item.path} />)}</section>}
  </>
}
export function MessagesPage() {
  const [page, setPage] = useState(1), [selected, setSelected] = useState<InboxMessage | null>(null), [busy, setBusy] = useState(false)
  const feedback = useFeedback(), client = useQueryClient()
  const messages = useQuery({ queryKey: ['console', 'messages', page], queryFn: ({ signal }) => messageApi.list(page, signal) })
  async function mark(message?: InboxMessage) {
    if (busy) return
    if (message) setSelected(message)
    if (message && !message.unread) return
    setBusy(true)
    try {
      if (message) await messageApi.read(message.id); else await messageApi.readAll()
      await client.invalidateQueries({ queryKey: ['console', 'messages'] })
      if (!message) feedback('已全部标为已读', 'success')
    } catch (error) { feedback(failureText(error)) } finally { setBusy(false) }
  }
  return <><div className="m-row m-list-heading"><span>{messages.data ? `共 ${messages.data.total} 条消息` : '消息中心'}</span>
    <button className="m-text-button" disabled={busy || !messages.data?.total} onClick={() => { void mark() }}>全部已读</button></div>
    {messages.isPending ? <Busy /> : messages.isError ? <Status text="消息加载失败" retry={() => { void messages.refetch() }} /> : !messages.data.data.length ? <EmptyState text="暂无消息" /> :
      <div className="m-message-list">{messages.data.data.map(message => <button key={message.id} className="m-panel m-message" onClick={() => { void mark(message) }} disabled={busy}>
        <div className="m-row"><h2>{message.unread && <span className="m-unread" aria-label="未读" />}{message.title}</h2><span>›</span></div><p>{message.body}</p><time>{message.created_at}</time>
      </button>)}</div>}
    {Boolean(messages.data?.total) && <div className="m-pagination"><Button type="default" inline size="small" disabled={page === 1 || messages.isFetching} onClick={() => setPage(page - 1)}>上一页</Button>
      <span>第 {page} 页</span><Button type="default" inline size="small" disabled={page * 10 >= (messages.data?.total ?? 0) || messages.isFetching} onClick={() => setPage(page + 1)}>下一页</Button></div>}
    <Dialog title={selected?.title} visible={Boolean(selected)} close={() => setSelected(null)} getScrollContainer={() => document.getElementById('mobile-message-body')} footer={[{ content: '关闭', onClick: () => setSelected(null) }]}>
      <div id="mobile-message-body" className="m-message-body">{selected?.body}</div>
    </Dialog>
  </>
}
