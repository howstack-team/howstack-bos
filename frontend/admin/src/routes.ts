import { useMemo } from 'react';
import auth, { type AuthParams, type UserPermission } from './utils/authentication.ts';

export type IRoute = AuthParams & {
  name: string;
  key: string;
  path?: string;
  permission?: string;
  permissionDependencies?: string[];
  operationPermissions?: string[];
  breadcrumb?: boolean;
  ignore?: boolean;
  external?: boolean;
  children?: IRoute[];
  component?: any;
};

/** 沿用 Arco Design Pro 的 routes.ts 树形路由与 requiredPermissions 配置。 */
export const routes: IRoute[] = [
  { name: '总览', key: 'dashboard', children: [
    { name: '工作台', key: 'dashboard/workplace', path: '/dashboard', permission: 'admin.page.dashboard.view', requiredPermissions: [{ resource: 'admin.page.dashboard.view', actions: ['read'] }] },
    { name: '经营分析', key: 'dashboard/analysis', path: '/analysis', permission: 'admin.page.analysis.view', requiredPermissions: [{ resource: 'admin.page.analysis.view', actions: ['read'] }] },
  ] },
  { name: '客户管理', key: 'customers', children: [
    { name: '会员管理', key: 'customers/members', path: '/members', permission: 'admin.members.view', requiredPermissions: [{ resource: 'admin.members.view', actions: ['read'] }] },
    { name: '会员分组', key: 'customers/member-groups', path: '/member-groups', permission: 'admin.member-groups.view', requiredPermissions: [{ resource: 'admin.member-groups.view', actions: ['read'] }] },
    { name: '会员等级', key: 'customers/member-levels', path: '/member-levels', permission: 'admin.member-levels.view', requiredPermissions: [{ resource: 'admin.member-levels.view', actions: ['read'] }] },
    { name: '实名认证', key: 'customers/real-name-authentications', path: '/real-name-authentications', permission: 'admin.real-name-authentications.view', requiredPermissions: [{ resource: 'admin.real-name-authentications.view', actions: ['read'] }] },
  ] },
  { name: '财务管理', key: 'finance', children: [
    { name: '订单管理', key: 'finance/orders', path: '/orders', permission: 'admin.page.order-list.view', requiredPermissions: [{ resource: 'admin.page.order-list.view', actions: ['read'] }] },
    { name: '充值记录', key: 'finance/recharges', path: '/recharges', permission: 'admin.finance.recharges.view', requiredPermissions: [{ resource: 'admin.finance.recharges.view', actions: ['read'] }] },
    { name: '支付记录', key: 'finance/payments', path: '/payments', permission: 'admin.finance.payments.view', requiredPermissions: [{ resource: 'admin.finance.payments.view', actions: ['read'] }] },
    { name: '资金流水', key: 'finance/transactions', path: '/transactions', permission: 'admin.finance.transactions.view', requiredPermissions: [{ resource: 'admin.finance.transactions.view', actions: ['read'] }] },
    { name: '退款记录', key: 'finance/refunds', path: '/refunds', permission: 'admin.finance.refunds.view', requiredPermissions: [{ resource: 'admin.finance.refunds.view', actions: ['read'] }] },
    { name: '发票记录', key: 'finance/invoices', path: '/invoices', permission: 'admin.finance.invoices.view', requiredPermissions: [{ resource: 'admin.finance.invoices.view', actions: ['read'] }] },
    { name: '提现记录', key: 'finance/withdrawals', path: '/withdrawals', permission: 'admin.finance.withdrawals.view', requiredPermissions: [{ resource: 'admin.finance.withdrawals.view', actions: ['read'] }] },
  ] },
  { name: '产品管理', key: 'products', children: [
    { name: '云服务器', key: 'products/cloud-servers', children: [
      { name: '地域和可用区', key: 'products/cloud-servers/deployment', path: '/products/cloud-servers/deployment', permission: 'admin.cloud-configuration.deployment.view', permissionDependencies: ['admin.compute-resources.view'], operationPermissions: ['admin.compute-resources.create', 'admin.compute-resources.update'], requiredPermissions: [{ resource: 'admin.cloud-configuration.deployment.view', actions: ['read'] }, { resource: 'admin.compute-resources.view', actions: ['read'] }] },
      { name: '规格与套餐', key: 'products/cloud-servers/specification', path: '/products/cloud-servers/specification', permission: 'admin.cloud-configuration.specification.view', permissionDependencies: ['admin.compute-resources.view'], operationPermissions: ['admin.compute-resources.create', 'admin.compute-resources.update'], requiredPermissions: [{ resource: 'admin.cloud-configuration.specification.view', actions: ['read'] }, { resource: 'admin.compute-resources.view', actions: ['read'] }] },
      { name: '线路和网络', key: 'products/cloud-servers/network', path: '/products/cloud-servers/network', permission: 'admin.cloud-configuration.network.view', permissionDependencies: ['admin.compute-resources.view'], operationPermissions: ['admin.compute-resources.create', 'admin.compute-resources.update'], requiredPermissions: [{ resource: 'admin.cloud-configuration.network.view', actions: ['read'] }, { resource: 'admin.compute-resources.view', actions: ['read'] }] },
      { name: '镜像和存储', key: 'products/cloud-servers/storage', path: '/products/cloud-servers/storage', permission: 'admin.cloud-configuration.storage.view', permissionDependencies: ['admin.compute-resources.view'], operationPermissions: ['admin.compute-resources.create', 'admin.compute-resources.update'], requiredPermissions: [{ resource: 'admin.cloud-configuration.storage.view', actions: ['read'] }, { resource: 'admin.compute-resources.view', actions: ['read'] }] },
      { name: '购买与计费', key: 'products/cloud-servers/purchase', path: '/products/cloud-servers/purchase', permission: 'admin.cloud-configuration.purchase.view', permissionDependencies: ['admin.cloud-products.view'], operationPermissions: ['admin.cloud-products.create', 'admin.cloud-products.update', 'admin.cloud-products.preview'], requiredPermissions: [{ resource: 'admin.cloud-configuration.purchase.view', actions: ['read'] }, { resource: 'admin.cloud-products.view', actions: ['read'] }] },
    ] },
    { name: '资源供应商', key: 'products/provider-connections', path: '/providers/connections', permission: 'admin.provider-connections.view', requiredPermissions: [{ resource: 'admin.provider-connections.view', actions: ['read'] }] },
  ] },
  { name: '业务管理', key: 'services', children: [
    { name: '云服务器', key: 'services/cloud-servers', path: '/services/cloud-servers', permission: 'admin.services.cloud-servers.view', requiredPermissions: [{ resource: 'admin.services.cloud-servers.view', actions: ['read'] }] },
    { name: '物理服务器', key: 'services/physical-servers', path: '/services/physical-servers', permission: 'admin.services.physical-servers.view', requiredPermissions: [{ resource: 'admin.services.physical-servers.view', actions: ['read'] }] },
    { name: '服务器托管', key: 'services/server-colocations', path: '/services/server-colocations', permission: 'admin.services.server-colocations.view', requiredPermissions: [{ resource: 'admin.services.server-colocations.view', actions: ['read'] }] },
  ] },
  { name: '服务管理', key: 'support', children: [
    { name: '工单管理', key: 'support/ticket-management', children: [
      { name: '工单类型', key: 'support/ticket-types', path: '/support/ticket-types', permission: 'admin.tickets.types.view', requiredPermissions: [{ resource: 'admin.tickets.types.view', actions: ['read'] }] },
      { name: '工单列表', key: 'support/tickets', path: '/support/tickets', permission: 'admin.tickets.list.view', requiredPermissions: [{ resource: 'admin.tickets.list.view', actions: ['read'] }] },
      { name: '快捷回复', key: 'support/quick-replies', path: '/support/quick-replies', permission: 'admin.tickets.quick-replies.view', requiredPermissions: [{ resource: 'admin.tickets.quick-replies.view', actions: ['read'] }] },
    ] },
  ] },
  { name: '内容管理', key: 'content', children: [
    { name: '文件管理', key: 'content/files', path: '/content/files', permission: 'admin.files.view', requiredPermissions: [{ resource: 'admin.files.view', actions: ['read'] }] },
    { name: '官网管理', key: 'content/website-management', children: [
      { name: '基础设置', key: 'content/website-base', path: '/website/base', permission: 'admin.website.base.view', requiredPermissions: [{ resource: 'admin.website.base.view', actions: ['read'] }] },
      { name: '导航管理', key: 'content/website-navigation', path: '/website/navigation', permission: 'admin.website.navigation.view', requiredPermissions: [{ resource: 'admin.website.navigation.view', actions: ['read'] }] },
      { name: '页面 SEO', key: 'content/website-seo', path: '/website/seo', permission: 'admin.website.seo.view', requiredPermissions: [{ resource: 'admin.website.seo.view', actions: ['read'] }] },
    ] },
    { name: '主题管理', key: 'content/themes', path: '/themes', permission: 'admin.page.website.view', requiredPermissions: [{ resource: 'admin.page.website.view', actions: ['read'] }] },
    { name: '帮助文档', key: 'content/help-management', children: [
      { name: '文档列表', key: 'content/help', path: '/content/help', permission: 'admin.content.help-entries.view', requiredPermissions: [{ resource: 'admin.content.help-entries.view', actions: ['read'] }] },
      { name: '文档分类', key: 'content/help-categories', path: '/content/help-categories', permission: 'admin.content.help-categories.view', requiredPermissions: [{ resource: 'admin.content.help-categories.view', actions: ['read'] }] },
    ] },
    { name: '新闻公告', key: 'content/news-management', children: [
      { name: '公告列表', key: 'content/news', path: '/content/news', permission: 'admin.content.news-entries.view', requiredPermissions: [{ resource: 'admin.content.news-entries.view', actions: ['read'] }] },
      { name: '公告分类', key: 'content/news-categories', path: '/content/news-categories', permission: 'admin.content.news-categories.view', requiredPermissions: [{ resource: 'admin.content.news-categories.view', actions: ['read'] }] },
    ] },
  ] },
  { name: '系统管理', key: 'system', children: [
    { name: '个人设置', key: 'system/profile', path: '/profile', ignore: true, permission: 'admin.profile.view', requiredPermissions: [{ resource: 'admin.profile.view', actions: ['read'] }] },
    { name: 'API 文档', key: 'system/api-docs', ignore: true, external: true, permission: 'admin.api-docs.view', requiredPermissions: [{ resource: 'admin.api-docs.view', actions: ['read'] }] },
    { name: '组织与权限', key: 'system/organization', children: [
      { name: '系统用户', key: 'system/administrators', path: '/administrators', permission: 'admin.administrators.view', requiredPermissions: [{ resource: 'admin.administrators.view', actions: ['read'] }] },
      { name: '部门管理', key: 'system/departments', path: '/departments', permission: 'admin.departments.view', requiredPermissions: [{ resource: 'admin.departments.view', actions: ['read'] }] },
      { name: '角色权限', key: 'system/roles', path: '/roles', permission: 'admin.roles.view', requiredPermissions: [{ resource: 'admin.roles.view', actions: ['read'] }] },
    ] },
    { name: '系统设置', key: 'system/settings', path: '/settings', permission: 'admin.page.settings.view', requiredPermissions: [{ resource: 'admin.page.settings.view', actions: ['read'] }] },
    { name: '消息配置', key: 'system/message-configurations', path: '/message-configurations', permission: 'admin.message-configurations.view', requiredPermissions: [{ resource: 'admin.message-configurations.view', actions: ['read'] }] },
    { name: '通道管理', key: 'system/channels', children: [
      { name: '支付通道', key: 'system/channels/payment', path: '/channels/payment', permission: 'admin.channels.payment.view', requiredPermissions: [{ resource: 'admin.channels.payment.view', actions: ['read'] }] },
      { name: '短信通道', key: 'system/channels/sms', path: '/channels/sms', permission: 'admin.channels.sms.view', requiredPermissions: [{ resource: 'admin.channels.sms.view', actions: ['read'] }] },
      { name: '邮件通道', key: 'system/channels/mail', path: '/channels/mail', permission: 'admin.channels.mail.view', requiredPermissions: [{ resource: 'admin.channels.mail.view', actions: ['read'] }] },
      { name: '实名认证', key: 'system/channels/identity', path: '/channels/identity', permission: 'admin.channels.identity.view', requiredPermissions: [{ resource: 'admin.channels.identity.view', actions: ['read'] }] },
      { name: '文件存储', key: 'system/channels/storage', path: '/channels/storage', permission: 'admin.channels.storage.view', requiredPermissions: [{ resource: 'admin.channels.storage.view', actions: ['read'] }] },
      { name: '第三方登录', key: 'system/channels/oauth', path: '/channels/oauth', permission: 'admin.channels.oauth.view', requiredPermissions: [{ resource: 'admin.channels.oauth.view', actions: ['read'] }] },
      { name: '人机验证', key: 'system/channels/captcha', path: '/channels/captcha', permission: 'admin.channels.captcha.view', requiredPermissions: [{ resource: 'admin.channels.captcha.view', actions: ['read'] }] },
    ] },
    { name: '授权与更新', key: 'system/maintenance', path: '/maintenance', oneOfPerm: true, requiredPermissions: [{ resource: 'admin.license.view', actions: ['read'] }, { resource: 'admin.updates.view', actions: ['read'] }] },
    { name: '应用中心', key: 'system/application-center', path: '/application-center', permission: 'admin.app-center.view', requiredPermissions: [{ resource: 'admin.app-center.view', actions: ['read'] }] },
  ] },
  { name: '日志审计', key: 'audit', children: [
    { name: '消息日志', key: 'audit/messages', path: '/audit/messages', permission: 'admin.message-logs.view', requiredPermissions: [{ resource: 'admin.message-logs.view', actions: ['read'] }] },
    { name: '操作日志', key: 'audit/operations', path: '/audit/operations', permission: 'admin.page.operation-logs.view', requiredPermissions: [{ resource: 'admin.page.operation-logs.view', actions: ['read'] }] },
    { name: '登录日志', key: 'audit/logins', path: '/audit/logins', permission: 'admin.page.login-logs.view', requiredPermissions: [{ resource: 'admin.page.login-logs.view', actions: ['read'] }] },
  ] },
];

/** Webman 权限码映射为 Arco Pro 的 resource/actions 权限对象。 */
export function toUserPermission(codes: string[]): UserPermission {
  return Object.fromEntries(codes.map((code) => [code, ['read']]));
}

function filterRoute(source: IRoute[], userPermission: UserPermission): IRoute[] {
  return source.flatMap((route) => {
    if (!auth(route, userPermission)) return [];
    if (!route.children) return [{ ...route }];
    const children = filterRoute(route.children, userPermission);
    return children.length ? [{ ...route, children }] : [];
  });
}

export default function useRoute(userPermission: UserPermission): [IRoute[], string] {
  const permissionRoute = useMemo(() => filterRoute(routes, userPermission), [userPermission]);
  const firstLeafPath = (items: IRoute[]): string => {
    for (const item of items) {
      if (item.ignore) continue;
      if (item.children?.length) {
        const path = firstLeafPath(item.children);
        if (path) return path;
      } else if (item.path) {
        return item.path;
      }
    }
    return '';
  };
  const defaultRoute = firstLeafPath(permissionRoute);
  return [permissionRoute, defaultRoute];
}
