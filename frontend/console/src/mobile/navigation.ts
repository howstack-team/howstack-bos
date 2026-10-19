import { isDeferredMemberPath } from '../../../packages/community-release'
import { paths } from '../routePaths'

const registeredLinks = [
  { path: paths.cloudServers, name: '云服务器', group: '产品与服务' },
  { path: paths.physicalServers, name: '物理服务器', group: '产品与服务' },
  { path: paths.colocation, name: '服务器托管', group: '产品与服务' },
  { path: paths.renewals, name: '续费管理', group: '产品与服务' },
  { path: paths.recharge, name: '充值中心', group: '财务管理' },
  { path: paths.transactions, name: '资金明细', group: '财务管理' },
  { path: paths.orders, name: '订单管理', group: '财务管理' },
  { path: paths.refunds, name: '退款管理', group: '财务管理' },
  { path: paths.invoices, name: '发票管理', group: '财务管理' },
  { path: paths.coupons, name: '优惠券', group: '财务管理' },
  { path: paths.security, name: '安全设置', group: '账户管理' },
  { path: paths.verification, name: '实名认证', group: '账户管理' },
  { path: paths.loginHistory, name: '登录记录', group: '账户管理' },
  { path: paths.tickets, name: '工单管理', group: '服务与帮助' },
]

/** 与 PC 共用首发范围，工单入口保留。 */
export const memberLinks = registeredLinks.filter(item => !isDeferredMemberPath(item.path))
