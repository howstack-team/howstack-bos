<?php

declare(strict_types=1);

/** 显式启用的本地HTTP验收；只创建临时会员与人工测试挑战，不发送短信邮件。 */
if (getenv('HOWSTACK_AUTH_HTTP_ACCEPTANCE') !== '1') exit('请显式设置HOWSTACK_AUTH_HTTP_ACCEPTANCE=1，仅用于本地验收。');
$backend = dirname(__DIR__, 2);
require $backend . '/vendor/autoload.php';
Dotenv\Dotenv::createUnsafeImmutable($backend)->safeLoad();
support\App::loadAllConfig(['route']);
date_default_timezone_set((string) config('app.default_timezone', 'Asia/Shanghai'));
if (in_array(strtolower((string) getenv('APP_ENV')), ['production', 'prod'], true)) exit('生产环境禁止执行。');

use app\modules\member\infrastructure\model\{MemberModel, MemberAuthChallengeModel, MemberSessionModel};
use app\modules\member\domain\PublicMemberId;

function check(bool $value, string $label): void { if (!$value) throw new RuntimeException($label); }
$curl = curl_init();
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 15]);
function requestHttp(string $path, ?array $input = null): array {
    global $curl;
    curl_setopt_array($curl, [CURLOPT_URL => 'http://127.0.0.1:8989/api/console/v1/auth/' . $path,
        CURLOPT_CUSTOMREQUEST => $input === null ? 'GET' : 'POST', CURLOPT_POSTFIELDS => $input === null ? null : json_encode($input, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: http://127.0.0.1:8989']]);
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException('HTTP连接失败');
    return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
}
function cookieValue(string $name): string {
    global $curl;
    foreach (curl_getinfo($curl, CURLINFO_COOKIELIST) as $cookie) { $parts = explode("\t", $cookie); if (($parts[5] ?? '') === $name) return $parts[6]; }
    return '';
}
$member = null;
$challengeIds = [];
try {
    $mobile = '139' . (string) random_int(10000000, 99999999);
    $email = 'auth-fixture-' . bin2hex(random_bytes(6)) . '@example.test';
    $member = MemberModel::query()->create(['public_id' => PublicMemberId::generate(), 'mobile' => $mobile,
        'email' => $email, 'nickname' => '认证HTTP临时验收', 'password_hash' => password_hash('Fixture@123456', PASSWORD_DEFAULT), 'status' => 1]);
    [$status] = requestHttp('options'); check($status === 200, '认证能力接口失败');
    $binding = cookieValue('hlyq_auth_binding'); check(strlen($binding) === 64, '绑定Cookie缺失');
    [$status] = requestHttp('login-sms', ['mobile' => 'bad', 'code' => '1', 'challenge_id' => 'x']);
    check($status === 422, '畸形输入应在签发前拒绝');
    [$status] = requestHttp('login', ['account' => $mobile, 'password' => 'Fixture@123456', 'remember_me' => true]);
    check($status === 200, '密码登录失败');
    $first = cookieValue('hlyq_member_token'); check($first !== '', '登录Cookie缺失');
    [$status] = requestHttp('logout', []); check($status === 200, '退出失败');
    curl_setopt($curl, CURLOPT_COOKIELIST, "Set-Cookie: hlyq_member_token={$first}; domain=127.0.0.1; path=/");
    [$status] = requestHttp('me'); check($status === 401, '退出后旧Cookie仍可复用');
    // 清除为重放试验手工插入的Cookie，避免与浏览器host-only Cookie重复。
    curl_setopt($curl, CURLOPT_COOKIELIST, 'ALL');
    requestHttp('options'); $binding = cookieValue('hlyq_auth_binding');
    $seed = function (string $purpose, string $method, string $target) use ($member, $binding, &$challengeIds): string {
        $id = bin2hex(random_bytes(16)); $challengeIds[] = $id;
        $digest = fn (string $input): string => hash_hmac('sha256', $input, (string) getenv('APP_KEY'));
        MemberAuthChallengeModel::query()->create(['challenge_id' => $id, 'purpose' => $purpose, 'method' => $method,
            'member_id' => $member->id, 'password_version' => hash('sha256', $member->password_hash), 'target_hash' => $digest($purpose . ':' . $method . ':' . $target),
            'binding_hash' => $digest($binding), 'code_hash' => $digest($id . ':123456'), 'status' => 'active', 'attempts' => 0,
            'expires_at' => date('Y-m-d H:i:s', time() + 300), 'created_at' => date('Y-m-d H:i:s')]);
        return $id;
    };
    $loginId = $seed('login', 'mobile', $mobile);
    [$status, $body] = requestHttp('login-sms', ['mobile' => $mobile, 'code' => '123456', 'challenge_id' => $loginId]);
    check($status === 200, '短信挑战登录失败（HTTP ' . $status . '，' . ($body['message'] ?? '') . '）');
    $second = cookieValue('hlyq_member_token');
    [$status, $body] = requestHttp('me'); check($status === 200, '会员资料接口失败（HTTP ' . $status . '，' . ($body['message'] ?? '') . '）');
    [$status, $body] = requestHttp('login-sms', ['mobile' => $mobile, 'code' => '123456', 'challenge_id' => $loginId]);
    check($status === 422, '已用验证码应拒绝');
    $resetId = $seed('password_reset', 'email', $email);
    [$status] = requestHttp('password-reset', ['account' => $email, 'code' => '123456', 'challenge_id' => $resetId, 'password' => 'Changed@123456']);
    check($status === 200, '邮箱挑战重置失败');
    [$status] = requestHttp('me'); check($status === 401, '重置后原登录会话仍有效');
    [$status] = requestHttp('login', ['account' => $email, 'password' => 'Changed@123456']);
    check($status === 200, '重置后的新密码登录失败');
    check(password_verify('Changed@123456', MemberModel::query()->find($member->id)->password_hash), '密码未持久化');
    echo "本地HTTP验收通过：输入校验、密码与短信挑战登录、退出撤销、一次性校验、邮箱重置、旧会话失效、新密码登录。外部消息发送次数：0。\n";
} finally {
    curl_close($curl);
    if ($member !== null) {
        MemberSessionModel::query()->where('member_id', $member->id)->delete();
        MemberAuthChallengeModel::query()->whereIn('challenge_id', $challengeIds)->delete();
        MemberModel::query()->whereKey($member->id)->delete();
    }
}
