<?php

declare(strict_types=1);

/** 本地真实HTTP鉴权、校验和RBAC验收；不建立供应来源，不调用真实资源。 */
if (getenv('HOWSTACK_OPERATIONS_HTTP_ACCEPTANCE') !== '1') {
    exit('需要显式启用本地实例运维接口验收');
}
$base = dirname(__DIR__, 2);
require $base . '/vendor/autoload.php';
Dotenv\Dotenv::createUnsafeImmutable($base)->safeLoad();
support\App::loadAllConfig(['route']);
if (in_array(strtolower((string) getenv('APP_ENV')), ['production', 'prod'], true)
    || !in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    exit('仅限本地开发数据库');
}

use app\modules\member\infrastructure\model\{MemberModel, MemberSessionModel};
use app\modules\service_instance\infrastructure\model\ServiceInstanceModel;
use app\modules\admin\access\infrastructure\model\AdminUserModel;
use app\modules\admin\auth\contract\{AdminAccountRepositoryInterface, AdminAccessTokenServiceInterface};
use support\{Container, Db};

function verifyOperation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$curl = curl_init();
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 15]);
function callOperation(string $path, ?array $body = null, string $origin = 'http://127.0.0.1:8989', ?string $cookie = null): array
{
    global $curl;
    curl_setopt_array($curl, [CURLOPT_URL => 'http://127.0.0.1:8989' . $path, CURLOPT_CUSTOMREQUEST => $body === null ? 'GET' : 'POST',
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . $origin]]);
    if ($cookie !== null) {
        curl_setopt($curl, CURLOPT_COOKIE, $cookie);
    }
    $raw = curl_exec($curl);
    $length = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $data = json_decode(substr($raw, $length), true, flags: JSON_THROW_ON_ERROR);
    verifyOperation(array_keys($data) === ['code', 'message', 'data', 'request_id'], '响应信封错误');
    verifyOperation(stripos(substr($raw, 0, $length), 'X-Request-Id:') !== false, '缺少请求追踪头');
    return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $data];
}
$members = [];
$service = $admin = null;
try {
    [$status] = callOperation('/api/console/v1/services/1/operations');
    verifyOperation($status === 401, '匿名读取必须拒绝');
    foreach (['本人', '他人'] as $nickname) {
        $members[] = MemberModel::query()->create(['member_no' => '8' . random_int(10000000, 99999999), 'mobile' => '187' . random_int(10000000, 99999999),
            'nickname' => '运维接口' . $nickname, 'password_hash' => password_hash('Fixture@123456', PASSWORD_DEFAULT), 'status' => 1]);
    }
    [$member, $other] = $members;
    $service = ServiceInstanceModel::query()->create(['instance_no' => 'operations-http-' . bin2hex(random_bytes(8)), 'product_type' => 'cloud_server',
        'member_id' => $member->id, 'display_name' => '无供应来源接口验收实例', 'status' => 'active', 'activated_at' => date('Y-m-d H:i:s'),
        'expires_at' => date('Y-m-d H:i:s', time() + 86400)]);
    [$status] = callOperation('/api/console/v1/auth/login', ['account' => $member->mobile, 'password' => 'Fixture@123456', 'remember_me' => false]);
    verifyOperation($status === 200, '本人登录失败');
    $path = '/api/console/v1/services/' . $service->id . '/operations';
    [$status, $data] = callOperation($path);
    verifyOperation($status === 200 && $data['data']['actions'] === [], '没有原供应必须禁用操作');
    $body = ['action' => 'reset_password', 'password' => 'short', 'request_key' => 'http-operation-key', 'acknowledged' => true];
    [$status, $data] = callOperation($path, $body);
    verifyOperation(in_array($status, [400, 422], true) && isset($data['data']['errors']['password']), '密码必须在入口校验：HTTP' . $status . ' code=' . $data['code'] . ' message=' . $data['message'] . ' error_keys=' . implode(',', array_keys($data['data']['errors'] ?? [])));
    verifyOperation(!str_contains(json_encode($data), 'short'), '不得回显密码输入');
    $body['password'] = 'Fixture@123456';
    [$status] = callOperation($path, $body, 'https://untrusted.example');
    verifyOperation($status === 403, '外部来源写入必须拒绝');
    [$status] = callOperation($path, $body);
    verifyOperation($status === 409, '没有供应来源必须拒绝执行');
    [$status] = callOperation('/api/console/v1/auth/login', ['account' => $other->mobile, 'password' => 'Fixture@123456', 'remember_me' => false]);
    verifyOperation($status === 200, '他人登录失败');
    [$status] = callOperation($path);
    verifyOperation($status === 404, '跨会员读取必须拒绝');
    [$status] = callOperation($path, $body);
    verifyOperation($status === 404, '跨会员写入必须拒绝');
    $admin = AdminUserModel::query()->create(['username' => 'operation_http_' . bin2hex(random_bytes(6)), 'nickname' => '运维权限验收',
        'password_hash' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT), 'status' => 1, 'is_founder' => false]);
    $actor = Container::get(AdminAccountRepositoryInterface::class)->findById($admin->id);
    $token = Container::get(AdminAccessTokenServiceInterface::class)->issue($actor);
    $cookie = config('authentication.admin.cookie_name', 'hlyq_admin_token') . '=' . $token->value;
    foreach (['power_on', 'power_off', 'reboot', 'reinstall', 'reset_password'] as $action) {
        $input = ['action' => $action, 'request_key' => 'admin-http-operation-key', 'acknowledged' => true];
        if (in_array($action, ['reinstall', 'reset_password'], true)) {
            $input['password'] = 'Fixture@123456';
        }
        if ($action === 'reinstall') {
            $input['image_id'] = 1;
        }
        [$status] = callOperation('/api/admin/v1/service-instances/' . $service->id . '/operations', $input, cookie: $cookie);
        verifyOperation($status === 403, '后台必须分别核验操作权限：' . $action);
    }
    verifyOperation(Db::table('service_lifecycle_tasks')->where('service_id', $service->id)->count() === 0, '拒绝的请求不得创建任务');
    echo "真实Webman/MySQL：匿名、同源、输入验证、本人资格、跨会员读取和提交、后台五项操作RBAC通过；未调用供应商。\n";
} finally {
    curl_close($curl);
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
