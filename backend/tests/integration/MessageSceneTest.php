<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\message_configuration\application\ManageMessageConfigurationsService;
use app\modules\message_configuration\application\ManageMessageScenesService;
use app\modules\message_configuration\domain\MessageTypeCatalog;
use app\modules\message_configuration\infrastructure\DatabaseMessageConfigurationRepository;
use app\modules\message_configuration\validation\MessageConfigurationValidator;
use app\modules\message_configuration\validation\BatchMessageConfigurationValidator;
use app\modules\message_configuration\validation\MessageRecipientPolicyValidator;
use app\modules\message\infrastructure\DatabaseMessageRecipientDirectory;
use app\modules\message\infrastructure\DatabaseMessageRepository;
use app\modules\message\domain\MessageOutcome;
use app\modules\system_settings\infrastructure\DatabaseNotificationRobotSettingsRepository;
use app\shared\infrastructure\DatabaseTransactionManager;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;
use tests\support\MessageRuntimeFactory;

/** 真实隔离数据库验证多组策略与投递快照，不连接外部厂商。 */
final class MessageSceneTest extends TestCase
{
    use IsolatedBusinessDatabase;

    private function fixture(array $permissions = []): array
    {
        $accessRepository = $this->createStub(AdminAccessRepositoryInterface::class);
        $accessRepository->method('grantsForAdmin')->willReturn([['permissions' => $permissions]]);
        $access = new AdminAccessControlService($accessRepository);
        $channels = $this->createStub(ChannelRepositoryInterface::class);
        $channels->method('list')->willReturn(['data' => [], 'total' => 0]);
        $channels->method('find')->willReturn(['id' => 1, 'revision' => 4, 'status' => 1, 'platform_available' => true, 'configuration_current' => true, 'plugin_id' => 'smtp']);
        $cipher = new SodiumSensitiveDataCipher(str_repeat('isolated-message-test-key', 2));
        $transactions = new DatabaseTransactionManager();
        $repository = new DatabaseMessageConfigurationRepository($cipher, $transactions);
        $directory = new DatabaseMessageRecipientDirectory();
        $legacy = new ManageMessageConfigurationsService($access, $repository, $channels, new MessageConfigurationValidator(),
            new DatabaseNotificationRobotSettingsRepository($cipher, $transactions), $transactions, new BatchMessageConfigurationValidator(), $directory);
        $scenes = new ManageMessageScenesService($access, $repository, $legacy, $directory, $transactions, new MessageRecipientPolicyValidator());
        return [$scenes, new AdminAccount(1, 'founder', '测试管理员', 'hidden', 1, null, true), $repository, $legacy,
            new AdminAccount(1, 'viewer', '查看管理员', 'hidden', 1, null), $cipher];
    }

    private function input(array $detail): array
    {
        $groups = $detail['groups'];
        foreach ($groups as &$group) $group['configurations'] = (array) $group['configurations'];
        unset($group);
        return ['revisions' => (array) $detail['revisions'], 'groups' => $groups];
    }

    private function rejected(int $status, callable $action): void
    {
        try { $action(); self::fail('请求应被拒绝'); }
        catch (BusinessException $error) { self::assertSame($status, $error->httpStatus(), $error->getMessage()); }
    }

    public function testSceneUnifiesAudiencesAndOldConfigurationsArePreservedWithoutWriteOnRead(): void
    {
        [$scenes, $actor, $repository, $legacy] = $this->fixture();
        $catalog = $scenes->list($actor, ['page_size' => 100]);
        self::assertCount(count(MessageTypeCatalog::scenes()), $catalog['data']);
        self::assertCount(1, array_filter($catalog['data'], static fn (array $row): bool => $row['code'] === 'order_paid'));
        $reply = array_values(array_filter($catalog['data'], static fn (array $row): bool => $row['code'] === 'ticket_replied'))[0];
        self::assertSame('工单回复', $reply['metadata']->customer['name']);
        self::assertSame('客户回复工单', $reply['metadata']->admin['name']);
        self::assertSame(['email', 'message', 'wechat'], $reply['metadata']->customer['channels']);
        self::assertSame(0, Db::table('message_configurations')->count());
        $old = $legacy->get($actor, 'admin', 'order_paid');
        $old['configurations']->message = (array) $old['configurations']->message;
        $old['configurations']->message['enabled'] = true;
        $old['configurations']->message['body'] = '原有模板 {{order_no}}';
        $legacy->save($actor, 'admin', 'order_paid', ['revision' => 0, 'recipients' => ['mode' => 'members', 'ids' => [1]], 'configurations' => array_map(static fn (mixed $config): array => (array) $config, (array) $old['configurations'])]);
        $detail = $scenes->get($actor, 'order_paid');
        $internal = array_values(array_filter($detail['groups'], static fn (array $group): bool => $group['kind'] === 'admin'))[0];
        self::assertSame('原有模板 {{order_no}}', $internal['configurations']->message['body']);
        self::assertSame(['mode' => 'members', 'ids' => [1]], $internal['recipients']);
        self::assertArrayNotHasKey('_groups', $repository->get('admin', 'order_paid')['configurations']);
        $saved = $scenes->save($actor, 'order_paid', $this->input($detail));
        self::assertSame(['customer' => 1, 'admin' => 2], (array) $saved['revisions']);
        self::assertStringNotContainsString('webhook_url', json_encode($saved, JSON_THROW_ON_ERROR));
        $this->rejected(409, fn () => $legacy->save($actor, 'admin', 'order_paid', ['revision' => 2, 'configurations' => array_map(static fn (mixed $config): array => (array) $config, (array) $old['configurations'])]));
    }

    public function testVerificationScenesExcludeEmailFromGroupsAndRejectSingleAndBatchWrites(): void
    {
        [$scenes, $actor, $repository] = $this->fixture();
        foreach (['login_verification'] as $code) {
            $detail = $scenes->get($actor, $code);
            $historical = $this->input($detail)['groups'];
            $historical[0]['configurations']['email'] = ['enabled' => true, 'channel_id' => 1, 'title' => '旧验证码', 'body' => '{{code}}'];
            $repository->save('customer', $code, 0, ['_groups' => $historical]);
            $detail = $scenes->get($actor, $code);
            self::assertSame(['sms'], $detail['metadata']->customer['channels']);
            self::assertSame(['sms'], array_keys((array) $detail['groups'][0]['configurations']));
            self::assertSame(['sms'], array_keys($detail['group_templates']->customer['configurations']));
            self::assertArrayNotHasKey('email', (array) $detail['channel_options']);
            $input = $this->input($detail);
            $input['groups'][0]['configurations']['email'] = $historical[0]['configurations']['email'];
            $this->rejected(422, fn () => $scenes->save($actor, $code, $input));
            $this->rejected(422, fn () => $scenes->batch($actor, [
                'items' => [['code' => $code, 'revisions' => (array) $detail['revisions']]],
                'kind' => 'customer', 'channel' => 'email', 'enabled' => true,
            ]));
            self::assertSame(1, $repository->get('customer', $code)['revision']);
            self::assertSame($historical, $repository->get('customer', $code)['configurations']['_groups']);
            $saved = $scenes->save($actor, $code, $this->input($detail));
            self::assertSame(['sms'], array_keys((array) $saved['groups'][0]['configurations']));
        }
    }

    public function testMultipleGroupsCreateSnapshotsAndOverlappingRecipientsUseFirstTemplateOnce(): void
    {
        [$scenes, $actor, , , , $cipher] = $this->fixture();
        $member = $this->member();
        $input = $this->input($scenes->get($actor, 'order_paid'));
        $input['groups'][0]['configurations']['message']['enabled'] = true;
        $input['groups'][0]['configurations']['message']['body'] = '客户模板 {{order_no}}';
        $input['groups'][1]['recipients'] = ['mode' => 'members', 'ids' => [1]];
        $input['groups'][1]['configurations']['message']['enabled'] = true;
        $input['groups'][1]['configurations']['message']['body'] = '首组模板 {{order_no}}';
        $input['groups'][1]['name'] = '运营团队';
        $duplicate = $input['groups'][1]; $duplicate['id'] = 'second-team'; $duplicate['name'] = '售后团队';
        $duplicate['configurations']['message']['body'] = '后组模板';
        $duplicate['configurations']['email']['enabled'] = true; $duplicate['configurations']['email']['channel_id'] = 1;
        $input['groups'][] = $duplicate;
        $scenes->save($actor, 'order_paid', $input);
        $publisher = MessageRuntimeFactory::publisher();
        $id = $publisher->publish('paid.test', 'order_paid', 'order', 7, $member, ['order_no' => 'ORDER-GROUP-PRIVATE']);
        self::assertSame($id, $publisher->publish('paid.test', 'order_paid', 'order', 7, $member, []));
        self::assertSame(3, Db::table('message_deliveries')->count()); // 客户站内、管理员站内、管理员邮件
        self::assertSame(1, Db::table('message_deliveries as d')->join('message_recipients as r', 'd.recipient_id', '=', 'r.id')->where('r.audience', 'admin')->where('d.channel', 'message')->count());
        $row = Db::table('message_deliveries as d')->join('message_recipients as r', 'd.recipient_id', '=', 'r.id')->where('r.audience', 'admin')->where('d.channel', 'message')->first();
        self::assertSame('运营团队', $row->group_name);
        self::assertSame('首组模板 ORDER-GROUP-PRIVATE', json_decode($cipher->decrypt($row->payload_encrypted), true)['body']);
        $repository = new DatabaseMessageRepository($cipher);
        $detail = $repository->detail($id);
        self::assertSame('pending', $detail['status']);
        self::assertCount(3, $detail['groups']);
        self::assertStringNotContainsString('ORDER-GROUP-PRIVATE', json_encode($detail));
        self::assertStringNotContainsString('payload_encrypted', json_encode($detail));
        $requestKeys = Db::table('message_deliveries')->pluck('request_key')->all();
        $changed = $this->input($scenes->get($actor, 'order_paid'));
        $changed['groups'] = [];
        $scenes->save($actor, 'order_paid', $changed);
        self::assertSame($requestKeys, Db::table('message_deliveries')->pluck('request_key')->all());
        $publisher->publish('paid.next', 'order_paid', 'order', 8, $member, []);
        self::assertSame(3, Db::table('message_deliveries')->count());
    }

    public function testAudienceRevisionConflictRollsBackOtherAudienceAndBulkPreservesTemplates(): void
    {
        [$scenes, $actor, $repository] = $this->fixture();
        $input = $this->input($scenes->get($actor, 'order_paid'));
        $repository->save('admin', 'order_paid', 0, []);
        $this->rejected(409, fn () => $scenes->save($actor, 'order_paid', $input));
        self::assertSame(0, $repository->get('customer', 'order_paid')['revision']);
        $scene = $scenes->get($actor, 'order_paid');
        $scenes->batch($actor, ['items' => [['code' => 'order_paid', 'revisions' => (array) $scene['revisions']]], 'kind' => 'customer', 'channel' => 'message', 'enabled' => true]);
        $after = $scenes->get($actor, 'order_paid');
        self::assertTrue($after['groups'][0]['configurations']->message['enabled']);
        self::assertSame($scene['groups'][0]['configurations']->message['body'], $after['groups'][0]['configurations']->message['body']);
        $before = $repository->get('customer', 'order_paid');
        $this->rejected(422, fn () => $scenes->batch($actor, ['items' => [['code' => 'order_paid', 'revisions' => (array) $after['revisions']], ['code' => 'service_due', 'revisions' => ['customer' => 0]]], 'kind' => 'customer', 'channel' => 'message', 'enabled' => true, 'channel_id' => 1]));
        self::assertSame($before, $repository->get('customer', 'order_paid'));
        $this->rejected(422, fn () => $scenes->batch($actor, ['items' => [['code' => 'order_paid', 'revisions' => (array) $after['revisions']], ['code' => 'service_due', 'revisions' => ['customer' => 0]]], 'kind' => 'admin', 'channel' => 'message', 'enabled' => true]));
        self::assertSame($before, $repository->get('customer', 'order_paid'));
    }

    public function testPermissionsAudienceAndUnsupportedVariablesAreEnforced(): void
    {
        [$scenes, $actor, , , $viewer] = $this->fixture(['admin.message-configurations.view']);
        $input = $this->input($scenes->get($viewer, 'order_paid'));
        $this->rejected(403, fn () => $scenes->save($viewer, 'order_paid', $input));
        $input['groups'][0]['configurations']['message']['body'] = '{{code}}';
        $this->rejected(422, fn () => $scenes->save($actor, 'order_paid', $input));
        $input = $this->input($scenes->get($actor, 'login_verification'));
        $input['groups'][0]['kind'] = 'admin';
        $this->rejected(422, fn () => $scenes->save($actor, 'login_verification', $input));
        $input = $this->input($scenes->get($actor, 'order_paid'));
        $input['groups'][0]['recipients'] = ['mode' => 'members', 'ids' => [1]];
        $this->rejected(422, fn () => $scenes->save($actor, 'order_paid', $input));
        $input = $this->input($scenes->get($actor, 'order_paid'));
        $input['groups'][2]['configurations']['dingtalk']['webhook_url'] = 'https://evil.invalid';
        $this->rejected(422, fn () => $scenes->save($actor, 'order_paid', $input));
        $input = $this->input($scenes->get($actor, 'order_paid'));
        $input['groups'][] = $input['groups'][0];
        $this->rejected(422, fn () => $scenes->save($actor, 'order_paid', $input));
        self::assertSame(0, Db::table('message_configurations')->count());
    }

    public function testDisablingGroupStopsSendingWithoutErasingChannelSwitches(): void
    {
        [$scenes, $actor] = $this->fixture();
        $input = $this->input($scenes->get($actor, 'order_paid'));
        $input['groups'][0]['enabled'] = false;
        $input['groups'][0]['configurations']['email']['enabled'] = true;
        $input['groups'][0]['configurations']['email']['channel_id'] = 0;
        $saved = $scenes->save($actor, 'order_paid', $input);
        self::assertFalse($saved['groups'][0]['enabled']);
        self::assertTrue($saved['groups'][0]['configurations']->email['enabled']);
        MessageRuntimeFactory::publisher()->publish('disabled.group', 'order_paid', 'order', 9, $this->member(), []);
        self::assertSame(0, Db::table('message_deliveries')->count());
        $input = $this->input($saved);
        $input['groups'][0]['enabled'] = true;
        $this->rejected(422, fn () => $scenes->save($actor, 'order_paid', $input));
    }

    public function testLogsAggregateActualOutcomesAndChannelStatusFiltersReferToSameDelivery(): void
    {
        [$scenes, $actor, , , , $cipher] = $this->fixture();
        $input = $this->input($scenes->get($actor, 'order_paid'));
        foreach (['message', 'email'] as $channel) $input['groups'][0]['configurations'][$channel]['enabled'] = true;
        $input['groups'][0]['configurations']['email']['channel_id'] = 1;
        $scenes->save($actor, 'order_paid', $input);
        $id = MessageRuntimeFactory::publisher()->publish('log.test', 'order_paid', 'order', 4, $this->member(), []);
        $repository = new DatabaseMessageRepository($cipher);
        Db::table('message_deliveries')->where('channel', 'message')->update(['status' => 'delivered']);
        Db::table('message_deliveries')->where('channel', 'email')->update(['status' => 'failed']);
        self::assertSame('partial', $repository->detail($id)['status']);
        self::assertSame(1, $repository->logs(['page' => 1, 'page_size' => 10, 'channel' => 'email', 'delivery_status' => 'failed', 'purpose' => 'notification'])['total']);
        self::assertSame(0, $repository->logs(['page' => 1, 'page_size' => 10, 'channel' => 'message', 'delivery_status' => 'failed'])['total']);
        self::assertSame('accepted', MessageOutcome::aggregate(['accepted' => 1, 'delivered' => 1]));
        self::assertSame('unknown', MessageOutcome::aggregate(['accepted' => 1, 'unknown' => 1]));
        self::assertSame('pending', MessageOutcome::aggregate(['failed' => 1, 'pending' => 1]));
        self::assertSame('inactive', MessageOutcome::aggregate([]));
        $audit = (new \app\modules\admin\operation\application\OperationLogPayloadSanitizer())->params('message-scenes', 'order_paid', ['revisions' => ['customer' => 1, 'admin' => 2], 'groups' => [['body' => 'PRIVATE_BODY', 'webhook_url' => 'PRIVATE_WEBHOOK']]]);
        self::assertStringNotContainsString('PRIVATE', $audit);
        self::assertStringContainsString('order_paid', $audit);
    }
}
