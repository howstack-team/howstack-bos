<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\support\application\{TicketConversationService, ManageSupportService};
use app\modules\support\infrastructure\{DatabaseTicketConversationRepository, DatabaseSupportRepository};
use app\modules\support\validation\MemberTicketValidator;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\infrastructure\DatabaseAdminAccessRepository;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\service_instance\contract\MemberServiceRepositoryInterface;
use app\modules\message\application\DispatchMessageService;
use app\modules\message\contract\{MessageTransportInterface, MessageQueueInterface};
use app\modules\message\infrastructure\{DatabaseMessageRepository, DatabaseMessageRecipientDirectory};
use app\shared\infrastructure\{DatabaseTransactionManager, SodiumSensitiveDataCipher};
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\{IsolatedBusinessDatabase, MessageRuntimeFactory};

/** 真迁移、ORM、事务与通知Outbox；不连接外部平台。 */
final class TicketConversationTest extends TestCase
{
    use IsolatedBusinessDatabase;

    private function service(): TicketConversationService
    {
        return new TicketConversationService(new DatabaseTicketConversationRepository(), $this->createStub(MemberServiceRepositoryInterface::class),
            new DatabaseTransactionManager(), MessageRuntimeFactory::publisher());
    }
    private function input(): array
    {
        $type = $this->row('ticket_types', ['name' => '技术咨询', 'code' => bin2hex(random_bytes(6)), 'status' => 1, 'sort_order' => 0, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        return ['type_id' => $type, 'service_id' => null, 'subject' => '无法连接实例', 'content' => '<script>window.injected=1</script>正文作为纯文本', 'request_key' => bin2hex(random_bytes(16))];
    }
    private function key(): string { return bin2hex(random_bytes(16)); }
    private function rejected(callable $action, int $status): void
    {
        try { $action(); self::fail('应拒绝此操作'); }
        catch (BusinessException $error) { self::assertSame($status, $error->httpStatus()); }
    }

    public function testCompleteConversationIdempotencyCloseAndDurableMemberNotification(): void
    {
        $member = $this->member(); $service = $this->service(); $input = $this->input();
        $created = $service->create($member, $input); $id = $created['ticket_id'];
        self::assertSame($created, $service->create($member, $input));
        self::assertSame(1, Db::table('tickets')->count());
        self::assertSame('open', $service->detail($member, $id)['ticket']['status']);
        self::assertArrayNotHasKey('member_id', $service->detail($member, $id)['ticket']);
        self::assertSame($input['content'], $service->detail($member, $id)['ticket']['content']);
        $this->rejected(fn () => $service->create($member, array_replace($input, ['subject' => '不同内容'])), 409);
        $replyKey = $this->key();
        $reply = $service->execute('admin', 1, 'reply', $id, ['content' => '请提供排查信息'], $replyKey);
        self::assertSame($reply, $service->execute('admin', 1, 'reply', $id, ['content' => '请提供排查信息'], $replyKey));
        self::assertSame('waiting_member', $service->detail($member, $id)['ticket']['status']);
        self::assertSame(1, Db::table('ticket_messages')->count());
        self::assertSame(1, Db::table('message_deliveries')->count(), '未启用任何外部通知时仍有必要站内提醒');
        self::assertSame('pending', Db::table('message_deliveries')->value('status'));
        self::assertStringNotContainsString('请提供排查信息', Db::table('message_deliveries')->value('payload_encrypted'));
        $messages = new DatabaseMessageRepository(new SodiumSensitiveDataCipher(str_repeat('isolated-message-test-key', 2)));
        $dispatcher = new DispatchMessageService($messages, $this->createStub(MessageTransportInterface::class),
            $this->createStub(MessageQueueInterface::class), new DatabaseMessageRecipientDirectory());
        $dispatcher->consume((int) Db::table('message_deliveries')->value('id'));
        $inbox = $messages->inbox('customer', $member, ['page' => 1, 'page_size' => 20]);
        self::assertSame(1, $inbox['total']); self::assertSame($id, $inbox['data'][0]['business_id']);
        self::assertSame('ticket', $inbox['data'][0]['business_type']); self::assertTrue($inbox['data'][0]['unread']);
        $followKey = $this->key(); $follow = $service->execute('member', $member, 'reply', $id, ['content' => '补充资料'], $followKey);
        self::assertSame('open', $service->detail($member, $id)['ticket']['status']);
        $closeKey = $this->key(); $closed = $service->execute('member', $member, 'close', $id, [], $closeKey);
        self::assertSame($closed, $service->execute('member', $member, 'close', $id, [], $closeKey));
        self::assertSame('closed', $service->detail($member, $id)['ticket']['status']);
        self::assertNotNull($service->detail($member, $id)['ticket']['closed_at']);
        self::assertSame($follow, $service->execute('member', $member, 'reply', $id, ['content' => '补充资料'], $followKey), '同键已受理回复在关闭后仍返回原结果');
        $this->rejected(fn () => $service->execute('member', $member, 'reply', $id, ['content' => '关闭后追问'], $this->key()), 409);
        $this->rejected(fn () => $service->execute('admin', 1, 'reply', $id, ['content' => '关闭后回复'], $this->key()), 409);
        self::assertSame(2, Db::table('ticket_messages')->count());
    }

    public function testOwnershipTypeAndInputBoundaries(): void
    {
        $a = $this->member(); $b = $this->member('13800138002'); $service = $this->service(); $input = $this->input();
        $id = $service->create($a, $input)['ticket_id'];
        foreach ([fn () => $service->detail($b, $id), fn () => $service->memberMessages($b, $id, null),
            fn () => $service->execute('member', $b, 'reply', $id, ['content' => '越权'], $this->key()),
            fn () => $service->execute('member', $b, 'close', $id, [], $this->key())] as $action) $this->rejected($action, 404);
        self::assertSame(0, $service->page($b, null, 1, 20)['total']);
        $this->rejected(fn () => $service->create($b, array_replace($input, ['service_id' => 999, 'request_key' => $this->key()])), 422);
        Db::table('ticket_types')->where('id', $input['type_id'])->update(['status' => 0]);
        self::assertSame([], $service->types());
        $this->rejected(fn () => $service->create($a, array_replace($input, ['request_key' => $this->key()])), 422);
        self::assertSame($id, $service->detail($a, $id)['ticket']['id'], '禁用分类不删除历史会话');
        foreach ([['subject' => '  '], ['content' => str_repeat('长', 10001)], ['member_id' => $b], ['status' => 'closed'], ['request_key' => 'x']] as $patch) {
            $this->rejected(fn () => MemberTicketValidator::create(array_replace($input, $patch)), 422);
        }
        $this->rejected(fn () => MemberTicketValidator::page(['member_id' => $a]), 422);
        self::assertSame(20, MemberTicketValidator::page([])['page_size']);
    }

    public function testBoundedConversationPagingAndAdminPermissions(): void
    {
        $member = $this->member(); $service = $this->service(); $id = $service->create($member, $this->input())['ticket_id'];
        for ($i = 1; $i <= 55; $i++) $service->execute('member', $member, 'reply', $id, ['content' => '追问' . $i], $this->key());
        $detail = $service->detail($member, $id); self::assertCount(50, $detail['messages']); self::assertNotNull($detail['next_before_id']);
        $older = $service->memberMessages($member, $id, $detail['next_before_id']); self::assertCount(5, $older['data']); self::assertNull($older['next_before_id']);
        self::assertSame([], array_intersect(array_column($detail['messages'], 'id'), array_column($older['data'], 'id')));
        self::assertArrayNotHasKey('author_id', $detail['messages'][0]);
        $admin = new ManageSupportService(new DatabaseSupportRepository(), new AdminAccessControlService(new DatabaseAdminAccessRepository()), $service);
        $actor = new AdminAccount(1, 'fixture-admin', '管理员', 'hash', 1, null, true);
        self::assertCount(50, $admin->ticket($actor, $id)['messages']);
        self::assertCount(5, $admin->messages($actor, $id, $detail['next_before_id'])['data']);
        $admin->close($actor, $id, $this->key()); self::assertSame('closed', $admin->ticket($actor, $id)['ticket']['status']);
        $operator = new AdminAccount(2, 'no-grant', '无权限', 'hash', 1, null);
        $this->rejected(fn () => $admin->reply($operator, $id, '无权', $this->key()), 403);
        $this->rejected(fn () => $admin->close($operator, $id, $this->key()), 403);
    }

    public function testConfiguredInboxIsNotDuplicatedAndExternalDeliveryFailureDoesNotLoseConversation(): void
    {
        $member = $this->member(); $service = $this->service(); $id = $service->create($member, $this->input())['ticket_id'];
        $cipher = new SodiumSensitiveDataCipher(str_repeat('isolated-message-test-key', 2));
        (new \app\modules\message_configuration\infrastructure\DatabaseMessageConfigurationRepository($cipher, new DatabaseTransactionManager()))
            ->save('customer', 'ticket_replied', 0, ['message' => ['enabled' => true], 'email' => ['enabled' => true]]);
        $service->execute('admin', 1, 'reply', $id, ['content' => '持久回复'], $this->key());
        self::assertSame(1, Db::table('message_deliveries')->where('channel', 'message')->count());
        $transport = $this->createStub(MessageTransportInterface::class); $transport->method('send')->willThrowException(new \RuntimeException('模拟外部网络故障'));
        $messages = new DatabaseMessageRepository($cipher);
        $dispatcher = new DispatchMessageService($messages, $transport, $this->createStub(MessageQueueInterface::class), new DatabaseMessageRecipientDirectory());
        foreach (Db::table('message_deliveries')->pluck('id') as $delivery) $dispatcher->consume((int) $delivery);
        self::assertSame(1, Db::table('message_deliveries')->where('status', 'unknown')->count());
        self::assertSame(1, $messages->inbox('customer', $member, ['page' => 1, 'page_size' => 20])['total']);
        self::assertSame('持久回复', $service->detail($member, $id)['messages'][0]['content']);
        self::assertSame('waiting_member', $service->detail($member, $id)['ticket']['status']);
    }

    public function testMemberListPaginationAndStatusAreScopedBeforePaging(): void
    {
        $member = $this->member(); $other = $this->member('13800138002'); $service = $this->service(); $input = $this->input(); $ids = [];
        for ($i = 0; $i < 25; $i++) $ids[] = $service->create($member, array_replace($input, ['request_key' => $this->key()]))['ticket_id'];
        $service->create($other, array_replace($input, ['request_key' => $this->key()]));
        $service->execute('member', $member, 'close', $ids[0], [], $this->key());
        Db::table('tickets')->update(['updated_at' => '2026-10-19 12:00:00']);
        $one = $service->page($member, null, 1, 20); $two = $service->page($member, null, 2, 20);
        self::assertSame(25, $one['total']); self::assertCount(20, $one['data']); self::assertCount(5, $two['data']);
        self::assertSame($ids[24], $one['data'][0]['id']); self::assertSame([], array_intersect(array_column($one['data'], 'id'), array_column($two['data'], 'id')));
        self::assertSame(1, $service->page($member, 'closed', 1, 20)['total']);
        self::assertSame(24, $service->page($member, 'open', 1, 20)['total']);
        self::assertArrayNotHasKey('content', $one['data'][0]);
    }

    public function testConcurrentDuplicateCommandsAndCloseReplyAreSerialized(): void
    {
        if (Db::connection()->getDriverName() !== 'mysql') self::markTestSkipped('多进程行锁验收仅运行独立MySQL测试库');
        $member = $this->member(); $input = $this->input(); $key = $input['request_key']; unset($input['request_key']);
        $create = ['action' => 'create', 'ticket_id' => null, 'input' => $input, 'key' => $key];
        $created = $this->race($member, [$create, $create]);
        self::assertSame([200, 200], array_column($created, 'status')); self::assertSame($created[0]['ticket_id'], $created[1]['ticket_id']);
        self::assertSame(1, Db::table('tickets')->count()); $id = $created[0]['ticket_id'];
        $reply = ['action' => 'reply', 'ticket_id' => $id, 'input' => ['content' => '并发同键追问'], 'key' => $this->key()];
        $replied = $this->race($member, [$reply, $reply]);
        self::assertSame([200, 200], array_column($replied, 'status')); self::assertSame($replied[0]['message_id'], $replied[1]['message_id']);
        self::assertSame(1, Db::table('ticket_messages')->count());
        $results = $this->race($member, [['action' => 'close', 'ticket_id' => $id, 'input' => [], 'key' => $this->key()], array_replace($reply, ['key' => $this->key()])]);
        self::assertSame(200, $results[0]['status']); self::assertContains($results[1]['status'], [200, 409]);
        self::assertSame('closed', Db::table('tickets')->where('id', $id)->value('status'));
        self::assertSame($results[1]['status'] === 200 ? 2 : 1, Db::table('ticket_messages')->count());
    }

    private function race(int $member, array $commands): array
    {
        $workers = [];
        try {
            foreach ($commands as $command) {
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/support/ticket_command_worker.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
                self::assertIsResource($process); $workers[] = [$process, $pipes];
                fwrite($pipes[0], json_encode(['connection' => Db::connection()->getConfig(), 'member_id' => $member] + $command, JSON_THROW_ON_ERROR) . "\n");
            }
            foreach ($workers as [, $pipes]) self::assertSame("READY\n", fgets($pipes[1]));
            foreach ($workers as [, $pipes]) { fwrite($pipes[0], "GO\n"); fclose($pipes[0]); }
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $error); $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }
            $workers = [];
            return $results;
        } finally {
            foreach ($workers as [$process, $pipes]) { foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); if (is_resource($process)) { proc_terminate($process); proc_close($process); } }
        }
    }
}
