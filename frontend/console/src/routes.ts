import { lazy } from 'react'
import { isDeferredMemberPath } from '../../packages/community-release'

import { paths } from './routePaths'
export { paths } from './routePaths'

/** 侧栏使用原生菜单分组，顺序与业务导航一致。控制台单独置于最前。 */
export const consoleMenuGroups = [
  { key: 'services', name: '产品与服务' },
  { key: 'finance', name: '财务管理' },
  { key: 'account', name: '账户管理' },
  { key: 'support', name: '服务与帮助' },
] as const

const pendingPage = lazy(() => import('./pages/PendingFeaturePage'))
/** 菜单、页面标题与路由共用配置；建设中页面不发起尚未接入的业务请求。 */
const registeredConsoleRoutes = [
  { key: 'overview', path: paths.overview, name: '控制台', group: '', menu: true, component: lazy(() => import('./pages/OverviewPage')) },
  { key: 'cloudServers', path: paths.cloudServers, name: '云服务器', group: '产品与服务', menu: true, component: lazy(() => import('./pages/MemberServicesPage')) },
  { key: 'physicalServers', path: paths.physicalServers, name: '物理服务器', group: '产品与服务', menu: true, component: pendingPage },
  { key: 'colocation', path: paths.colocation, name: '服务器托管', group: '产品与服务', menu: true, component: pendingPage },
  { key: 'renewals', path: paths.renewals, name: '续费管理', group: '产品与服务', menu: true, component: pendingPage },
  { key: 'recharge', path: paths.recharge, name: '充值中心', group: '财务管理', menu: true, component: lazy(() => import('./pages/RechargePage')) },
  { key: 'transactions', path: paths.transactions, name: '资金明细', group: '财务管理', menu: true, component: lazy(() => import('./pages/WalletEntriesPage')) },
  { key: 'orders', path: paths.orders, name: '订单管理', group: '财务管理', menu: true, component: lazy(() => import('./pages/MemberOrdersPage')) },
  { key: 'orderDetail', path: paths.orderDetail, name: '订单详情', group: '财务管理', menu: false, component: lazy(() => import('./pages/MemberOrdersPage')) },
  { key: 'purchase', path: paths.purchase, name: '购买云服务器', group: '产品与服务', menu: false, component: lazy(() => import('./pages/CloudPurchasePage')) },
  { key: 'refunds', path: paths.refunds, name: '退款管理', group: '财务管理', menu: true, component: pendingPage },
  { key: 'invoices', path: paths.invoices, name: '发票管理', group: '财务管理', menu: true, component: pendingPage },
  { key: 'coupons', path: paths.coupons, name: '优惠券', group: '财务管理', menu: true, component: pendingPage },
  { key: 'profile', path: paths.profile, name: '账户设置', group: '账户管理', menu: true, component: lazy(() => import('./pages/ProfilePage')) },
  { key: 'security', path: paths.security, name: '安全设置', group: '账户管理', menu: true, component: pendingPage },
  { key: 'verification', path: paths.verification, name: '实名认证', group: '账户管理', menu: true, component: pendingPage },
  { key: 'loginHistory', path: paths.loginHistory, name: '登录记录', group: '账户管理', menu: true, component: pendingPage },
  { key: 'messages', path: paths.messages, name: '消息中心', group: '账户管理', menu: false, component: lazy(() => import('./pages/MessageCenterPage')) },
  { key: 'tickets', path: paths.tickets, name: '工单管理', group: '服务与帮助', menu: true, component: lazy(() => import('./pages/TicketsPage')) },
  { key: 'ticketDetail', path: paths.ticketDetail, name: '工单详情', group: '服务与帮助', menu: false, component: lazy(() => import('./pages/TicketsPage')) },
  { key: 'wallet', path: paths.wallet, name: '资金账户', group: '财务管理', menu: false, component: lazy(() => import('./pages/WalletPage')) },
] as const

/** 暂缓页面同时退出路由和菜单，直接访问由统一 404 处理。 */
export const consoleRoutes = registeredConsoleRoutes.filter(route => !isDeferredMemberPath(route.path))

/** 顶部快捷导航。费用入口保留现有本人资金账户，财务子页共用选中状态。 */
export const consoleTopRoutes = [
  { key: 'overview', name: '控制台', path: paths.overview },
  { key: 'tickets', name: '工单', path: paths.tickets },
  { key: 'finance', name: '费用', path: paths.wallet },
] as const
