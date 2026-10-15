/** 消息中心展示数据；演示消息不承载业务操作。 */
export interface CenterNotification {
  id: string;
  category: string;
  topic: string;
  title: string;
  detail: string;
  time: string;
  unread: boolean;
  demo: boolean;
}

/** 消息主题对应已有后台页面；演示消息只导航到页面，不编造业务记录 ID。 */
export const notificationDestinations: Record<string, string> = {
  'new-order': '/orders',
  'paid-order': '/orders',
  renewal: '/services/cloud-servers',
  delivery: '/services/cloud-servers',
  expiry: '/services/cloud-servers',
  ticket: '/support/tickets',
  authentication: '/real-name-authentications',
  recharge: '/recharges',
  refund: '/refunds',
  invoice: '/invoices',
  update: '/maintenance',
  announcement: '/content/news',
};

/** 两级分类与云业务场景对应，版本更新沿用本机已验证摘要。 */
export const notificationCategories = [
  { id: 'trade', label: '交易消息', topics: [
    { id: 'new-order', label: '新订单' },
    { id: 'paid-order', label: '已付款订单' },
    { id: 'renewal', label: '续费提醒' },
  ] },
  { id: 'business', label: '业务消息', topics: [
    { id: 'delivery', label: '资源交付' },
    { id: 'expiry', label: '到期提醒' },
  ] },
  { id: 'service', label: '服务消息', topics: [
    { id: 'ticket', label: '工单消息' },
    { id: 'authentication', label: '实名认证' },
  ] },
  { id: 'finance', label: '财务消息', topics: [
    { id: 'recharge', label: '充值到账' },
    { id: 'refund', label: '退款申请' },
    { id: 'invoice', label: '发票申请' },
  ] },
  { id: 'system', label: '系统消息', topics: [
    { id: 'update', label: '版本更新' },
    { id: 'announcement', label: '系统公告' },
  ] },
];
