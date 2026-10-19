<?php

declare(strict_types=1);

/** 本地真实 HTTP、认证、RBAC、同源及未付订单取消；不扣款、不调用供应商。 */
if (getenv('HOWSTACK_RENEWAL_HTTP_ACCEPTANCE') !== '1') {
    exit('请显式启用本地续费接口验收');
}
$base = dirname(__DIR__, 2);
require $base . '/vendor/autoload.php';
Dotenv\Dotenv::createUnsafeImmutable($base)->safeLoad();
support\App::loadAllConfig(['route']);
if (in_array(strtolower((string) getenv('APP_ENV')), ['production', 'prod'], true)
    || !in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    exit('禁止在非本地数据库执行');
}

use app\modules\member\infrastructure\model\{MemberModel, MemberSessionModel};
use app\modules\service_instance\infrastructure\model\ServiceInstanceModel;
use app\modules\order\infrastructure\model\OrderModel;
use app\modules\admin\access\infrastructure\model\AdminUserModel;
use app\modules\admin\auth\contract\{AdminAccountRepositoryInterface, AdminAccessTokenServiceInterface};
use support\{Container, Db};

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$curl = curl_init();
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 15]);
function callRenewal(string $path, array $body, string $origin = 'http://127.0.0.1:8989', ?string $cookie = null): array
{
    global $curl;
    curl_setopt_array($curl, [CURLOPT_URL => 'http://127.0.0.1:8989' . $path, CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR), CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . $origin]]);
    if ($cookie !== null) curl_setopt($curl, CURLOPT_COOKIE, $cookie);
    $raw = curl_exec($curl);
    $length = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $headers = substr($raw, 0, $length);
    $data = json_decode(substr($raw, $length), true, flags: JSON_THROW_ON_ERROR);
    verify(array_keys($data) === ['code', 'message', 'data', 'request_id'], '响应信封错误');
    verify(stripos($headers, 'X-Request-Id:') !== false, '缺少请求标识');
    verify(!str_contains((string) $raw, 'SQLSTATE') && !str_contains((string) $raw, 'Stack trace'), '接口泄露内部异常');
    return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $data];
}
$members = [];
$service = $order = $admin = null;
try {
    [$status] = callRenewal('/api/console/v1/services/1/renewal-quote', ['months' => 0]);
    verify($status === 401, '匿名续费报价必须拒绝');
    foreach (['本人续费HTTP验收', '跨会员续费HTTP验收'] as $nickname) {
        $members[] = MemberModel::query()->create(['public_id' => \app\modules\member\domain\PublicMemberId::generate(),
            'mobile' => '139' . random_int(10000000, 99999999), 'nickname' => $nickname,
            'password_hash' => password_hash('Fixture@123456', PASSWORD_DEFAULT), 'status' => 1]);
    }
    [$member, $other] = $members;
    $service = ServiceInstanceModel::query()->create(['instance_no' => 'http-renew-' . bin2hex(random_bytes(8)),
        'product_type' => 'cloud_server', 'member_id' => $member->id, 'display_name' => '无真实上游的接口边界测试',
        'status' => 'active', 'activated_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + 30 * 86400)]);
    [$status] = callRenewal('/api/console/v1/auth/login', ['account' => $member->mobile, 'password' => 'Fixture@123456', 'remember_me' => false]);
    verify($status === 200, '本人认证失败');
    [$status] = callRenewal('/api/console/v1/services/' . $service->id . '/renewal-quote', ['months' => -1]);
    verify(in_array($status, [400, 422], true), '非法月份必须在入口拒绝');
    [$status] = callRenewal('/api/console/v1/services/' . $service->id . '/renewal-quote', ['months' => 0], 'https://untrusted.example');
    verify($status === 403, '非同源报价写请求必须拒绝');
    [$status] = callRenewal('/api/console/v1/services/' . $service->id . '/renewal-quote', ['months' => 0]);
    verify($status === 409, '没有确认开通及供应契约时必须拒绝续费');
    $order = OrderModel::query()->create(['order_no' => 'HTTP-CANCEL-' . bin2hex(random_bytes(8)), 'member_id' => $member->id,
        'member_mobile_snapshot' => $member->mobile, 'member_nickname_snapshot' => $member->nickname, 'order_type' => 'renewal',
        'status' => 'pending_payment', 'payment_status' => 'unpaid', 'currency' => 'CNY', 'subtotal_minor' => 100, 'discount_minor' => 0, 'payable_minor' => 100, 'remark' => '本地无扣款验收']);
    [$status] = callRenewal('/api/console/v1/orders/' . $order->id . '/cancel', []);
    verify($status === 200 && $order->fresh()->status === 'cancelled', '真实未付订单取消失败');
    [$status] = callRenewal('/api/console/v1/orders/' . $order->id . '/cancel', []);
    verify($status === 200, '取消必须幂等');
    [$status] = callRenewal('/api/console/v1/auth/login', ['account' => $other->mobile, 'password' => 'Fixture@123456', 'remember_me' => false]);
    verify($status === 200, '他人认证失败');
    [$status] = callRenewal('/api/console/v1/services/' . $service->id . '/renewal-quote', ['months' => 1]);
    verify($status === 404, '跨会员报价必须拒绝');
    [$status] = callRenewal('/api/console/v1/services/' . $service->id . '/renewals', ['months' => 1, 'expected_amount_minor' => 100, 'request_key' => 'http-renew-cross-owner']);
    verify($status === 404, '跨会员创建续费必须拒绝');
    [$status] = callRenewal('/api/console/v1/orders/' . $order->id . '/cancel', []);
    verify($status === 404, '跨会员取消必须拒绝');
    $admin = AdminUserModel::query()->create(['username' => 'renew_http_' . bin2hex(random_bytes(6)), 'nickname' => '续费权限验收',
        'password_hash' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT), 'status' => 1, 'is_founder' => false]);
    $actor = Container::get(AdminAccountRepositoryInterface::class)->findById($admin->id);
    $token = Container::get(AdminAccessTokenServiceInterface::class)->issue($actor);
    $cookie = config('authentication.admin.cookie_name', 'hlyq_admin_token') . '=' . $token->value;
    foreach (['renewal-quote' => ['months' => 0], 'retry-lifecycle' => [], 'renewals' => ['months' => 1, 'expected_amount_minor' => 100, 'request_key' => 'admin-http-renew-key']] as $action => $body) {
        [$status] = callRenewal('/api/admin/v1/service-instances/' . $service->id . '/' . $action, $body, cookie: $cookie);
        verify($status === 403, '缺少后台续费或生命周期权限必须拒绝');
    }
    echo "真实Webman/MySQL：匿名、本人资格、跨会员、同源、字段验证、后台RBAC、真实未付取消与幂等通过；未扣款及调用供应商。\n";
} finally {
    curl_close($curl);
    if ($order) {
        $messages = Db::table('messages')->where('business_type', 'order')->where('business_id', $order->id)->pluck('id');
        Db::table('messages')->whereIn('id', $messages)->delete();
        $order->delete();
    }
    if ($service) {
        Db::table('service_lifecycle_tasks')->where('service_id', $service->id)->delete();
        $service->delete();
    }
    if ($admin) {
        Db::table('admin_operation_logs')->where('admin_user_id', $admin->id)->delete();
        $admin->delete();
    }
    foreach ($members as $member) {
        MemberSessionModel::query()->where('member_id', $member->id)->delete();
        $member->delete();
    }
}
