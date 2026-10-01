import type { FinanceRecord, FinanceResource } from '../api/financeApi.ts'
import type { OrderDetail } from '../api/orderApi.ts'

// 只用于开发环境空库预览；编号和会员信息均为虚构内容，不写入服务端。
const orders: OrderDetail[] = [
  {
    id: -9001, order_no: 'DEMO-ORD-20260927-001', member_id: 101,
    member_mobile_snapshot: '13800000001', member_nickname_snapshot: '演示客户甲',
    order_type: 'purchase', status: 'completed', payment_status: 'paid', currency: 'CNY',
    subtotal_minor: 139800, discount_minor: 10000, payable_minor: 129800, paid_minor: 129800,
    refunded_minor: 0, remark: '两台云服务器，演示订单', paid_at: '2026-09-27 09:36:12',
    completed_at: '2026-09-27 09:42:08', cancelled_at: null,
    created_at: '2026-09-27 09:30:15', updated_at: '2026-09-27 09:42:08',
    items: [{ id: -9101, line_no: 1, product_type: 'cloud_server', product_type_version: '1.0',
      sales_version: 'demo-v1', product_name: '云服务器 2核4G', quantity: 2,
      unit_price_minor: 69900, total_minor: 139800 }],
  },
  {
    id: -9002, order_no: 'DEMO-ORD-20260927-002', member_id: 102,
    member_mobile_snapshot: '13800000002', member_nickname_snapshot: '演示客户乙',
    order_type: 'renewal', status: 'pending_payment', payment_status: 'unpaid', currency: 'CNY',
    subtotal_minor: 39900, discount_minor: 0, payable_minor: 39900, paid_minor: 0,
    refunded_minor: 0, remark: '续费待支付', paid_at: null, completed_at: null, cancelled_at: null,
    created_at: '2026-09-27 10:15:32', updated_at: '2026-09-27 10:15:32',
    items: [{ id: -9102, line_no: 1, product_type: 'cloud_server', product_type_version: '1.0',
      sales_version: 'demo-v1', product_name: '云服务器 2核2G 续费', quantity: 1,
      unit_price_minor: 39900, total_minor: 39900 }],
  },
  {
    id: -9003, order_no: 'DEMO-ORD-20260926-003', member_id: 103,
    member_mobile_snapshot: '13800000003', member_nickname_snapshot: '演示客户丙',
    order_type: 'purchase', status: 'completed', payment_status: 'partial_refund', currency: 'CNY',
    subtotal_minor: 99800, discount_minor: 0, payable_minor: 99800, paid_minor: 99800,
    refunded_minor: 49900, remark: '其中一台已退款', paid_at: '2026-09-26 14:06:11',
    completed_at: '2026-09-26 14:17:35', cancelled_at: null,
    created_at: '2026-09-26 14:03:07', updated_at: '2026-09-27 08:20:00',
    items: [{ id: -9103, line_no: 1, product_type: 'cloud_server', product_type_version: '1.0',
      sales_version: 'demo-v1', product_name: '云服务器 4核8G', quantity: 2,
      unit_price_minor: 49900, total_minor: 99800 }],
  },
]

const finance: Record<FinanceResource, FinanceRecord[]> = {
  recharges: [
    { id: -8001, recharge_no: 'DEMO-RCH-001', member_id: 101, amount_minor: 200000, currency: 'CNY', channel: 'wechat', status: 'credited', paid_at: '2026-09-27 09:18:00', created_at: '2026-09-27 09:16:42' },
    { id: -8002, recharge_no: 'DEMO-RCH-002', member_id: 102, amount_minor: 50000, currency: 'CNY', channel: 'alipay', status: 'pending', paid_at: null, created_at: '2026-09-27 10:12:23' },
  ],
  payments: [
    { id: -7001, payment_no: 'DEMO-PAY-001', member_id: 101, purpose: 'recharge', purpose_id: -8001, channel: 'wechat', channel_trade_no: 'DEMO-WX-001', amount_minor: 200000, currency: 'CNY', status: 'paid', paid_at: '2026-09-27 09:18:00', created_at: '2026-09-27 09:16:43' },
    { id: -7002, payment_no: 'DEMO-PAY-002', member_id: 101, purpose: 'order', purpose_id: -9001, channel: 'wallet', channel_trade_no: null, amount_minor: 129800, currency: 'CNY', status: 'paid', paid_at: '2026-09-27 09:36:12', created_at: '2026-09-27 09:36:12' },
    { id: -7003, payment_no: 'DEMO-PAY-003', member_id: 102, purpose: 'recharge', purpose_id: -8002, channel: 'alipay', channel_trade_no: null, amount_minor: 50000, currency: 'CNY', status: 'pending', paid_at: null, created_at: '2026-09-27 10:12:24' },
  ],
  transactions: [
    { id: -6001, entry_no: 'DEMO-LED-001', member_id: 101, entry_type: 'recharge_credit', source_type: 'recharge', source_id: -8001, amount_minor: 200000, available_after_minor: 200000, created_at: '2026-09-27 09:18:01' },
    { id: -6002, entry_no: 'DEMO-LED-002', member_id: 101, entry_type: 'order_payment', source_type: 'order', source_id: -9001, amount_minor: -129800, available_after_minor: 70200, created_at: '2026-09-27 09:36:12' },
    { id: -6003, entry_no: 'DEMO-LED-003', member_id: 103, entry_type: 'refund_credit', source_type: 'refund', source_id: -5001, amount_minor: 49900, available_after_minor: 49900, created_at: '2026-09-27 08:20:00' },
  ],
  refunds: [
    { id: -5001, refund_no: 'DEMO-RFD-001', member_id: 103, source_type: 'order_unit', source_id: -9103, payment_id: null, amount_minor: 49900, currency: 'CNY', destination: 'wallet', status: 'completed', reason: '演示：单台资源退款', reviewer_id: 1, reviewed_at: '2026-09-27 08:10:00', completed_at: '2026-09-27 08:20:00', created_at: '2026-09-27 08:00:00' },
    { id: -5002, refund_no: 'DEMO-RFD-002', member_id: 101, source_type: 'order_unit', source_id: -9101, payment_id: -7002, amount_minor: 64900, currency: 'CNY', destination: 'wallet', status: 'pending_review', reason: '演示：申请退款', reviewer_id: null, reviewed_at: null, completed_at: null, created_at: '2026-09-27 11:05:26' },
  ],
  invoices: [
    { id: -4001, invoice_no: 'DEMO-INV-001', member_id: 101, order_id: -9001, red_of_id: null, amount_minor: 129800, currency: 'CNY', invoice_type: 'electronic', title: '演示企业科技有限公司', status: 'issued', external_number: 'DEMO-FP-001', reviewer_id: 1, reviewed_at: '2026-09-27 10:20:00', issued_at: '2026-09-27 10:35:00', created_at: '2026-09-27 10:02:11' },
    { id: -4002, invoice_no: 'DEMO-INV-002', member_id: 103, order_id: -9003, red_of_id: null, amount_minor: 49900, currency: 'CNY', invoice_type: 'electronic', title: '演示客户丙', status: 'pending_review', external_number: null, reviewer_id: null, reviewed_at: null, issued_at: null, created_at: '2026-09-27 11:11:00' },
  ],
  withdrawals: [
    { id: -3001, withdrawal_no: 'DEMO-WDR-001', member_id: 101, source_type: 'recharge_refund', source_id: -8001, amount_minor: 10000, currency: 'CNY', status: 'pending_review', reason: '演示：原路退回不可用', reviewer_id: null, reviewed_at: null, payout_reference: null, payout_at: null, created_at: '2026-09-27 11:30:00' },
    { id: -3002, withdrawal_no: 'DEMO-WDR-002', member_id: 101, source_type: 'recharge_refund', source_id: -8001, amount_minor: 5000, currency: 'CNY', status: 'paid', reason: '演示：线下打款', reviewer_id: 1, reviewed_at: '2026-09-26 16:00:00', payout_reference: 'DEMO-BANK-001', payout_at: '2026-09-27 09:00:00', created_at: '2026-09-26 15:00:00' },
  ],
}

/** 获取订单演示列表，支持与真实页面相同的筛选及分页。 */
export function previewOrders(keyword: string, status: string | undefined, page: number, pageSize: number) {
  const query = keyword.toLowerCase()
  const filtered = orders.filter((row) => (!status || row.status === status)
    && (!query || [row.order_no, row.member_mobile_snapshot, row.member_nickname_snapshot]
      .some((value) => value.toLowerCase().includes(query))))
  return { data: filtered.slice((page - 1) * pageSize, page * pageSize), total: filtered.length }
}

/** 获取指定演示订单及订单项。 */
export function previewOrder(id: number): OrderDetail | null {
  return orders.find((row) => row.id === id) ?? null
}

/** 获取指定财务资源的演示列表。 */
export function previewFinanceRecords(resource: FinanceResource, page: number, pageSize: number) {
  const rows = finance[resource]
  return { data: rows.slice((page - 1) * pageSize, page * pageSize), total: rows.length }
}

/** 获取指定财务演示单据。 */
export function previewFinanceRecord(resource: FinanceResource, id: number): FinanceRecord | null {
  return finance[resource].find((row) => row.id === id) ?? null
}
