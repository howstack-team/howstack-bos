<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\message\application\DispatchMessageService;
use app\modules\message\application\MessageInboxService;
use app\modules\message\contract\MessageQueueInterface;
use app\modules\message\contract\MessageTransportInterface;
use app\modules\message\infrastructure\DatabaseMessageRepository;
use app\modules\message\infrastructure\DatabaseMessageRecipientDirectory;
use app\modules\message\validation\MessageQueryValidator;
use app\modules\message_configuration\domain\MessageTypeCatalog;
use app\modules\message_configuration\infrastructure\DatabaseMessageConfigurationRepository;
use app\shared\infrastructure\DatabaseTransactionManager;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;
use tests\support\MessageRuntimeFactory;

/** 真迁移、真持久化，运输替身验证队列状态机；不发送真实厂商消息。 */
final class MessageRuntimeTest extends TestCase
{
    use IsolatedBusinessDatabase;

    private function repository(): DatabaseMessageRepository
    {
        return new DatabaseMessageRepository(new SodiumSensitiveDataCipher(str_repeat('isolated-message-test-key', 2)));
    }

    private function configure(string $audience, string $scene, array $channels = ['message'], ?array $policy = null): void
    {
        $configs = [];
        $type = MessageTypeCatalog::get($audience, $scene);
        foreach ($type['channels'] as $channel) {
            $configs[$channel] = MessageTypeCatalog::defaults($type, $channel);
            $configs[$channel]['enabled'] = in_array($channel, $channels, true);
        }
        if ($policy !== null) {
            $configs['_recipients'] = $policy;
        }
        (new DatabaseMessageConfigurationRepository(new SodiumSensitiveDataCipher(str_repeat('isolated-message-test-key', 2)),
            new DatabaseTransactionManager()))->save($audience, $scene, 0, $configs);
    }

    private function transport(string $status = 'accepted'): MessageTransportInterface
    {
        return new class ($status) implements MessageTransportInterface {
            public int $calls = 0;
            public array $requests = [];
            public function __construct(private string $status) {}
            public function send(string $channel, string $requestKey, array $payload): array
            {
                $this->calls++;
                $this->requests[] = ['key' => $requestKey, 'payload' => $payload];
                return ['status' => $this->status, 'error' => $this->status === 'accepted' ? '' : 'provider_' . $this->status,
                    'retry' => $this->status === 'failed'];
            }
        };
    }

    private function queue(bool $available = true): MessageQueueInterface
    {
        return new class ($available) implements MessageQueueInterface {
            public array $ids = [];
            public function __construct(public bool $available) {}
            public function publish(int $deliveryId): bool
            {
                if ($this->available) $this->ids[] = $deliveryId;
                return $this->available;
            }
        };
    }

    private function publish(int $memberId, string $event = 'order.paid.7'): int
    {
        return MessageRuntimeFactory::publisher()->publish($event, 'order_paid', 'order', 7, $memberId,
            ['site_name' => '测试站点', 'order_no' => 'ORDER-PRIVATE', 'amount' => '12.00']);
    }

    public function testIdempotencyEncryptedSnapshotAndBusinessRollback(): void
    {
        $member = $this->member();
        $this->configure('customer', 'order_paid');
        $id = $this->publish($member);
        self::assertSame($id, $this->publish($member));
        self::assertSame(1, Db::table('messages')->count());
        self::assertSame(1, Db::table('message_deliveries')->count());
        $ciphertext = Db::table('message_deliveries')->value('payload_encrypted');
        self::assertStringNotContainsString('ORDER-PRIVATE', $ciphertext);
        self::assertStringNotContainsString('测试会员', $ciphertext);
        Db::beginTransaction();
        $this->publish($member, 'order.paid.rollback');
        Db::rollBack();
        self::assertSame(1, Db::table('messages')->count());
        self::assertStringNotContainsString('ORDER-PRIVATE', json_encode($this->repository()->detail($id), JSON_THROW_ON_ERROR));
    }

    public function testRedisUnavailableDoesNotLoseTasksAndDuplicatesDoNotSendTwice(): void
    {
        $member = $this->member();
        $this->configure('customer', 'order_paid', ['email']);
        $this->publish($member);
        $transport = $this->transport();
        $queue = $this->queue(false);
        $repository = $this->repository();
        $dispatcher = new DispatchMessageService($repository, $transport, $queue, new DatabaseMessageRecipientDirectory());
        $dispatcher->tick();
        self::assertCount(1, $repository->due(100));
        self::assertSame([], $queue->ids);
        $queue->available = true;
        $dispatcher->tick();
        self::assertCount(1, $queue->ids);
        $dispatcher->consume($queue->ids[0]);
        $dispatcher->consume($queue->ids[0]);
        self::assertSame(1, $transport->calls);
        self::assertSame('accepted', Db::table('message_deliveries')->value('status'));
        self::assertSame('accepted', Db::table('message_delivery_attempts')->value('status'));
        self::assertSame(0, $repository->inbox('customer', $member, ['page' => 1, 'page_size' => 10])['total']);
    }

    public function testUnknownAndCrashedExternalAttemptsCannotBeBlindlyRetried(): void
    {
        $member = $this->member();
        $this->configure('customer', 'order_paid', ['sms']);
        $this->publish($member);
        $repository = $this->repository();
        $transport = $this->transport('unknown');
        $dispatcher = new DispatchMessageService($repository, $transport, $this->queue(), new DatabaseMessageRecipientDirectory());
        $id = $repository->due(10)[0];
        $dispatcher->consume($id);
        self::assertSame('unknown', Db::table('message_deliveries')->value('status'));
        $this->assertConflict(fn () => $repository->retry($id));
        $this->publish($member, 'order.paid.crash');
        $crashed = $repository->due(10)[0];
        $repository->claim($crashed);
        Db::table('message_deliveries')->where('id', $crashed)->update(['lease_until' => '2020-01-01 00:00:00']);
        $repository->recoverExpired();
        $dispatcher->consume($crashed);
        self::assertSame(1, $transport->calls);
        self::assertSame('unknown', Db::table('message_deliveries')->where('id', $crashed)->value('status'));
        self::assertSame('worker_lease_expired', Db::table('message_delivery_attempts')->where('delivery_id', $crashed)->value('error_code'));
    }

    public function testFailuresBackOffAndStopAfterThreeAttemptsWithSameRequestKey(): void
    {
        $member = $this->member();
        $this->configure('customer', 'order_paid', ['email']);
        $this->publish($member);
        $repository = $this->repository();
        $transport = $this->transport('failed');
        $dispatcher = new DispatchMessageService($repository, $transport, $this->queue(), new DatabaseMessageRecipientDirectory());
        $id = $repository->due(10)[0];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $dispatcher->consume($id);
            self::assertCount(0, $repository->due(10));
            Db::table('message_deliveries')->where('id', $id)->update(['next_attempt_at' => '2020-01-01 00:00:00']);
        }
        self::assertSame('failed', Db::table('message_deliveries')->value('status'));
        self::assertSame(3, Db::table('message_delivery_attempts')->count());
        self::assertCount(1, array_unique(array_column($transport->requests, 'key')));
        $repository->retry($id);
        self::assertCount(1, $repository->due(10));
    }

    public function testInboxReadIsPersistedAndScopedToTheAuthenticatedRecipient(): void
    {
        $member = $this->member();
        $other = $this->member('13800138002');
        $this->configure('customer', 'order_paid');
        $this->publish($member);
        $repository = $this->repository();
        $dispatcher = new DispatchMessageService($repository, $this->transport(), $this->queue(), new DatabaseMessageRecipientDirectory());
        $dispatcher->consume($repository->due(10)[0]);
        $inbox = new MessageInboxService($repository, new MessageQueryValidator());
        self::assertSame(1, $inbox->summary('customer', $member)['unread']);
        self::assertSame(0, $inbox->summary('customer', $other)['unread']);
        $row = $inbox->list('customer', $member, [])['data'][0];
        self::assertStringContainsString('ORDER-PRIVATE', $row['body']);
        try {
            $inbox->read('customer', $other, $row['id']);
            self::fail('不得阅读其他客户消息');
        } catch (BusinessException $error) {
            self::assertSame(404, $error->httpStatus());
        }
        $inbox->read('customer', $member, $row['id']);
        self::assertSame(0, (new MessageInboxService($this->repository(), new MessageQueryValidator()))->summary('customer', $member)['unread']);
        self::assertSame(0, $inbox->list('customer', $member, ['topic' => 'ticket'])['total']);
    }

    public function testRolesDepartmentsDeduplicateAndRobotGroupIsSentOnce(): void
    {
        $now = date('Y-m-d H:i:s');
        $member = $this->member();
        $department = $this->row('admin_departments', ['name' => '客服', 'created_at' => $now, 'updated_at' => $now]);
        $second = $this->row('admin_users', ['username' => 'second', 'nickname' => '客服二', 'department_id' => $department,
            'password_hash' => 'hash', 'created_at' => $now, 'updated_at' => $now]);
        Db::table('admin_users')->where('id', 1)->update(['department_id' => $department]);
        $role = $this->row('admin_roles', ['name' => '客服角色', 'code' => 'service', 'created_at' => $now, 'updated_at' => $now]);
        $this->row('admin_user_roles', ['admin_user_id' => 1, 'role_id' => $role]);
        $directory = new DatabaseMessageRecipientDirectory();
        self::assertCount(2, $directory->recipients('admin', 0, ['mode' => 'departments', 'ids' => [$department]]));
        self::assertCount(1, $directory->recipients('admin', 0, ['mode' => 'roles', 'ids' => [$role, $role]]));
        $this->configure('admin', 'order_paid', ['message', 'dingtalk'], ['mode' => 'members', 'ids' => [1, $second]]);
        $this->publish($member);
        self::assertSame(3, Db::table('message_recipients')->count());
        self::assertSame(3, Db::table('message_deliveries')->count());
        self::assertSame(1, Db::table('message_deliveries')->where('channel', 'dingtalk')->count());
    }

    public function testTemplateChangesDoNotRewritePublishedTasksAndCodeCannotEnterNotificationLogs(): void
    {
        $member = $this->member();
        $this->configure('customer', 'order_paid');
        $this->publish($member);
        $key = Db::table('message_deliveries')->value('payload_encrypted');
        Db::table('message_configurations')->delete();
        self::assertSame($key, Db::table('message_deliveries')->value('payload_encrypted'));
        $this->expectException(\InvalidArgumentException::class);
        MessageRuntimeFactory::publisher()->publish('verify.1', 'login_verification', 'member', $member, $member, ['code' => '123456']);
    }

    public function testBusinessRechargeAndOrderPaymentCreateExactlyOneMessagePerCommittedEvent(): void
    {
        $member = $this->member();
        Db::table('admin_users')->where('id', 1)->update(['is_founder' => 1]);
        $this->configure('customer', 'recharge_received');
        $this->configure('customer', 'order_paid');
        $this->configure('admin', 'order_paid');
        $transactions = new DatabaseTransactionManager();
        $publisher = MessageRuntimeFactory::publisher();
        $wallet = new \app\modules\finance\application\WalletLedgerService(
            new \app\modules\finance\infrastructure\DatabaseWalletLedgerRepository(), $transactions, $publisher);
        $payment = new \app\modules\order\application\PayOrderByWalletService(
            new \app\modules\order\infrastructure\DatabaseOrderPaymentRepository(), $wallet, $transactions,
            new \app\modules\finance\infrastructure\DatabaseOutboxWriter(), $publisher);
        $recharge = $this->recharge($member);
        $order = $this->order($member);
        $wallet->creditConfirmedRecharge($member, $recharge);
        $wallet->creditConfirmedRecharge($member, $recharge);
        $payment->pay($member, $order);
        $payment->pay($member, $order);
        self::assertSame(2, Db::table('messages')->count());
        self::assertSame(3, Db::table('message_deliveries')->count());
        $repository = $this->repository();
        $dispatcher = new DispatchMessageService($repository, $this->transport(), $this->queue(), new DatabaseMessageRecipientDirectory());
        foreach ($repository->due(10) as $id) $dispatcher->consume($id);
        self::assertSame(2, $repository->summary('customer', $member)['unread']);
        self::assertSame(1, $repository->summary('admin', 1)['unread']);
        self::assertStringContainsString('12.00', $repository->inbox('customer', $member, ['page' => 1, 'page_size' => 10])['data'][0]['body']);
    }

    public function testReplyAndRealNameReviewPublishInTheSameTransactionAndOnlyToCustomers(): void
    {
        $member = $this->member();
        Db::table('admin_users')->where('id', 1)->update(['is_founder' => 1]);
        foreach (['ticket_replied', 'real_name_approved'] as $scene) $this->configure('customer', $scene);
        $this->configure('admin', 'ticket_replied');
        $access = new \app\modules\admin\access\application\AdminAccessControlService(
            $this->createStub(\app\modules\admin\access\contract\AdminAccessRepositoryInterface::class));
        $actor = new \app\modules\admin\auth\domain\AdminAccount(1, 'founder', '测试管理员', 'hash', 1, null, true);
        $repository = new \app\modules\support\infrastructure\DatabaseSupportRepository();
        $type = $repository->saveCatalog('ticket_types', null, ['name' => '咨询', 'code' => 'consult', 'status' => 1]);
        $now = date('Y-m-d H:i:s');
        $ticket = $this->row('tickets', ['ticket_no' => 'TEST-TICKET', 'member_id' => $member, 'type_id' => $type,
            'subject' => '测试咨询', 'content' => '问题', 'created_at' => $now, 'updated_at' => $now]);
        $service = new \app\modules\support\application\ManageSupportService($repository, $access,
            MessageRuntimeFactory::publisher(), new DatabaseTransactionManager());
        Db::beginTransaction();
        $service->reply($actor, $ticket, '受控测试回复');
        self::assertSame(1, Db::table('messages')->count());
        Db::rollBack();
        self::assertSame([], $repository->messages($ticket));
        self::assertSame(0, Db::table('messages')->count());
        $service->reply($actor, $ticket, '已处理');
        self::assertSame(1, Db::table('message_recipients')->where('audience', 'customer')->count());
        self::assertSame(0, Db::table('message_recipients')->where('audience', 'admin')->count());
        $records = new \app\modules\member\infrastructure\DatabaseRealNameAuthenticationRepository();
        $authentication = $records->create(['member_id' => $member, 'authentication_type' => 'personal', 'status' => 'pending',
            'subject_name' => '测试会员', 'id_type' => 'identity_card', 'id_number_ciphertext' => 'secret',
            'id_number_hash' => str_repeat('a', 64), 'id_number_masked' => '110***********1234']);
        $reviews = new \app\modules\member\application\RealNameManagementService($records, $access,
            MessageRuntimeFactory::publisher(), new DatabaseTransactionManager());
        Db::beginTransaction();
        $reviews->review($actor, $authentication, 'approved', '');
        Db::rollBack();
        self::assertSame('pending', $records->find($authentication)['status']);
        self::assertSame(1, Db::table('messages')->count());
        $reviews->review($actor, $authentication, 'approved', '');
        self::assertSame(2, Db::table('messages')->count());
        self::assertSame(2, Db::table('message_deliveries')->count());
        try { $reviews->review($actor, $authentication, 'approved', ''); self::fail('不得重复审核'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        self::assertSame(2, Db::table('messages')->count());
    }

    public function testLogViewingDoesNotGrantRetryPermission(): void
    {
        $member = $this->member();
        $this->configure('customer', 'order_paid', ['email']);
        $message = $this->publish($member);
        $repository = $this->repository();
        $id = $repository->due(10)[0];
        $task = $repository->claim($id);
        $repository->finish($id, $task['lease_key'], 'failed', 'channel_or_recipient_invalid', 0, false);
        $grants = $this->createStub(\app\modules\admin\access\contract\AdminAccessRepositoryInterface::class);
        $grants->method('grantsForAdmin')->willReturn([['permissions' => ['admin.message-logs.view'], 'data_scope' => 'all', 'department_ids' => []]]);
        $logs = new \app\modules\message\application\ManageMessageLogsService($repository,
            new \app\modules\admin\access\application\AdminAccessControlService($grants), new MessageQueryValidator());
        $actor = new \app\modules\admin\auth\domain\AdminAccount(7, 'operator', '运营', 'hash', 1, null);
        self::assertSame($message, $logs->detail($actor, $message)['id']);
        try { $logs->retry($actor, $id); self::fail('只读角色不得重试'); }
        catch (BusinessException $error) { self::assertSame(403, $error->httpStatus()); }
        self::assertSame('failed', Db::table('message_deliveries')->value('status'));
    }

    private function assertConflict(callable $action): void
    {
        try { $action(); self::fail('结果未知不能自动重试'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
    }

    public function testLogFiltersAggregateWholeMessagesBeforePaginationAndMatchOneDelivery(): void
    {
        $repository = $this->repository();
        $cases = [
            'inactive' => [], 'pending' => ['pending', 'delivered'],
            'processing' => ['processing', 'failed'], 'accepted' => ['accepted', 'delivered'],
            'delivered' => ['delivered', 'delivered'], 'partial' => ['delivered', 'failed'],
            'failed' => ['failed', 'failed'], 'unknown' => ['unknown', 'pending'],
        ];
        $ids = [];
        foreach ($cases as $outcome => $statuses) {
            $targets = [];
            foreach ($statuses as $index => $status) {
                $targets[] = ['audience' => $index === 0 ? 'customer' : 'admin', 'account_id' => 1,
                    'target_key' => 'test:' . $index, 'channel' => $index === 0 ? 'message' : 'email',
                    'channel_name' => $index === 0 ? '' : 'SMTP 原通道', 'payload' => ['private' => 'must-not-leak']];
            }
            $id = Db::transaction(fn (): int => $repository->record(hash('sha256', $outcome), 'order_paid', 'order', 7, $targets));
            $ids[$outcome] = $id;
            Db::table('messages')->where('id', $id)->update(['created_at' => '2026-10-07 23:59:59']);
            $deliveries = Db::table('message_deliveries as d')->join('message_recipients as r', 'd.recipient_id', '=', 'r.id')
                ->where('r.message_id', $id)->orderBy('d.id')->pluck('d.id');
            foreach ($deliveries as $index => $delivery) {
                Db::table('message_deliveries')->where('id', $delivery)->update(['status' => $statuses[$index]]);
            }
        }
        $validator = new MessageQueryValidator();
        foreach ($ids as $outcome => $id) {
            $result = $repository->logs($validator->query(['status' => $outcome]));
            self::assertSame(1, $result['total'], $outcome);
            self::assertSame($id, $result['data'][0]['id']);
            self::assertSame($outcome, $result['data'][0]['status']);
        }
        $query = ['status' => 'partial', 'channel' => 'email', 'delivery_status' => 'failed', 'audience' => 'admin',
            'business_type' => 'order', 'business_id' => '7', 'created_from' => '2026-10-07', 'created_to' => '2026-10-07'];
        self::assertSame($ids['partial'], $repository->logs($validator->query($query))['data'][0]['id']);
        self::assertSame(0, $repository->logs($validator->query(array_replace($query, ['audience' => 'customer'])))['total']);
        self::assertSame(0, $repository->logs($validator->query(array_replace($query, ['business_id' => 8])))['total']);
        self::assertSame(0, $repository->logs($validator->query(array_replace($query, ['created_to' => '2026-10-06', 'created_from' => '2026-10-06'])))['total']);
        $secondPage = $repository->logs($validator->query($query + ['page' => 2]));
        self::assertSame(1, $secondPage['total']);
        self::assertSame([], $secondPage['data']);
        self::assertSame(8, $repository->logs($validator->query(['created_to' => '2026-10-07']))['total']);
        self::assertStringNotContainsString('must-not-leak', json_encode($repository->detail($ids['partial'])));
        self::assertSame('SMTP 原通道', $repository->detail($ids['partial'])['deliveries'][1]['channel_name']);
    }

    public function testChannelNameSnapshotSurvivesLaterPlatformRename(): void
    {
        $member = $this->member();
        $id = $this->row('channel_connections', ['category' => 'mail', 'name' => '邮件连接', 'plugin_id' => 'smtp',
            'platform_snapshot' => '{"name":"SMTP"}', 'secret_keys' => '[]', 'configuration_encrypted' => 'unused',
            'status' => 1, 'created_at' => '2026-10-07 10:00:00', 'updated_at' => '2026-10-07 10:00:00']);
        $this->configure('customer', 'order_paid', ['email']);
        $configs = new DatabaseMessageConfigurationRepository(new SodiumSensitiveDataCipher(str_repeat('isolated-message-test-key', 2)), new DatabaseTransactionManager());
        $stored = $configs->get('customer', 'order_paid');
        $stored['configurations']['email']['channel_id'] = $id;
        $configs->save('customer', 'order_paid', $stored['revision'], $stored['configurations']);
        $message = $this->publish($member);
        Db::table('channel_connections')->where('id', $id)->update(['platform_snapshot' => '{"name":"新名称"}']);
        self::assertSame('SMTP', $this->repository()->detail($message)['deliveries'][0]['channel_name']);
        if (getenv('HOWSTACK_MYSQL_TEST') === '1') {
            $connection = Db::connection();
            $column = $connection->selectOne('SELECT COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$connection->getDatabaseName(), $connection->getTablePrefix() . 'message_deliveries', 'channel_name']);
            self::assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $column->COLUMN_COMMENT);
        }
    }
}
