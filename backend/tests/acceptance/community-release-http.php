<?php

declare(strict_types=1);

/** 本机范围验收：无身份、空请求，绝不创建账号、单据或供应资源。 */
if (getenv('HOWSTACK_COMMUNITY_SCOPE_HTTP_ACCEPTANCE') !== '1') exit('请显式设置 HOWSTACK_COMMUNITY_SCOPE_HTTP_ACCEPTANCE=1。');
$cases = [
    ['POST', '/api/admin/v1/finance/invoices', 404], ['POST', '/api/admin/v1/finance/withdrawals', 401],
    ['POST', '/api/admin/v1/member-levels', 404], ['PUT', '/api/admin/v1/member-levels/1', 404],
    ['POST', '/api/admin/v1/themes/install', 404], ['DELETE', '/api/admin/v1/themes/1', 404],
    ['POST', '/api/admin/v1/application-center/prepare', 404], ['POST', '/api/admin/v1/application-center/install', 404],
    ['POST', '/api/admin/v1/system-updates/check', 404], ['POST', '/api/admin/v1/system-updates/start', 404],
    ['POST', '/api/admin/v1/official-platform/license/activate', 404],
    ['GET', '/api/admin/v1/finance/invoices', 401], ['GET', '/api/admin/v1/finance/withdrawals', 401],
    ['POST', '/api/admin/v1/finance/invoices/1/red', 401], ['POST', '/api/admin/v1/finance/withdrawals/1/payout', 401],
    ['POST', '/api/admin/v1/finance/recharges', 401], ['POST', '/api/admin/v1/finance/refunds', 401],
    ['GET', '/api/admin/v1/tickets', 401], ['GET', '/api/admin/v1/system/version', 401],
];
foreach ($cases as [$method, $path, $expected]) {
    $curl = curl_init('http://127.0.0.1:8989' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: http://127.0.0.1:8989']]);
    if ($method !== 'GET') curl_setopt($curl, CURLOPT_POSTFIELDS, '{}');
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($body === false || $status !== $expected) throw new RuntimeException($method . ' ' . $path . ' 应为 ' . $expected . '，实际 ' . $status);
    $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($json) || !isset($json['code'], $json['message'], $json['request_id']) || !array_key_exists('data', $json)) throw new RuntimeException('缺少统一错误信封');
}
echo '真实 HTTP：' . count($cases) . ' 个暂缓新受理/历史读取/原业务鉴权边界通过；未写数据库、未调用外部平台。' . PHP_EOL;
