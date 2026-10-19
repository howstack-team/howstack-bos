<?php

declare(strict_types=1);

/** 本机真实会话与可选浏览器闭环，临时数据结束清理，不发外部通知。 */
if (getenv('HOWSTACK_TICKET_HTTP_ACCEPTANCE') !== '1') exit('需要显式启用HOWSTACK_TICKET_HTTP_ACCEPTANCE=1');
$base = dirname(__DIR__, 2);
require $base . '/vendor/autoload.php';
Dotenv\Dotenv::createUnsafeImmutable($base)->safeLoad();
support\App::loadAllConfig(['route']);
if (in_array(strtolower((string) getenv('APP_ENV')), ['production', 'prod'], true)
    || !in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1'], true)) exit('仅限本机开发数据库');

use app\modules\member\infrastructure\model\{MemberModel, MemberSessionModel};
use app\modules\support\infrastructure\model\{TicketTypeModel, TicketModel, TicketMessageModel, TicketCommandModel};
use app\modules\service_instance\infrastructure\model\ServiceInstanceModel;
use app\modules\admin\access\infrastructure\model\AdminUserModel;
use app\modules\admin\auth\contract\{AdminAccountRepositoryInterface, AdminAccessTokenServiceInterface};
use app\modules\message_configuration\contract\MessageConfigurationRepositoryInterface;
use app\modules\message_configuration\domain\{MessageGroups, MessageTypeCatalog};
use support\{Container, Db};

// 不改变客户消息配置；若启用了真实外部通知，拒绝用临时会员触发。
$stored = Container::get(MessageConfigurationRepositoryInterface::class)->get('customer', 'ticket_replied');
foreach (MessageGroups::fromStored('customer', MessageTypeCatalog::get('customer', 'ticket_replied'), $stored['configurations']) as $group) {
    if (!$group['enabled']) continue;
    foreach ($group['configurations'] as $channel => $configuration) if ($channel !== 'message' && ($configuration['enabled'] ?? false)) exit('需在不启用工单外部通知的本机测试环境运行');
}
function verifyTicket(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function callTicket(CurlHandle $curl, string $path, ?array $body = null, string $origin = 'http://127.0.0.1:8989'): array
{
    curl_setopt_array($curl, [CURLOPT_URL => 'http://127.0.0.1:8989' . $path, CURLOPT_CUSTOMREQUEST => $body === null ? 'GET' : 'POST',
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . $origin]]);
    $raw = curl_exec($curl); verifyTicket(is_string($raw), 'HTTP请求失败');
    $headers = substr($raw, 0, (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE));
    $data = json_decode(substr($raw, strlen($headers)), true, flags: JSON_THROW_ON_ERROR);
    verifyTicket(array_keys($data) === ['code', 'message', 'data', 'request_id'], '统一响应信封缺失');
    verifyTicket(stripos($headers, 'X-Request-Id:') !== false, '请求追踪头缺失');
    return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $data, $headers];
}
function ticketCookie(CurlHandle $curl): string
{
    $parts = [];
    foreach (curl_getinfo($curl, CURLINFO_COOKIELIST) as $cookie) { $fields = explode("\t", $cookie); $parts[] = $fields[5] . '=' . $fields[6]; }
    return implode('; ', $parts);
}
$members = $admins = $services = $handles = [];
$type = null; $marker = '工单验收' . bin2hex(random_bytes(5));
try {
    foreach (range(0, 5) as $index) { $handle = curl_init(); curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 15]); $handles[] = $handle; }
    [$anonymous, $a, $b, $adminHttp, $noGrantHttp, $browserHttp] = $handles;
    [$status] = callTicket($anonymous, '/api/console/v1/tickets'); verifyTicket($status === 401, '匿名读取必须拒绝');
    [$status] = callTicket($anonymous, '/api/console/v1/tickets', []); verifyTicket($status === 401, '匿名提交必须拒绝');
    $type = TicketTypeModel::query()->create(['name' => $marker, 'code' => 'acceptance_' . bin2hex(random_bytes(6)), 'status' => 1, 'sort_order' => 0]);
    foreach (['甲', '乙', '浏览器'] as $nickname) {
        $members[] = MemberModel::query()->create(['nickname' => $marker . $nickname, 'mobile' => '185' . random_int(10000000, 99999999), 'password_hash' => password_hash('Fixture@123456', PASSWORD_DEFAULT), 'status' => 1]);
    }
    foreach ($members as $member) $services[] = ServiceInstanceModel::query()->create(['member_id' => $member->id, 'instance_no' => 'ticket-' . bin2hex(random_bytes(8)), 'product_type' => 'cloud_server', 'display_name' => $marker . '实例', 'status' => 'active']);
    foreach ([[$a, $members[0]], [$b, $members[1]], [$browserHttp, $members[2]]] as [$handle, $member]) { [$status] = callTicket($handle, '/api/console/v1/auth/login', ['account' => $member->mobile, 'password' => 'Fixture@123456', 'remember_me' => false]); verifyTicket($status === 200, '临时会员登录失败'); }
    foreach ([true, false] as $founder) {
        $admin = AdminUserModel::query()->create(['username' => 'ticket_' . bin2hex(random_bytes(8)), 'nickname' => $marker . ($founder ? '管理员' : '无权人员'), 'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'status' => 1, 'is_founder' => $founder]);
        $admins[] = $admin;
        $actor = Container::get(AdminAccountRepositoryInterface::class)->findById($admin->id);
        $token = Container::get(AdminAccessTokenServiceInterface::class)->issue($actor);
        $cookie = config('authentication.admin.cookie_name') . '=' . $token->value;
        curl_setopt($founder ? $adminHttp : $noGrantHttp, CURLOPT_COOKIE, $cookie);
        if ($founder) $adminCookie = $cookie;
    }
    $path = '/api/console/v1/tickets';
    [$status, $types] = callTicket($a, $path . '/types', origin: ''); verifyTicket($status === 200 && is_array($types['data']), '同源GET不要求浏览器发送Origin');
    [$status, $list] = callTicket($a, $path, origin: ''); verifyTicket($status === 200 && $list['data']['page_size'] === 20, '列表默认20条');
    $input = ['type_id' => $type->id, 'service_id' => $services[0]->id, 'subject' => $marker . 'HTTP', 'content' => '<img src=x onerror=window.ticketXss=1>必须按文本展示', 'request_key' => bin2hex(random_bytes(16))];
    [$status] = callTicket($a, $path, $input, 'https://untrusted.example'); verifyTicket($status === 403, '跨来源写入必须拒绝');
    [$status, $created] = callTicket($a, $path, $input); verifyTicket($status === 201, '本人应可提交工单'); $id = $created['data']['ticket_id'];
    [$status, $replay] = callTicket($a, $path, $input); verifyTicket($status === 201 && $created['data'] === $replay['data'], '同键重试不能重复新建');
    [$status] = callTicket($a, $path, array_replace($input, ['subject' => '不同内容'])); verifyTicket($status === 409, '同键不同内容必须拒绝');
    foreach ([['service_id' => $services[1]->id], ['member_id' => $members[1]->id], ['subject' => '   '], ['type_id' => 4294967295]] as $patch) {
        [$status, $error] = callTicket(isset($patch['service_id']) ? $a : $b, $path, array_replace($input, ['service_id' => null, 'request_key' => bin2hex(random_bytes(16))], $patch)); verifyTicket($status === 422 && isset($error['data']['errors']), '关联、身份及输入应返回字段错误');
    }
    foreach (['/' . $id, '/' . $id . '/messages'] as $suffix) { [$status] = callTicket($b, $path . $suffix); verifyTicket($status === 404, '跨会员读取应404'); }
    foreach (['replies' => ['content' => '越权'], 'close' => []] as $action => $body) { [$status] = callTicket($b, $path . '/' . $id . '/' . $action, $body + ['request_key' => bin2hex(random_bytes(16))]); verifyTicket($status === 404, '跨会员写入应404'); }
    $adminPath = '/api/admin/v1/tickets/' . $id;
    foreach (['replies' => ['content' => '无权'], 'close' => []] as $action => $body) { [$status] = callTicket($noGrantHttp, $adminPath . '/' . $action, $body + ['request_key' => bin2hex(random_bytes(16))]); verifyTicket($status === 403, '无权限后台操作应拒绝'); }
    $reply = ['content' => '工作人员HTTP回复', 'request_key' => bin2hex(random_bytes(16))];
    [$status, $replyResult] = callTicket($adminHttp, $adminPath . '/replies', $reply); verifyTicket($status === 200, '后台应可回复');
    [$status, $replyAgain] = callTicket($adminHttp, $adminPath . '/replies', $reply); verifyTicket($replyResult['data'] === $replyAgain['data'], '重复后台回复应复用结果');
    [$status, $detail, $headers] = callTicket($a, $path . '/' . $id); verifyTicket($status === 200 && $detail['data']['ticket']['status'] === 'waiting_member', '后台回复后应等待会员');
    verifyTicket(!array_key_exists('member_id', $detail['data']['ticket']) && !array_key_exists('author_id', $detail['data']['messages'][0]), '客户接口不能泄露内部身份');
    verifyTicket(stripos($headers, 'Cache-Control: no-store, private') !== false, '工单禁止缓存');
    [$status] = callTicket($a, $path . '/' . $id . '/replies', ['content' => '本人HTTP追问', 'request_key' => bin2hex(random_bytes(16))]); verifyTicket($status === 200, '会员应可追问');
    [$status, $detail] = callTicket($a, $path . '/' . $id); verifyTicket($detail['data']['ticket']['status'] === 'open', '追问应等待后台');
    [$status] = callTicket($a, $path . '/' . $id . '/close', ['request_key' => bin2hex(random_bytes(16))]); verifyTicket($status === 200, '本人应可关闭');
    [$status] = callTicket($adminHttp, $adminPath . '/replies', ['content' => '关闭后回复', 'request_key' => bin2hex(random_bytes(16))]); verifyTicket($status === 409, '已关闭不能再回复');
    verifyTicket(Db::table('admin_operation_logs')->where('admin_user_id', $admins[0]->id)->where('module', '工单管理')->exists(), '后台动作应有审计');
    echo "真实Webman/MySQL HTTP：提交→后台回复→本人追问→关闭、同键去重/冲突、归属、同源、字段422、独立后台授权、只读投影及审计通过。\n";
    if (getenv('HOWSTACK_TICKET_BROWSER') === '1') {
        $process = proc_open(['node', dirname($base) . '/frontend/console/tests/tickets-real-browser.mjs'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode(['member_cookie' => ticketCookie($browserHttp), 'other_cookie' => ticketCookie($b), 'admin_cookie' => $adminCookie, 'marker' => $marker], JSON_THROW_ON_ERROR)); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $result = proc_close($process); echo $output; verifyTicket($result === 0, '真实浏览器专项失败：' . $error);
    }
    $limited = false;
    for ($attempt = 0; $attempt < 7; $attempt++) {
        [$status] = callTicket($browserHttp, $path, array_replace($input, ['service_id' => null, 'request_key' => bin2hex(random_bytes(16))]));
        if ($status === 429) { $limited = true; break; }
        verifyTicket($status === 201, '限流阈值内正常受理');
    }
    verifyTicket($limited, '工单新建必须受到频率限制');
    echo "本机真实限流：同会员超出新建额度返回429，查询和其他会员隔离。\n";
} finally {
    foreach ($handles as $handle) curl_close($handle);
    $ids = TicketModel::query()->whereIn('member_id', array_map(static fn ($member): int => $member->id, $members))->pluck('id')->all();
    if ($ids !== []) {
        $messageIds = Db::table('messages')->where('business_type', 'ticket')->whereIn('business_id', $ids)->pluck('id')->all();
        $recipients = Db::table('message_recipients')->whereIn('message_id', $messageIds)->pluck('id')->all();
        $deliveries = Db::table('message_deliveries')->whereIn('recipient_id', $recipients)->pluck('id')->all();
        Db::table('message_delivery_attempts')->whereIn('delivery_id', $deliveries)->delete(); Db::table('message_deliveries')->whereIn('id', $deliveries)->delete();
        Db::table('message_recipients')->whereIn('id', $recipients)->delete(); Db::table('messages')->whereIn('id', $messageIds)->delete();
        TicketCommandModel::query()->whereIn('ticket_id', $ids)->delete(); TicketMessageModel::query()->whereIn('ticket_id', $ids)->delete(); TicketModel::query()->whereIn('id', $ids)->delete();
    }
    foreach ($services as $service) $service->delete();
    foreach ($members as $member) { MemberSessionModel::query()->where('member_id', $member->id)->delete(); $member->delete(); }
    foreach ($admins as $admin) { Db::table('admin_operation_logs')->where('admin_user_id', $admin->id)->delete(); $admin->delete(); }
    if ($type) $type->delete();
}
