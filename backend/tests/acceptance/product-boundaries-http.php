<?php

declare(strict_types=1);

/** 本地真实路由及权限验收；不发布配置、不创建交易，临时管理员在结束后清理。 */
if (getenv('HOWSTACK_PRODUCT_BOUNDARIES_HTTP_ACCEPTANCE') !== '1') {
    exit('请显式启用本机产品边界验收。');
}
$backend = dirname(__DIR__, 2);
require $backend . '/vendor/autoload.php';
Dotenv\Dotenv::createUnsafeImmutable($backend)->safeLoad();
support\App::loadAllConfig(['route']);
if (in_array(strtolower((string) getenv('APP_ENV')), ['prod', 'production'], true)
    || !in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    exit('仅限本机开发数据库。');
}

use app\modules\admin\access\infrastructure\model\AdminUserModel;
use app\modules\admin\auth\contract\{AdminAccountRepositoryInterface, AdminAccessTokenServiceInterface};
use support\{Container, Db};

function proof(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function requestApi(string $path, string $method, ?array $body, ?string $cookie): array
{
    $curl = curl_init('http://127.0.0.1:8989/api/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => [
            'Content-Type: application/json', 'Origin: http://127.0.0.1:8989',
        ]]);
    if ($cookie !== null) {
        curl_setopt($curl, CURLOPT_COOKIE, $cookie);
    }
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (in_array($status, [404, 405], true)) {
        return [$status, null];
    }
    $response = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
    proof(isset($response['code'], $response['request_id']), '接口响应格式异常');
    return [$status, $response];
}

$admin = null;
try {
    $admin = AdminUserModel::query()->create([
        'username' => 'product_http_' . bin2hex(random_bytes(6)), 'nickname' => '产品边界临时验收',
        'password_hash' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT), 'status' => 1, 'is_founder' => true,
    ]);
    $account = Container::get(AdminAccountRepositoryInterface::class)->findById($admin->id);
    $token = Container::get(AdminAccessTokenServiceInterface::class)->issue($account);
    $cookie = config('authentication.admin.cookie_name', 'hlyq_admin_token') . '=' . $token->value;
    [$status] = requestApi('admin/v1/cloud-servers/configuration', 'GET', null, null);
    proof($status === 401, '配置读取必须认证');
    foreach (['deployment', 'specification', 'network', 'storage', 'purchase'] as $section) {
        [$status, $response] = requestApi('admin/v1/cloud-servers/sections/' . $section, 'GET', null, $cookie);
        proof($status === 200, '模块移动后配置分区失效：' . $section);
        proof(!array_key_exists('network_policies', $response['data']['references']['resources']), '不能暴露已关闭的旧网络编辑资源');
    }
    [$status, $response] = requestApi('admin/v1/cloud-servers/configuration', 'GET', null, $cookie);
    proof($status === 200 && array_key_exists('configuration', $response['data']), '当前配置读取失效');
    [$status, $legacyResponse] = requestApi('admin/v1/cloud-servers/configuration/live', 'PUT', [
        'configuration_id' => null, 'expected_configuration_id' => null, 'revision' => 0,
        'name' => '禁止创建旧配置', 'code' => 'must-not-create', 'description' => '',
        'draft' => ['schema_version' => '1.3.0'],
    ], $cookie);
    proof($status === 422, '当前保存入口不能新建或覆盖成旧配置，实际HTTP：' . $status . '，反馈：' . ($legacyResponse['message'] ?? '无'));
    foreach ([['cloud-servers/configuration', 'PUT'], ['cloud-products', 'POST'], ['cloud-products', 'GET'],
        ['cloud-products/999999', 'PUT'], ['cloud-products/999999/publish', 'POST'],
        ['cloud-servers/delivery-rules', 'GET'], ['cloud-servers/delivery-rules', 'POST'],
        ['cloud-servers/delivery-rules/999999', 'PUT']] as [$path, $method]) {
        [$status] = requestApi('admin/v1/' . $path, $method, [], $cookie);
        proof(in_array($status, [404, 405], true), '旧配置入口仍然可用：' . $path);
    }
    [$status] = requestApi('admin/v1/compute-resources/network_policies', 'POST', [
        'code' => 'must-not-save', 'name' => '拒绝旧策略写入', 'status' => 1,
        'bandwidth_min' => 1, 'bandwidth_max' => 100, 'bandwidth_step' => 1, 'ip_min' => 1, 'ip_max' => 1,
    ], $cookie);
    proof($status === 404, '旧网络策略应停止维护');
    [$status, $response] = requestApi('public/v1/products', 'GET', null, null);
    proof($status === 200 && is_array($response['data']), '公开目录读取失效');
    AdminUserModel::query()->whereKey($admin->id)->update(['is_founder' => false]);
    [$status] = requestApi('admin/v1/cloud-servers/sections/purchase', 'GET', null, $cookie);
    proof($status === 403, '模块迁移不能绕过页面权限');
    echo "真实 Webman/MySQL HTTP：五个配置分区、认证权限、公开目录、旧保存/销售/交付规则入口关闭及旧网络写入拒绝通过。\n";
} finally {
    if ($admin !== null) {
        Db::table('admin_operation_logs')->where('admin_user_id', $admin->id)->delete();
        $admin->delete();
    }
}
