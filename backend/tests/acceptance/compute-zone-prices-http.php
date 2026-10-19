<?php

declare(strict_types=1);

/** 仅本地开发库：临时资源和管理员验收完成后清理，不发布配置、不创建订单或外部实例。 */
if (getenv('HOWSTACK_ZONE_PRICES_HTTP_ACCEPTANCE') !== '1') {
    exit('请显式启用本机可用区价格验收。');
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
use app\modules\compute\infrastructure\model\{DiskTypeModel, FlavorGroupModel, FlavorModel, RegionModel, ZoneModel};
use support\{Container, Db};

function proof(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function callApi(string $method, string $path, ?array $body, ?string $cookie, string $origin = 'http://127.0.0.1:8989'): array
{
    $headers = [];
    $curl = curl_init('http://127.0.0.1:8989/api/admin/v1/compute-resources' . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . $origin],
        CURLOPT_HEADERFUNCTION => static function ($handle, $line) use (&$headers): int {
            $headers[] = trim($line);
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    if ($cookie !== null) {
        curl_setopt($curl, CURLOPT_COOKIE, $cookie);
    }
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $json = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
    proof(isset($json['code'], $json['message'], $json['request_id']) && array_key_exists('data', $json), '响应信封缺失');
    proof(in_array('X-Request-Id: ' . $json['request_id'], $headers, true), '请求追踪标识不一致');
    return [$status, $json];
}

$admin = null;
$created = [];
try {
    [$status] = callApi('GET', '', null, null);
    proof($status === 401, '匿名不能查看资源价格');
    $suffix = bin2hex(random_bytes(6));
    $admin = AdminUserModel::query()->create([
        'username' => 'zone_http_' . $suffix, 'nickname' => '价格临时验收管理员',
        'password_hash' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT), 'status' => 1, 'is_founder' => true,
    ]);
    $account = Container::get(AdminAccountRepositoryInterface::class)->findById($admin->id);
    $token = Container::get(AdminAccessTokenServiceInterface::class)->issue($account);
    $cookie = config('authentication.admin.cookie_name', 'hlyq_admin_token') . '=' . $token->value;
    $create = static function (string $kind, array $fields) use (&$created, $cookie, $suffix): array {
        [$status, $response] = callApi('POST', '/' . $kind, array_replace([
            'code' => 'zone-http-' . $kind . '-' . $suffix, 'name' => '价格验收' . $kind, 'status' => 1,
        ], $fields), $cookie);
        proof($status === 201, '创建临时资源失败：' . $kind);
        $created[$kind][] = $response['data']['id'];
        return $response['data'];
    };
    $region = $create('regions', []);
    $first = $create('zones', ['region_id' => $region['id']]);
    $second = $create('zones', ['region_id' => $region['id'], 'code' => 'zone-http-second-' . $suffix]);
    $group = $create('flavor_groups', []);
    foreach (['flavors', 'disk_types'] as $kind) {
        $specific = $kind === 'flavors'
            ? ['group_id' => $group['id'], 'cpu' => 2, 'memory_mib' => 2048, 'architecture' => 'x86_64']
            : ['min_gib' => 20, 'max_gib' => 200, 'step_gib' => 1, 'max_count' => 2];
        $row = $create($kind, $specific + [
            'zone_ids' => [$first['id'], $second['id']], 'prices' => ['month' => 2000],
            'zone_prices' => [$second['id'] => ['month' => 4500]],
        ]);
        proof($row['zone_prices'][$second['id']]['month'] === 4500, '独立价格未回填');
        $path = '/' . $kind . '/' . $row['id'];
        [$status, $response] = callApi('PUT', $path, array_replace($row, ['zone_prices' => [$first['id'] => ['month' => 0]]]), $cookie);
        proof($status === 200 && $response['data']['zone_prices'][$first['id']]['month'] === 0, '显式零价不能回退');
        [$status, $response] = callApi('PUT', $path, array_replace($row, ['zone_prices' => []]), $cookie);
        proof($status === 200 && $response['data']['zone_prices'] === [], '清空不能恢复继承');
        [$status] = callApi('PUT', $path, array_replace($row, ['zone_prices' => [$first['id'] => ['month' => -1]]]), $cookie);
        proof($status === 422, '负价必须拒绝');
        [$status] = callApi('PUT', $path, array_replace($row, ['zone_ids' => [$first['id']]]), $cookie);
        proof($status === 422, '不能为未关联区配置价格');
        [$status] = callApi('PUT', $path, $row, $cookie, 'https://untrusted.example.test');
        proof($status === 403, '跨站写入必须拒绝');
    }
    [$status, $response] = callApi('GET', '', null, $cookie);
    proof($status === 200, '资源目录读取失败');
    foreach (['flavors', 'disk_types'] as $kind) {
        $rows = array_values(array_filter($response['data'][$kind], static fn ($row): bool => in_array($row['id'], $created[$kind], true)));
        proof(count($rows) === 1 && $rows[0]['prices']['month'] === 2000 && $rows[0]['zone_prices'] === [], '持久化结果不正确');
    }
    AdminUserModel::query()->whereKey($admin->id)->update(['is_founder' => false]);
    [$status] = callApi('GET', '', null, $cookie);
    proof($status === 403, '无资源权限不能读取价格');
    [$status] = callApi('PUT', '/disk_types/' . $row['id'], $row, $cookie);
    proof($status === 403, '无修改权限不能写价格');
    echo "真实 Webman/MySQL HTTP：双区价格、零价、清空、持久化、非法价格/作用域、匿名、跨站及权限拒绝通过。\n";
} finally {
    foreach (['disk_types' => DiskTypeModel::class, 'flavors' => FlavorModel::class, 'flavor_groups' => FlavorGroupModel::class, 'zones' => ZoneModel::class, 'regions' => RegionModel::class] as $kind => $model) {
        if (isset($created[$kind])) {
            $model::query()->whereIn('id', $created[$kind])->delete();
        }
    }
    if ($admin !== null) {
        Db::table('admin_operation_logs')->where('admin_user_id', $admin->id)->delete();
        $admin->delete();
    }
}
