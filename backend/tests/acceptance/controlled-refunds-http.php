<?php

declare(strict_types=1);

/** 本机受控退款真实HTTP验收；只创建并清理临时合成订单，不连接供应商或银行。 */
if (getenv('HOWSTACK_REFUND_HTTP_ACCEPTANCE') !== '1') exit('需显式启用HOWSTACK_REFUND_HTTP_ACCEPTANCE=1');
$base = dirname(__DIR__, 2);
require $base . '/vendor/autoload.php';
Dotenv\Dotenv::createUnsafeImmutable($base)->safeLoad();
support\App::loadAllConfig(['route']);
if (in_array(strtolower((string) getenv('APP_ENV')), ['production', 'prod'], true)
    || !in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1'], true)) exit('仅允许本机开发环境');

use app\modules\member\infrastructure\model\MemberModel;
use app\modules\admin\access\infrastructure\model\AdminUserModel;
use app\modules\admin\auth\contract\{AdminAccountRepositoryInterface, AdminAccessTokenServiceInterface};
use app\modules\finance\application\WalletLedgerService;
use app\modules\finance\domain\WalletOrderPayment;
use app\modules\order\infrastructure\model\{OrderModel, OrderItemModel, OrderItemUnitModel};
use app\modules\service_instance\infrastructure\model\{ProvisioningTaskModel, ServiceInstanceModel};
use app\modules\message_configuration\contract\MessageConfigurationRepositoryInterface;
use app\modules\message_configuration\domain\{MessageGroups, MessageTypeCatalog};
use support\{Container, Db};

// 不修改既有消息设置，拒绝在会发送外部充值通知的环境运行。
foreach (['customer', 'admin'] as $audience) {
    foreach (MessageTypeCatalog::all($audience) as $code => $type) {
        if ($code !== 'recharge_received') continue;
        $stored = Container::get(MessageConfigurationRepositoryInterface::class)->get($audience, $code);
        foreach (MessageGroups::fromStored($audience, $type, $stored['configurations']) as $group) {
            if (!$group['enabled']) continue;
            foreach ($group['configurations'] as $channel => $configuration) {
                if ($channel !== 'message' && ($configuration['enabled'] ?? false)) exit('充值外部通知须在专用测试环境关闭');
            }
        }
    }
}
function verifyRefund(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function callRefund(?string $cookie, string $path, ?array $body = null, string $origin = 'http://127.0.0.1:8989'): array
{
    $curl = curl_init('http://127.0.0.1:8989' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => $body === null ? 'GET' : 'POST',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . $origin, 'Cookie: ' . ($cookie ?? '')], CURLOPT_HEADER => true]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode((object) $body, JSON_THROW_ON_ERROR));
    try {
        $raw = curl_exec($curl); verifyRefund(is_string($raw), 'HTTP请求失败');
        $headers = substr($raw, 0, curl_getinfo($curl, CURLINFO_HEADER_SIZE));
        $data = json_decode(substr($raw, strlen($headers)), true, flags: JSON_THROW_ON_ERROR);
        verifyRefund(array_keys($data) === ['code', 'message', 'data', 'request_id'], '统一响应信封缺失');
        verifyRefund(stripos($headers, 'X-Request-Id:') !== false, '请求追踪头缺失');
        return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $data];
    } finally { curl_close($curl); }
}
function refundInput(array $values = []): array { return ['request_key' => bin2hex(random_bytes(16))] + $values; }
$admins = []; $member = null; $order = null; $marker = 'R04-' . bin2hex(random_bytes(6));
$path = '/api/admin/v1/finance';
try {
    $member = MemberModel::query()->create(['nickname' => $marker, 'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'status' => 1]);
    $cookies = [];
    foreach ([true, true, false] as $founder) {
        $admin = AdminUserModel::query()->create(['username' => 'refund_' . bin2hex(random_bytes(8)), 'nickname' => $marker,
            'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'status' => 1, 'is_founder' => $founder]);
        $admins[] = $admin;
        $actor = Container::get(AdminAccountRepositoryInterface::class)->findById($admin->id);
        $cookies[] = config('authentication.admin.cookie_name') . '=' . Container::get(AdminAccessTokenServiceInterface::class)->issue($actor)->value;
    }
    [$applicant, $reviewer, $ungranted] = $cookies;
    [$status] = callRefund(null, $path . '/withdrawals', []); verifyRefund($status === 401, '匿名不得申请资金退还');
    [$status, $emptyError] = callRefund($ungranted, $path . '/refunds', []); verifyRefund(in_array($status, [403, 422], true), '无权空请求应拒绝，实际HTTP=' . $status . ' ' . $emptyError['message']);
    $deposit = refundInput(['member_id' => $member->id, 'amount_minor' => 10000, 'external_reference' => $marker . '-BANK-IN']);
    [$status] = callRefund($ungranted, $path . '/recharges', $deposit); verifyRefund($status === 403, '无创建权限不得入账');
    [$status] = callRefund($applicant, $path . '/recharges', $deposit, 'https://untrusted.example'); verifyRefund($status === 403, '跨来源不得写入财务');
    [$status, $paid] = callRefund($applicant, $path . '/recharges', $deposit); verifyRefund($status === 200, '应能核实线下充值入账');
    $rechargeId = $paid['data']['id'];
    [$status, $repeat] = callRefund($applicant, $path . '/recharges', $deposit); verifyRefund($status === 200 && $repeat['data'] === $paid['data'], '充值重试不得重复入账');
    $order = OrderModel::query()->create(['order_no' => $marker, 'member_id' => $member->id, 'member_nickname_snapshot' => $marker,
        'order_type' => 'purchase', 'status' => 'failed', 'payment_status' => 'paid', 'currency' => 'CNY',
        'subtotal_minor' => 5000, 'payable_minor' => 5000, 'paid_minor' => 5000]);
    $item = OrderItemModel::query()->create(['order_id' => $order->id, 'line_no' => 1, 'product_type' => 'cloud_server',
        'product_type_version' => '1', 'sales_version' => 'fixture', 'product_name' => '验收合成实例', 'configuration_snapshot' => '{}',
        'quantity' => 5, 'unit_price_minor' => 1000, 'total_minor' => 5000, 'created_at' => date('Y-m-d H:i:s')]);
    Container::get(WalletLedgerService::class)->debitOrder(new WalletOrderPayment($order->id, $order->order_no, $member->id, 5000, 'CNY'));
    $units = [];
    for ($i = 1; $i <= 5; $i++) {
        $unit = OrderItemUnitModel::query()->create(['order_item_id' => $item->id, 'unit_index' => $i, 'allocated_minor' => 1000, 'created_at' => date('Y-m-d H:i:s')]);
        $service = ServiceInstanceModel::query()->create(['member_id' => $member->id, 'instance_no' => $marker . '-' . $i, 'display_name' => $marker,
            'product_type' => 'cloud_server', 'status' => $i === 1 ? 'active' : ($i === 3 ? 'provisioning' : 'terminated'),
            'origin_order_unit_id' => $unit->id, 'activated_at' => $i === 1 ? date('Y-m-d H:i:s') : null]);
        $unit->service_id = $service->id; $unit->save(); $units[] = $unit;
        ProvisioningTaskModel::query()->create(['order_id' => $order->id, 'order_unit_id' => $unit->id, 'service_id' => $service->id,
            'request_key' => $marker . '-task-' . $i, 'status' => $i === 1 ? 'succeeded' : ($i === 3 ? 'attention' : 'failed'),
            'external_id' => $i === 1 ? $marker . '-synthetic-resource' : null, 'error_code' => $i === 1 ? null : ($i === 3 ? 'provider_result_unknown' : 'provider_rejected'),
            'next_attempt_at' => date('Y-m-d H:i:s'), 'context' => []]);
    }
    $input = ['member_id' => $member->id, 'amount_minor' => 1000, 'reason' => '确认未交付'];
    foreach ([$units[0], $units[2]] as $unit) {
        [$status] = callRefund($applicant, $path . '/refunds', refundInput($input + ['source_id' => $unit->id])); verifyRefund($status === 409, '已交付及结果未知必须拒绝退款');
    }
    [$status] = callRefund($applicant, $path . '/refunds', refundInput(['amount_minor' => 999] + $input + ['source_id' => $units[1]->id])); verifyRefund($status === 409, '未交付仅接受单台全额退款');
    [$status, $created] = callRefund($applicant, $path . '/refunds', refundInput($input + ['source_id' => $units[1]->id])); verifyRefund($status === 200, '确认未创建的实例应可申请'); $refundId = $created['data']['id'];
    [$status] = callRefund($applicant, $path . '/refunds/' . $refundId . '/approve', refundInput(['review_reason' => '本人审批'])); verifyRefund($status === 409, '不能自己审批');
    [$status] = callRefund($reviewer, $path . '/refunds/' . $refundId . '/approve', refundInput()); verifyRefund($status === 422, '审批意见必填');
    [$status] = callRefund($reviewer, $path . '/refunds/' . $refundId . '/approve', refundInput(['review_reason' => '已核实供应未创建及单台实付'])); verifyRefund($status === 200, '独立管理员审核');
    $execute = refundInput();
    foreach ([1, 2] as $_) { [$status] = callRefund($applicant, $path . '/refunds/' . $refundId . '/execute', $execute); verifyRefund($status === 200, '执行及重复重放应成功且不重复入账'); }
    verifyRefund($order->fresh()->refunded_minor === 1000 && $units[0]->fresh()->refunded_minor === 0, '只退失败台，成功台不受影响');
    [$status, $detail] = callRefund($reviewer, $path . '/refunds/' . $refundId); verifyRefund($status === 200 && $detail['data']['executor_id'] === $admins[0]->id && $detail['data']['delivery_task_id'] > 0, '退款应记录核查和执行人');
    [$status] = callRefund($applicant, '/api/admin/v1/service-instances/' . $units[2]->service_id . '/confirm-not-created', ['acknowledged' => true, 'reason' => '供应商查单已确认没有创建']); verifyRefund($status === 422, '人工核查凭证必填');
    [$status] = callRefund($applicant, '/api/admin/v1/service-instances/' . $units[2]->service_id . '/confirm-not-created', ['acknowledged' => true, 'reason' => '供应商查单已确认没有创建', 'evidence_reference' => $marker . '-CHECK']); verifyRefund($status === 200, '核查后才解除未知阻塞');
    $withdrawInput = ['member_id' => $member->id, 'source_id' => $rechargeId, 'amount_minor' => 500, 'reason' => '人工退还未消费充值'];
    [$status, $created] = callRefund($applicant, $path . '/withdrawals', refundInput($withdrawInput)); verifyRefund($status === 200, '资金退还申请恢复受理'); $withdrawId = $created['data']['id'];
    [$status] = callRefund($reviewer, $path . '/withdrawals/' . $withdrawId . '/reject', refundInput(['review_reason' => '核对资料后重新申请'])); verifyRefund($status === 200, '驳回应解冻');
    verifyRefund(Container::get(WalletLedgerService::class)->balance($member->id)['available_minor'] === 6000, '驳回恢复原来源和可用余额');
    [$status, $created] = callRefund($applicant, $path . '/withdrawals', refundInput($withdrawInput)); $withdrawId = $created['data']['id'];
    [$status] = callRefund($reviewer, $path . '/withdrawals/' . $withdrawId . '/approve', refundInput(['review_reason' => '已核对原充值及收款信息'])); verifyRefund($status === 200, '审核资金退还');
    [$status] = callRefund($reviewer, $path . '/withdrawals/' . $withdrawId . '/payout', refundInput(['external_reference' => $marker . '-BANK-OUT'])); verifyRefund($status === 422, '打款说明不能缺失');
    $payout = refundInput(['external_reference' => $marker . '-BANK-OUT', 'payout_note' => '验收合成银行凭证，仅核对登记流程，没有实际对外打款']);
    foreach ([1, 2] as $_) { [$status] = callRefund($reviewer, $path . '/withdrawals/' . $withdrawId . '/payout', $payout); verifyRefund($status === 200, '登记及重放只结算一次'); }
    $balance = Container::get(WalletLedgerService::class)->balance($member->id);
    verifyRefund($balance['available_minor'] === 5500 && $balance['frozen_minor'] === 0, '打款只扣已冻结资金，不能再次扣可用余额');
    verifyRefund(Db::table('finance_operations')->where('actor_id', $admins[1]->id)->where('action', 'payout')->where('client_ip_location', '本机')->exists(), '资金审计保存客户端IP地点');
    echo "真实Webman/MySQL HTTP：未知与已交付拦截、单台全退、独立审核、核查凭证、来源冻结/驳回恢复、真实凭证字段登记、幂等及审计通过。使用合成交易，未连接银行或供应商。\n";
    if (getenv('HOWSTACK_REFUND_BROWSER') === '1') {
        $process = proc_open(['node', dirname($base) . '/frontend/admin/tests/controlled-refunds-real-browser.mjs'], [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes);
        fwrite($pipes[0], json_encode(['applicant_cookie' => $applicant, 'reviewer_cookie' => $reviewer, 'marker' => $marker, 'member_id' => $member->id,
            'eligible_units' => [$units[2]->id, $units[3]->id], 'recharge_id' => $rechargeId], JSON_THROW_ON_ERROR)); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process); echo $output; verifyRefund($code === 0, '后台真实浏览器验收失败：' . $error);
    }
} finally {
    if ($member !== null) {
        $memberId = $member->id; $orderIds = OrderModel::query()->where('member_id', $memberId)->pluck('id');
        $rechargeIds = Db::table('recharges')->where('member_id', $memberId)->pluck('id');
        $messageIds = Db::table('messages')->where('business_type', 'recharge')->whereIn('business_id', $rechargeIds)->pluck('id');
        $recipientIds = Db::table('message_recipients')->whereIn('message_id', $messageIds)->pluck('id');
        $deliveryIds = Db::table('message_deliveries')->whereIn('recipient_id', $recipientIds)->pluck('id');
        Db::table('message_delivery_attempts')->whereIn('delivery_id', $deliveryIds)->delete();
        Db::table('message_deliveries')->whereIn('id', $deliveryIds)->delete();
        Db::table('message_recipients')->whereIn('id', $recipientIds)->delete();
        Db::table('messages')->whereIn('id', $messageIds)->delete();
        $refundIds = Db::table('refund_requests')->where('member_id', $memberId)->pluck('id');
        Db::table('finance_outbox')->where('event_type', 'refund.completed')->whereIn('aggregate_id', $refundIds)->delete();
        // 只清理本次合成fixture，不改真实业务单据。
        foreach (['refund_attempts' => 'refund_id'] as $table => $key) Db::table($table)->whereIn($key, Db::table('refund_requests')->where('member_id', $memberId)->select('id'))->delete();
        Db::table('refund_requests')->where('member_id', $memberId)->delete(); Db::table('withdrawal_requests')->where('member_id', $memberId)->delete();
        Db::table('finance_outbox')->whereIn('aggregate_id', $orderIds)->where('event_type', 'order.paid')->delete();
        Db::table('service_provisioning_tasks')->whereIn('order_id', $orderIds)->delete();
        Db::table('order_item_units')->whereIn('order_item_id', Db::table('order_items')->whereIn('order_id', $orderIds)->select('id'))->update(['service_id' => null]);
        ServiceInstanceModel::query()->where('member_id', $memberId)->delete();
        Db::table('wallet_consumptions')->whereIn('order_id', $orderIds)->delete();
        Db::table('wallet_entries')->where('member_id', $memberId)->delete();
        Db::table('wallet_funding_lots')->whereIn('account_id', Db::table('wallet_accounts')->where('member_id', $memberId)->select('id'))->delete();
        Db::table('wallet_accounts')->where('member_id', $memberId)->delete();
        Db::table('payment_transactions')->where('member_id', $memberId)->delete(); Db::table('recharges')->where('member_id', $memberId)->delete();
        Db::table('order_item_units')->whereIn('order_item_id', Db::table('order_items')->whereIn('order_id', $orderIds)->select('id'))->delete();
        Db::table('order_items')->whereIn('order_id', $orderIds)->delete(); OrderModel::query()->where('member_id', $memberId)->delete();
        $member->delete();
    }
    foreach ($admins as $admin) {
        Db::table('finance_operations')->where('actor_id', $admin->id)->delete();
        Db::table('admin_operation_logs')->where('admin_user_id', $admin->id)->delete(); $admin->delete();
    }
}
