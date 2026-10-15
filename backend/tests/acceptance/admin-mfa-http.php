<?php

declare(strict_types=1);
/** 使用无联系方式的临时管理员验收真实HTTP认证，结束后清理全部测试数据。 */
if (getenv('HOWSTACK_ADMIN_MFA_HTTP_ACCEPTANCE') !== '1') exit('请显式启用本机MFA验收。');
$backend = dirname(__DIR__, 2); require $backend . '/vendor/autoload.php';
Dotenv\Dotenv::createUnsafeImmutable($backend)->safeLoad(); support\App::loadAllConfig(['route']);
date_default_timezone_set((string) config('app.default_timezone', 'Asia/Shanghai'));
if (in_array(strtolower((string) getenv('APP_ENV')), ['prod', 'production'], true) || !in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1'], true)) exit('仅限本机开发数据库。');
use app\modules\admin\access\infrastructure\model\AdminUserModel;
use app\modules\admin\auth\infrastructure\model\{AdminMfaCredentialModel, AdminMfaChallengeModel, AdminLoginLogModel};
use app\modules\admin\auth\contract\{AdminAccountRepositoryInterface, AdminAccessTokenServiceInterface};
use support\{Container, Db};
function proof(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function callApi(string $path, ?array $body, ?string $cookie = null, string $origin = 'http://127.0.0.1:8989'): array {
    $headers = []; $curl = curl_init('http://127.0.0.1:8989/api/admin/v1' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . $origin], CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$headers) { $headers[] = trim($line); return strlen($line); }]);
    if ($body !== null) { curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR)); }
    if ($cookie !== null) curl_setopt($curl, CURLOPT_COOKIE, $cookie);
    $raw = curl_exec($curl); $http = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
    $json = json_decode((string) $raw, true, 32, JSON_THROW_ON_ERROR);
    proof(isset($json['code'], $json['message'], $json['request_id']) && array_key_exists('data', $json), 'API信封缺失');
    proof(in_array('X-Request-Id: ' . $json['request_id'], $headers, true), '追踪响应头不一致');
    return [$http, $json, $headers];
}
function cookie(array $headers, string $name): string {
    foreach ($headers as $line) if (preg_match('/^Set-Cookie: (' . preg_quote($name, '/') . '=[^;]*);/i', $line, $m)) {
        if (str_contains($line, 'Max-Age=0') || str_contains($line, 'expires=Thu, 01-Jan-1970')) continue;
        return $m[1];
    }
    throw new RuntimeException('缺少预期认证Cookie');
}
$admin = null;
try {
    $name = 'mfa_http_' . bin2hex(random_bytes(4));
    $admin = AdminUserModel::query()->create(['username' => $name, 'nickname' => 'MFA临时验收管理员', 'password_hash' => password_hash('FixtureMfa@123456', PASSWORD_DEFAULT), 'status' => 1, 'is_founder' => true]);
    $account = Container::get(AdminAccountRepositoryInterface::class)->findById($admin->id);
    $token = Container::get(AdminAccessTokenServiceInterface::class)->issue($account);
    $cookieName = (string) config('authentication.admin.cookie_name', 'hlyq_admin_token'); $fullCookie = $cookieName . '=' . $token->value;
    [$status] = callApi('/profile/mfa', null); proof($status === 401, '匿名不能读取安全设置');
    [$status] = callApi('/profile/mfa/setup', ['current_password' => 'FixtureMfa@123456'], $fullCookie, 'https://untrusted.example'); proof($status === 403, '跨源不能修改MFA');
    [$status, $setup] = callApi('/profile/mfa/setup', ['current_password' => 'FixtureMfa@123456'], $fullCookie); proof($status === 200, '生成绑定信息失败');
    $setup = $setup['data'];
    [$status, $bound, $headers] = callApi('/profile/mfa/enable', ['current_password' => 'FixtureMfa@123456', 'setup_id' => $setup['setup_id'], 'code' => OTPHP\TOTP::createFromSecret($setup['secret'])->at(time() - 30)], $fullCookie);
    proof($status === 200 && count($bound['data']['recovery_codes']) === 10, '绑定确认失败');
    $codes = $bound['data']['recovery_codes'];
    [$status] = callApi('/auth/me', null, $fullCookie); proof($status === 401, '绑定后旧JWT必须失效');
    [$status, $challenge, $headers] = callApi('/auth/login', ['username' => $name, 'password' => 'FixtureMfa@123456', 'remember' => true]);
    proof($status === 200 && $challenge['data']['mfa_required'] === true && !isset($challenge['data']['user']), '密码通过不应返回登录用户');
    $challengeCookie = cookie($headers, 'hlyq_admin_mfa');
    [$status] = callApi('/auth/me', null, $challengeCookie); proof($status === 401, '挑战Cookie不能访问受保护接口');
    [$status, $completed, $headers] = callApi('/auth/mfa/verify', ['code' => OTPHP\TOTP::createFromSecret($setup['secret'])->at(time()), 'recovery' => false], $challengeCookie);
    proof($status === 200 && $completed['data']['mfa_required'] === false, '第二步验证失败');
    $fullCookie = cookie($headers, $cookieName);
    [$status, $profile] = callApi('/auth/me', null, $fullCookie); proof($status === 200 && $profile['data']['user']['id'] === $admin->id, 'MFA完成后应可访问后台');
    [$status] = callApi('/auth/mfa/verify', ['code' => $codes[0], 'recovery' => true], $challengeCookie); proof($status === 401, '挑战必须一次性消费');
    // 恢复码登录；原密码仍必须先通过，不能单独用恢复码换会话。
    [$status, $v, $headers] = callApi('/auth/login', ['username' => $name, 'password' => 'FixtureMfa@123456']); proof($status === 200, '恢复码登录创建挑战失败');
    $challengeCookie = cookie($headers, 'hlyq_admin_mfa');
    [$status, $v, $headers] = callApi('/auth/mfa/verify', ['code' => $codes[0], 'recovery' => true], $challengeCookie); proof($status === 200, '恢复码登录失败');
    $fullCookie = cookie($headers, $cookieName);
    [$status, $v] = callApi('/profile/mfa', null, $fullCookie); proof($status === 200 && $v['data']['recovery_remaining'] === 9, '恢复码未被消费');
    [$status] = callApi('/profile/mfa/disable', ['current_password' => 'FixtureMfa@123456', 'code' => $codes[0], 'recovery' => true], $fullCookie); proof($status === 422, '使用过的恢复码不能关闭MFA');
    [$status, $v, $headers] = callApi('/profile/mfa/disable', ['current_password' => 'FixtureMfa@123456', 'code' => $codes[1], 'recovery' => true], $fullCookie); proof($status === 200 && $v['data']['enabled'] === false, '关闭MFA失败');
    $fullCookie = cookie($headers, $cookieName);
    AdminUserModel::query()->whereKey($admin->id)->update(['is_founder' => false]);
    [$status] = callApi('/profile/mfa/setup', ['current_password' => 'FixtureMfa@123456'], $fullCookie); proof($status === 403, '没有管理本人MFA权限不能绑定验证器');
    echo "真实HTTP：绑定、旧JWT失效、密码挑战隔离、TOTP及恢复码登录、恢复码重放拒绝、跨源和匿名拒绝均通过。\n";
} finally {
    if ($admin !== null) {
        AdminMfaChallengeModel::query()->where('admin_user_id', $admin->id)->delete();
        AdminMfaCredentialModel::query()->where('admin_user_id', $admin->id)->delete();
        AdminLoginLogModel::query()->where('admin_user_id', $admin->id)->delete();
        Db::table('admin_operation_logs')->where('admin_user_id', $admin->id)->delete();
        $admin->delete();
    }
}
