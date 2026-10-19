<?php

declare(strict_types=1);

/** 本机临时会员/合成账本验收；不创建真实充值、订单或支付，不调用外部服务。 */
if (getenv('HOWSTACK_WALLET_ENTRIES_HTTP_ACCEPTANCE') !== '1') {
    exit('需要显式设置 HOWSTACK_WALLET_ENTRIES_HTTP_ACCEPTANCE=1');
}
$base = dirname(__DIR__, 2);
require $base . '/vendor/autoload.php';
Dotenv\Dotenv::createUnsafeImmutable($base)->safeLoad();
support\App::loadAllConfig(['route']);
if (in_array(strtolower((string) getenv('APP_ENV')), ['production', 'prod'], true)
    || !in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    exit('仅限本地开发数据库');
}

use app\modules\finance\infrastructure\model\{WalletAccountModel, WalletEntryModel};
use app\modules\member\infrastructure\model\{MemberModel, MemberSessionModel};

function verifyEntries(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @return array{int,array,string} */
function callEntries(CurlHandle $curl, string $path, ?array $body = null, bool $expectEnvelope = true): array
{
    curl_setopt_array($curl, [CURLOPT_URL => 'http://127.0.0.1:8989' . $path,
        CURLOPT_CUSTOMREQUEST => $body === null ? 'GET' : 'POST', CURLOPT_HEADER => true,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: http://127.0.0.1:8989']]);
    $raw = curl_exec($curl);
    verifyEntries(is_string($raw), 'HTTP 请求失败');
    $headers = substr($raw, 0, (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE));
    if (!$expectEnvelope) return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), [], $headers];
    $data = json_decode(substr($raw, strlen($headers)), true, flags: JSON_THROW_ON_ERROR);
    verifyEntries(isset($data['code'], $data['message'], $data['request_id']) && array_key_exists('data', $data), '统一响应缺失');
    verifyEntries(stripos($headers, 'X-Request-Id:') !== false, '请求追踪头缺失');
    return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $data, $headers];
}

$members = $handles = [];
$marker = 'WE-HTTP-' . bin2hex(random_bytes(6));
try {
    foreach (range(0, 2) as $index) {
        $handle = curl_init();
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 15]);
        $handles[] = $handle;
    }
    [$anonymous, $a, $b] = $handles;
    $path = '/api/console/v1/wallet/entries';
    [$status] = callEntries($anonymous, $path);
    verifyEntries($status === 401, '匿名不得查询账本');
    foreach (['甲', '乙'] as $name) {
        $members[] = MemberModel::query()->create(['mobile' => '186' . random_int(10000000, 99999999), 'nickname' => $marker . $name,
            'password_hash' => password_hash('Fixture@123456', PASSWORD_DEFAULT), 'status' => 1]);
    }
    [$memberA, $memberB] = $members;
    foreach ([[$a, $memberA], [$b, $memberB]] as [$handle, $member]) {
        [$status] = callEntries($handle, '/api/console/v1/auth/login', ['account' => $member->mobile, 'password' => 'Fixture@123456', 'remember_me' => false]);
        verifyEntries($status === 200, '临时会员正常登录失败');
    }
    [$status, $empty] = callEntries($b, $path);
    verifyEntries($status === 200 && $empty['data'] === ['data' => [], 'total' => 0, 'page' => 1, 'page_size' => 20], '无钱包会员应为空页');
    verifyEntries(!WalletAccountModel::query()->where('member_id', $memberB->id)->exists(), '只读接口不得创建钱包');
    $account = WalletAccountModel::query()->create(['member_id' => $memberA->id, 'currency' => 'CNY', 'fund_type' => 'recharge_cash',
        'available_minor' => 25, 'frozen_minor' => 0, 'version' => 25]);
    foreach (range(1, 25) as $index) WalletEntryModel::query()->create(['entry_no' => $marker . '-' . $index, 'member_id' => $memberA->id,
        'account_id' => $account->id, 'entry_type' => 'credit', 'source_type' => 'acceptance_fixture', 'source_id' => $index,
        'amount_minor' => 1, 'available_after_minor' => $index, 'created_at' => '2026-10-19 12:00:00']);
    $before = WalletEntryModel::query()->where('member_id', $memberA->id)->orderBy('id')->get()->toJson();
    [$status, $one, $headers] = callEntries($a, $path);
    verifyEntries($status === 200 && $one['data']['total'] === 25 && count($one['data']['data']) === 20, '本人默认分页应为20条');
    verifyEntries($one['data']['data'][0]['entry_no'] === $marker . '-25', '同时间账本必须稳定倒序');
    verifyEntries(stripos($headers, 'Cache-Control: no-store, private') !== false && stripos($headers, 'Pragma: no-cache') !== false, '私人账本不得缓存');
    foreach ($one['data']['data'] as $row) verifyEntries(array_keys($row) === ['entry_no', 'entry_type', 'amount_minor', 'available_after_minor', 'currency', 'business_type', 'business_no', 'created_at'], '响应不得泄露内部主键或敏感财务字段');
    [$status, $two] = callEntries($a, $path . '?page=2&page_size=20');
    verifyEntries($status === 200 && count($two['data']['data']) === 5 && $two['data']['data'][0]['entry_no'] === $marker . '-5', '第二页应为剩余5条');
    verifyEntries(array_intersect(array_column($one['data']['data'], 'entry_no'), array_column($two['data']['data'], 'entry_no')) === [], '相邻分页不得重复流水');
    [$status, $other] = callEntries($b, $path);
    verifyEntries($status === 200 && $other['data']['total'] === 0 && $other['data']['data'] === [], '其他会员不能读取本人账本');
    foreach (['member_id=' . $memberA->id, 'account_id=' . $account->id, 'page=0', 'page_size=1000', 'page[]=1', 'sort_by=id'] as $query) {
        [$status, $error] = callEntries($b, $path . '?' . $query);
        verifyEntries($status === 422 && isset($error['data']['errors']), '非法查询必须422且包含字段错误');
    }
    [$status] = callEntries($a, $path, ['amount_minor' => 99999], false);
    verifyEntries(in_array($status, [404, 405], true), '资金明细不得提供写接口');
    verifyEntries($before === WalletEntryModel::query()->where('member_id', $memberA->id)->orderBy('id')->get()->toJson(), '查询不得改变账本');
    verifyEntries($account->fresh()->available_minor === 25 && $account->fresh()->frozen_minor === 0, '查询不得改变账户余额');
    $memberB->update(['status' => 0]);
    [$status] = callEntries($b, $path);
    verifyEntries($status === 401, '停用会员旧会话不能继续查询');
    [$status] = callEntries($a, '/api/console/v1/auth/logout', []);
    verifyEntries($status === 200, '退出失败');
    [$status] = callEntries($a, $path);
    verifyEntries($status === 401, '退出旧会话不能继续查询');
    echo "真实 Webman/MySQL HTTP：正常登录、匿名拒绝、两会员隔离、默认20条/稳定分页、字段白名单、非法参数422、禁止写入、停用/退出失效、无缓存和空读不建钱包通过。仅临时合成账本，未产生真实交易。\n";
} finally {
    foreach ($handles as $handle) curl_close($handle);
    foreach ($members as $member) {
        WalletEntryModel::query()->where('member_id', $member->id)->delete();
        WalletAccountModel::query()->where('member_id', $member->id)->delete();
        MemberSessionModel::query()->where('member_id', $member->id)->delete();
        $member->delete();
    }
}
