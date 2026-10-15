import type { CenterNotification } from '../components/NavBar/notificationCenterData';

/** 仅开发环境加载，所有编号和内容均为虚构，不调用或写入后台。 */
export const notificationPreviewData: CenterNotification[] = [
  ...Array.from({ length: 16 }, (_, index) => ({
    id: `demo-order-${index + 1}`,
    category: 'trade',
    topic: 'new-order',
    title: `新订单：DEMO20261005${String(index + 1).padStart(4, '0')}，金额：¥${[299, 128, 599, 89][index % 4].toFixed(2)}`,
    detail: `演示会员 ${index + 1} 购买了${index % 2 ? '香港精品云服务器' : '弹性云服务器'}，数量 ${index % 3 + 1} 台，付款后进入资源交付流程。本条为演示消息。`,
    time: `2026-10-05 ${String(15 - Math.floor(index / 4)).padStart(2, '0')}:${String(58 - index * 3).padStart(2, '0')}:00`,
    unread: index < 6,
    demo: true,
  })),
  ...[
    ['trade', 'paid-order', '订单已付款，金额：¥299.00', '演示订单 DEMO202610050001 已通过余额支付，等待资源交付。'],
    ['trade', 'renewal', '自动续费余额不足', '演示会员的云服务器即将到期，当前可用余额不足以续费。'],
    ['business', 'delivery', '云服务器交付完成', '演示实例 DEMO-VM-001 已开通，请在业务管理中查看实例信息。'],
    ['business', 'expiry', '云服务器将在 3 天后到期', '演示实例 DEMO-VM-002 即将到期，请及时提醒会员续费。'],
    ['service', 'ticket', '您有一条待回复工单', '演示工单 DEMO-TICKET-001：会员咨询云服务器网络连接问题。'],
    ['service', 'authentication', '新增企业实名认证申请', '演示企业会员已提交认证资料，等待审核。'],
    ['finance', 'recharge', '会员充值到账：¥500.00', '演示充值 DEMO-RECHARGE-001 已到账。'],
    ['finance', 'refund', '新增退款申请：¥89.00', '演示会员申请退订一台云服务器，申请金额 ¥89.00，等待审核。'],
    ['finance', 'invoice', '新增发票申请：¥299.00', '演示会员提交了一笔发票申请，等待财务审核。'],
    ['system', 'announcement', '消息中心使用说明', '您可以按分类查看消息，展开阅读详情，或将消息标记为已读。当前业务消息均为演示数据。'],
  ].map(([category, topic, title, detail], index) => ({
    id: `demo-message-${index + 1}`,
    category, topic, title, detail,
    time: `2026-10-05 10:${String(50 - index * 3).padStart(2, '0')}:00`,
    unread: true,
    demo: true,
  })),
];
