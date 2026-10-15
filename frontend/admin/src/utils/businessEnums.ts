import type { FinanceResource } from '../api/financeApi'
import { enumLabels, type CategoryMap, type StatusMap } from './tableEnum'

/** 订单生命周期；待付款需要处理，退款结束与订单完成均为已完成结果。 */
export const ORDER_STATUSES: StatusMap = {
  pending_payment: { text: '待支付', status: 'warning' },
  paid: { text: '已支付', status: 'success' },
  fulfilling: { text: '交付中', status: 'processing' },
  completed: { text: '已完成', status: 'success' },
  cancelled: { text: '已取消', status: 'default' },
  failed: { text: '失败', status: 'error' },
  refunding: { text: '退款中', status: 'processing' },
  refunded: { text: '已退款', status: 'success' },
}
/** 订单支付结果，部分支付及部分退款需要关注。 */
export const PAYMENT_STATUSES: StatusMap = {
  unpaid: { text: '未支付', status: 'default' },
  partial: { text: '部分支付', status: 'warning' },
  paid: { text: '已支付', status: 'success' },
  refunded: { text: '已退款', status: 'success' },
  partial_refund: { text: '部分退款', status: 'warning' },
}
/** 订单类型以纯文本展示，供列表、详情和筛选共用。 */
export const ORDER_TYPES: CategoryMap = {
  purchase: { text: '购买' }, renewal: { text: '续费' },
  upgrade: { text: '升级' }, trial: { text: '试用' },
}
/** 已有业务产品的中文分类，未知扩展产品保留原始值。 */
export const PRODUCT_TYPES: CategoryMap = {
  cloud_server: { text: '云服务器' },
  physical_server: { text: '物理服务器' },
  server_colocation: { text: '服务器托管' },
}
/** 服务实例生命周期。 */
export const SERVICE_STATUSES: StatusMap = {
  provisioning: { text: '开通中', status: 'processing' }, active: { text: '已开通', status: 'success' },
  suspended: { text: '已暂停', status: 'warning' }, expired: { text: '已到期', status: 'warning' },
  terminated: { text: '已终止', status: 'default' }, failed: { text: '开通失败', status: 'error' },
}
/** 工单处理中才使用 processing，等待会员按中性等待展示。 */
export const TICKET_STATUSES: StatusMap = {
  open: { text: '待处理', status: 'warning' }, processing: { text: '处理中', status: 'processing' },
  waiting_member: { text: '等待会员', status: 'default' }, closed: { text: '已关闭', status: 'default' },
}
/** 工单优先级以文字展示，保持每个表格只有一列流程徽标。 */
export const TICKET_PRIORITIES: CategoryMap = {
  low: { text: '低' }, normal: { text: '普通' },
  high: { text: '高' }, urgent: { text: '紧急' },
}
/** 实名认证类型。 */
export const AUTHENTICATION_TYPES: CategoryMap = {
  personal: { text: '个人认证' }, enterprise: { text: '企业认证' },
}
/** 实名审核状态。 */
export const AUTHENTICATION_STATUSES: StatusMap = {
  pending: { text: '待审核', status: 'warning' }, approved: { text: '已通过', status: 'success' },
  rejected: { text: '已拒绝', status: 'error' },
}
/** 审计结果中的 0 为失败，与启用 / 禁用无关。 */
export const AUDIT_STATUSES: StatusMap = {
  '0': { text: '失败', status: 'error' }, '1': { text: '成功', status: 'success' },
}
/** 内容和销售方案的发布情况。 */
export const PUBLICATION_STATUSES: StatusMap = {
  draft: { text: '草稿', status: 'default' }, published: { text: '已发布', status: 'success' },
}
/** 安装、升级任务；待核实重启与需要恢复不标记为执行成功。 */
export const INSTALLATION_STATUSES: StatusMap = {
  pending: { text: '等待执行', status: 'default' }, running: { text: '执行中', status: 'processing' },
  awaiting_restart: { text: '核实重启', status: 'warning' }, succeeded: { text: '成功', status: 'success' },
  failed: { text: '失败', status: 'error' }, recovery_required: { text: '需要恢复', status: 'error' },
}
/** 应用类型。 */
export const APPLICATION_TYPES: CategoryMap = {
  plugin: { text: '插件' }, theme: { text: '主题' },
}
/** 应用包含安装与停止接单等生命周期，区别于二元启用状态。 */
export const APPLICATION_STATUSES: StatusMap = {
  not_installed: { text: '尚未安装', status: 'default' }, installed: { text: '已安装', status: 'success' },
  enabled: { text: '已启用', status: 'success' }, disabled: { text: '已停用', status: 'default' },
  draining: { text: '停止接单', status: 'warning' }, uninstalled: { text: '已卸载', status: 'default' },
}

const reviewStatuses: StatusMap = {
  pending_review: { text: '待审核', status: 'warning' }, approved: { text: '已通过', status: 'success' },
  rejected: { text: '已拒绝', status: 'error' }, processing: { text: '处理中', status: 'processing' },
  completed: { text: '已完成', status: 'success' }, failed: { text: '失败', status: 'error' },
  cancelled: { text: '已取消', status: 'default' },
}
/** 财务记录按单据含义解释同名状态，仅影响展示，不开放交易操作。 */
export const FINANCE_STATUSES: Readonly<Partial<Record<FinanceResource, StatusMap>>> = {
  recharges: { pending: { text: '待支付', status: 'warning' }, paid: { text: '已支付', status: 'success' }, credited: { text: '已入账', status: 'success' }, failed: { text: '失败', status: 'error' }, cancelled: { text: '已取消', status: 'default' } },
  payments: { ...PAYMENT_STATUSES, pending: { text: '待支付', status: 'warning' }, processing: { text: '支付中', status: 'processing' }, failed: { text: '失败', status: 'error' }, cancelled: { text: '已取消', status: 'default' } },
  refunds: reviewStatuses,
  invoices: { ...reviewStatuses, issued: { text: '已开票', status: 'success' }, red: { text: '已红冲', status: 'default' } },
  withdrawals: { ...reviewStatuses, paid: { text: '已打款', status: 'success' } },
}
/** 财务用途及来源共用中文分类。 */
export const FINANCE_SOURCE_TYPES: CategoryMap = {
  order: { text: '订单' }, order_unit: { text: '订单实例' },
  recharge: { text: '充值' }, refund: { text: '退款' },
  recharge_refund: { text: '充值退款' }, withdrawal: { text: '提现' },
}
/** 财务分类只覆盖已知渠道/类型；插件自定义标识保留原文。 */
export const FINANCE_CATEGORIES: Readonly<Record<string, CategoryMap>> = {
  source_type: FINANCE_SOURCE_TYPES,
  purpose: FINANCE_SOURCE_TYPES,
  channel: { offline: { text: '线下收款' }, wechat: { text: '微信支付' }, alipay: { text: '支付宝' }, wallet: { text: '余额' } },
  destination: { wallet: { text: '余额' }, original: { text: '原支付渠道' } },
  invoice_type: { electronic: { text: '电子发票' }, paper: { text: '纸质发票' } },
  entry_type: { withdrawal_freeze: { text: '提现冻结' }, withdrawal_unfreeze: { text: '提现解冻' }, withdrawal_payout: { text: '提现打款' }, credit: { text: '入账' }, spend: { text: '消费' }, recharge_credit: { text: '充值入账' }, order_payment: { text: '订单支付' }, refund_credit: { text: '退款入账' } },
}

/** 已知产品的名称供实例标题共用。 */
export const PRODUCT_TYPE_LABELS = enumLabels(PRODUCT_TYPES)
