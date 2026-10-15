<?php

declare(strict_types=1);

/** 本机开发HTTP验收；仅临时账号和通道，不访问QQ/微信上游、不发送验证码。 */
if (getenv('HOWSTACK_OAUTH_HTTP_ACCEPTANCE') !== '1') exit('请显式设置HOWSTACK_OAUTH_HTTP_ACCEPTANCE=1，仅限本机开发验收。');
$backend = dirname(__DIR__, 2);
require $backend . '/vendor/autoload.php';
Dotenv\Dotenv::createUnsafeImmutable($backend)->safeLoad();
support\App::loadAllConfig(['route']);
date_default_timezone_set((string) config('app.default_timezone', 'Asia/Shanghai'));
if (in_array(strtolower((string) getenv('APP_ENV')), ['prod', 'production'], true)) exit('生产环境禁止执行。');
if (!in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1'], true)) exit('只允许本机测试库。');

use app\modules\channel\contract\ChannelPlatformRegistryInterface;
use app\modules\channel\infrastructure\model\ChannelConnectionModel;
use app\modules\member\domain\PublicMemberId;
use app\modules\member\infrastructure\DatabaseMemberOAuthRepository;
use app\modules\member\infrastructure\model\{MemberModel, MemberOAuthPendingModel, MemberOAuthIdentityModel, MemberSessionModel};
use app\shared\contract\SensitiveDataCipherInterface;
use app\shared\plugin\dto\oauth\OAuthIdentity;
use support\{Container, Redis};

function verifyOAuth(bool $value, string $label): void { if (!$value) throw new RuntimeException($label); }
$curl = curl_init(); curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 15]);
function oauthRequest(string $path, ?array $input = null, string $origin = 'http://127.0.0.1:8989'): array {
    global $curl;
    $headers = [];
    curl_setopt_array($curl, [CURLOPT_URL => 'http://127.0.0.1:8989/api/console/v1/auth/' . $path,
        CURLOPT_CUSTOMREQUEST => $input === null ? 'GET' : 'POST', CURLOPT_POSTFIELDS => $input === null ? null : json_encode($input, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . $origin, 'User-Agent: Mozilla/5.0 iPhone Mobile'],
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int { $headers[] = $line; return strlen($line); }]);
    $body = curl_exec($curl);
    if (!is_string($body)) throw new RuntimeException('HTTP连接失败');
    $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    verifyOAuth(array_keys($json) === ['code', 'message', 'data', 'request_id'], '响应固定字段错误');
    verifyOAuth(str_contains(strtolower(implode('', $headers)), 'x-request-id:'), '响应缺少请求标识');
    return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $json, implode('', $headers)];
}
function oauthCookie(string $name): string {
    global $curl;
    foreach (curl_getinfo($curl, CURLINFO_COOKIELIST) as $cookie) { $parts = explode("\t", $cookie); if (($parts[5] ?? '') === $name) return $parts[6]; }
    return '';
}
$member = $channel = null; $state = null;
try {
    // 真实插件定义和加密配置；仅种入临时行，绝不修改商户原通道。
    $definition = Container::get(ChannelPlatformRegistryInterface::class)->definition('oauth', 'qq_login');
    verifyOAuth($definition !== null, '本机QQ插件必须已安装并启用');
    $cipher = Container::get(SensitiveDataCipherInterface::class);
    $channel = ChannelConnectionModel::query()->create(['category' => 'oauth', 'name' => 'OAuth HTTP临时验收', 'plugin_id' => 'qq_login',
        'platform_snapshot' => $definition, 'secret_keys' => ['app_secret'], 'configuration_encrypted' => $cipher->encrypt(json_encode([
            'app_id' => '12345678', 'app_secret' => 'fixture-only-secret', 'redirect_url' => 'https://merchant.example.test/console/login'], JSON_THROW_ON_ERROR)),
        'status' => 1, 'sort_order' => -999999, 'description' => '临时测试，不访问真实上游', 'revision' => 1]);
    $mobile = '139' . random_int(10000000, 99999999);
    $member = MemberModel::query()->create(['public_id' => PublicMemberId::generate(), 'mobile' => $mobile, 'nickname' => 'OAuth HTTP临时会员',
        'password_hash' => password_hash('Fixture@123456', PASSWORD_DEFAULT), 'status' => 1]);
    [$status, $body, $headers] = oauthRequest('options');
    verifyOAuth($status === 200 && in_array('qq', $body['data']['oauth_providers'], true), 'QQ能力未暴露');
    verifyOAuth(str_contains($headers, 'HttpOnly') && str_contains($headers, 'no-store'), '能力Cookie或缓存错误');
    $binding = oauthCookie('hlyq_auth_binding');
    [$status] = oauthRequest('oauth/start', ['provider' => 'qq'], 'https://wrong.example');
    verifyOAuth($status === 403, '跨域写操作必须拒绝');
    [$status, $body] = oauthRequest('oauth/start', ['provider' => 'qq', 'remember_me' => true]);
    verifyOAuth($status === 200, '发起授权失败');
    parse_str(parse_url($body['data']['url'], PHP_URL_QUERY), $query); $state = $query['state'];
    verifyOAuth($query['display'] === 'mobile' && $query['redirect_uri'] === 'https://merchant.example.test/console/login', '官方授权参数错误');
    [$status, $body] = oauthRequest('oauth/complete', ['state' => 'bad', 'code' => 'CODE']);
    verifyOAuth($status === 422 && isset($body['data']['errors']['state']), '非法state必须前置拒绝');
    [$status] = oauthRequest('oauth/complete', ['state' => str_repeat('f', 64), 'code' => 'CODE']);
    verifyOAuth($status === 409, '未签发state不能访问上游');
    // 人工放置已验证身份，只验证HTTP关联/会话链路，不冒充真实平台授权。
    $ticket = bin2hex(random_bytes(32));
    (new DatabaseMemberOAuthRepository())->issue(hash('sha256', $ticket), hash('sha256', $binding),
        new OAuthIdentity('qq_login', '12345678', 'HTTP_' . bin2hex(random_bytes(8))), ['channel_id' => (int) $channel->id, 'revision' => 1, 'remember_me' => true]);
    curl_setopt($curl, CURLOPT_COOKIELIST, "Set-Cookie: hlyq_oauth_pending={$ticket}; domain=127.0.0.1; path=/");
    [$status, $body] = oauthRequest('oauth/pending');
    verifyOAuth($status === 200 && $body['data']['provider'] === 'qq' && !isset($body['data']['ticket']), '待关联信息泄露或失败');
    [$status, $body] = oauthRequest('oauth/bind', ['account' => $mobile, 'password' => 'wrong-password']);
    verifyOAuth($status === 422 && isset($body['data']['errors']['password']), '密码错误未返回字段反馈');
    verifyOAuth(MemberOAuthPendingModel::query()->whereKey(hash('sha256', $ticket))->first()->attempts === 1, '错误次数必须提交');
    [$status, $body, $headers] = oauthRequest('oauth/bind', ['account' => $mobile, 'password' => 'Fixture@123456']);
    verifyOAuth($status === 200 && $body['data']['status'] === 'authenticated' && $body['data']['user']['id'] === $member->public_id, '关联未建立正确身份');
    verifyOAuth(!isset($body['data']['token']) && str_contains($headers, 'HttpOnly'), '会话凭据不应返回JSON');
    verifyOAuth(MemberOAuthIdentityModel::query()->where('member_id', $member->id)->count() === 1, '唯一关联未持久化');
    [$status] = oauthRequest('me'); verifyOAuth($status === 200, '真实Cookie会话无法读取身份');
    [$status] = oauthRequest('oauth/pending'); verifyOAuth($status === 409, '消费后的关联票据必须失效');
    [$status] = oauthRequest('logout', []); verifyOAuth($status === 200, '退出失败');
    [$status] = oauthRequest('me'); verifyOAuth($status === 401, '退出后会话仍有效');
    echo "真实HTTP授权入口、同源拒绝、状态校验、关联事务、Cookie身份与退出验证通过；真实上游调用0次。\n";
} finally {
    if ($state !== null) Redis::del('channel:oauth:v1:' . $state);
    if ($channel !== null) { MemberOAuthPendingModel::query()->where('channel_id', $channel->id)->delete(); ChannelConnectionModel::query()->whereKey($channel->id)->delete(); }
    if ($member !== null) { MemberOAuthIdentityModel::query()->where('member_id', $member->id)->delete(); MemberSessionModel::query()->where('member_id', $member->id)->delete(); MemberModel::query()->whereKey($member->id)->delete(); }
    curl_close($curl);
}
