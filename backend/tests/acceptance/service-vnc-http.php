<?php

declare(strict_types=1);

/** 本地真实VNC HTTP鉴权、校验和RBAC验收；不建立供应来源，不调用真实资源。 */
if (getenv('HOWSTACK_VNC_HTTP_ACCEPTANCE') !== '1') {
    exit('需要显式启用本地VNC接口验收');
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

function verifyVnc(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$curl = curl_init();
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 15]);
function callVnc(string $path, ?array $body = null, string $origin = 'http://127.0.0.1:8989', ?string $cookie = null): array
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
    verifyVnc(array_keys($data) === ['code', 'message', 'data', 'request_id'], '响应信封错误');
    verifyVnc(stripos(substr($raw, 0, $length), 'X-Request-Id:') !== false, '缺少请求追踪头');
    return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $data];
}
$members = [];
$service = $admin = null;
try {
    [$status] = callVnc('/api/console/v1/services/1/vnc');
    verifyVnc($status === 401, '匿名读取必须拒绝');
    foreach (['本人', '他人'] as $nickname) {
        $members[] = MemberModel::query()->create(['member_no' => '8' . random_int(10000000, 99999999), 'mobile' => '187' . random_int(10000000, 99999999),
            'nickname' => '运维接口' . $nickname, 'password_hash' => password_hash('Fixture@123456', PASSWORD_DEFAULT), 'status' => 1]);
    }
    [$member, $other] = $members;
    $service = ServiceInstanceModel::query()->create(['instance_no' => 'vnc-http-' . bin2hex(random_bytes(8)), 'product_type' => 'cloud_server',
        'member_id' => $member->id, 'display_name' => '无供应来源接口验收实例', 'status' => 'active', 'activated_at' => date('Y-m-d H:i:s'),
        'expires_at' => date('Y-m-d H:i:s', time() + 86400)]);
    [$status] = callVnc('/api/console/v1/auth/login', ['account' => $member->mobile, 'password' => 'Fixture@123456', 'remember_me' => false]);
    verifyVnc($status === 200, '本人登录失败');
    $path = '/api/console/v1/services/' . $service->id . '/vnc';
    [$status] = callVnc($path);
    verifyVnc($status === 409, '没有原供应来源必须拒绝VNC检查');
    [$status, $data] = callVnc('/api/console/v1/services/0/vnc');
    verifyVnc($status === 422 && isset($data['data']['errors']['id']), '路径必须在入口验证');
    [$status] = callVnc($path, [], 'https://untrusted.example');
    verifyVnc($status === 403, '外部来源签发必须拒绝');
    [$status] = callVnc($path, []);
    verifyVnc($status === 409, '没有原供应不能签发VNC');
    [$status] = callVnc('/api/console/v1/auth/login', ['account' => $other->mobile, 'password' => 'Fixture@123456', 'remember_me' => false]);
    verifyVnc($status === 200, '他人登录失败');
    [$status] = callVnc($path);
    verifyVnc($status === 404, '跨会员检查必须拒绝');
    [$status] = callVnc($path, []);
    verifyVnc($status === 404, '跨会员签发必须拒绝');
    for ($attempt = 0; $attempt < 2; $attempt++) {
        [$status] = callVnc($path, []);
        verifyVnc($status === 404, '限额内仍执行对象归属检查');
    }
    [$status] = callVnc($path, []);
    verifyVnc($status === 429, '签发必须受限流约束');
    $admin = AdminUserModel::query()->create(['username' => 'vnc_http_' . bin2hex(random_bytes(6)), 'nickname' => '运维权限验收',
        'password_hash' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT), 'status' => 1, 'is_founder' => false]);
    $actor = Container::get(AdminAccountRepositoryInterface::class)->findById($admin->id);
    $token = Container::get(AdminAccessTokenServiceInterface::class)->issue($actor);
    $cookie = config('authentication.admin.cookie_name', 'hlyq_admin_token') . '=' . $token->value;
    foreach ([null, []] as $input) {
        [$status] = callVnc('/api/admin/v1/service-instances/' . $service->id . '/vnc', $input, cookie: $cookie);
        verifyVnc($status === 403, '后台检查和签发均需独立VNC权限');
    }
    verifyVnc(Db::table('service_lifecycle_tasks')->where('service_id', $service->id)->count() === 0, 'VNC签发不创建运维或付费任务');
    echo "真实Webman/MySQL：VNC匿名、同源、路径验证、本人资格、跨会员、后台独立RBAC和签发限流通过；未调用供应商。\n";
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
