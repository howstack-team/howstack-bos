/** 社区版 1.0 固定交付范围；不是用户授权或可在线开关的商业权益。 */
export const communityRelease = {
  themePackages: false,
  commercialDelivery: false,
  newInvoices: false,
  newWithdrawals: true,
} as const

/** 只隐藏日常导航，授权历史查询与收尾页面仍保留。 */
export const hiddenAdminMenuKeys: ReadonlySet<string> = new Set([
  'customers/member-levels', 'finance/invoices',
  'services/physical-servers', 'services/server-colocations', 'system/application-center',
])

/** 暂缓会员页面不注册路由；资金明细 R02 已接入，工单属于 R03 必补且保留。 */
const deferredMemberPaths = new Set([
  '/services/physical-servers', '/services/colocation', '/services/renewals',
  '/finance/refunds', '/finance/invoices', '/finance/coupons',
  '/account/security', '/account/verification', '/account/login-history',
])

/** 同时用于导航、快捷入口和直达路径判断，忽略尾斜线、查询及片段。 */
export function isDeferredMemberPath(value: string): boolean {
  const path = value.split(/[?#]/u)[0].replace(/\/+$/u, '') || '/'
  return Array.from(deferredMemberPaths).some(base => path === base || path.startsWith(`${base}/`))
}
