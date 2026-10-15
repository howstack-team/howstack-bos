<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\message_configuration\application\ManageMessageConfigurationsService;
use app\modules\message_configuration\domain\MessageTypeCatalog;
use app\modules\message_configuration\infrastructure\DatabaseMessageConfigurationRepository;
use app\modules\message_configuration\validation\MessageConfigurationValidator;
use app\shared\infrastructure\DatabaseTransactionManager;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 使用真实迁移与加密持久化，不发送邮件、短信或机器人请求。 */
final class MessageConfigurationTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testCatalogSeparatesAudienceAndUnsupportedChannelsAndFiltersWithAnd(): void
    {
        [$service, $actor] = $this->fixture();
        $customers = $service->list($actor, 'customer', ['page_size' => 100]);
        $admins = $service->list($actor, 'admin', ['page_size' => 100]);
        self::assertSame(19, $customers['total']);
        self::assertSame(11, $admins['total']);
        self::assertSame(['sms' => false, 'email' => false], (array) $customers['data'][0]['channels']);
        foreach ($admins['data'] as $row) {
            self::assertSame(['email', 'dingtalk', 'wecom', 'feishu', 'message'], array_keys((array) $row['channels']));
        }
        self::assertSame(0, Db::table('message_configurations')->count());
        self::assertSame(1, $service->list($actor, 'customer', ['name' => '订单', 'description' => '收款'])['total']);
        self::assertCount(1, $service->list($actor, 'admin', ['page' => 2, 'page_size' => 10])['data']);
        $this->rejected(422, fn () => $service->list($actor, 'invalid', []));
        $this->rejected(404, fn () => $service->get($actor, 'customer', 'delivery_failed'));
    }

    public function testRegistrationAndLoginOnlyAllowSmsEvenWithHistoricalEmailConfiguration(): void
    {
        [$service, $actor, $repository] = $this->fixture();
        foreach (['login_verification'] as $code) {
            $input = $this->input('customer', $code);
            $historical = $input['configurations'];
            $historical['email'] = ['enabled' => true, 'channel_id' => 7, 'title' => '旧邮件验证码', 'body' => '{{code}}'];
            $repository->save('customer', $code, 0, $historical);
            $detail = $service->get($actor, 'customer', $code);
            self::assertSame(['sms'], $detail['channels']);
            self::assertSame(['sms'], array_keys((array) $detail['configurations']));
            self::assertArrayNotHasKey('email', (array) $detail['channel_options']);
            $input['revision'] = 1;
            $input['configurations']['email'] = $historical['email'];
            $this->rejected(422, fn () => $service->save($actor, 'customer', $code, $input));
            $this->rejected(422, fn () => $service->batch($actor, 'customer', [
                'items' => [['code' => $code, 'revision' => 1]], 'channel' => 'email', 'enabled' => true,
            ]));
            self::assertSame($historical, $repository->get('customer', $code)['configurations']);
            unset($input['configurations']['email']);
            $saved = $service->save($actor, 'customer', $code, $input);
            self::assertSame(['sms'], array_keys((array) $saved['configurations']));
        }
        self::assertSame(['email', 'sms'], MessageTypeCatalog::get('customer', 'password_reset')['channels']);
    }

    public function testNewTableAndEveryColumnHaveChineseComments(): void
    {
        if (getenv('HOWSTACK_MYSQL_TEST') !== '1') {
            self::markTestSkipped('字段原生注释需要 MySQL 验证');
        }
        $connection = Db::connection();
        $columns = $connection->select('SELECT COLUMN_NAME, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$connection->getDatabaseName(), $connection->getTablePrefix() . 'message_configurations']);
        self::assertCount(7, $columns);
        foreach ($columns as $column) {
            self::assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $column->COLUMN_COMMENT, $column->COLUMN_NAME);
        }
    }

    public function testAdministratorRecipientPolicyIsValidatedAndSurvivesBatchConfiguration(): void
    {
        [$service, $actor] = $this->fixture();
        $input = $this->input('admin', 'order_paid');
        $input['recipients'] = ['mode' => 'members', 'ids' => [1]];
        $saved = $service->save($actor, 'admin', 'order_paid', $input);
        self::assertSame($input['recipients'], $saved['recipients']);
        self::assertSame(1, $saved['recipient_options']->members[0]['id']);
        self::assertArrayNotHasKey('email', $saved['recipient_options']->members[0]);
        $service->batch($actor, 'admin', ['items' => [['code' => 'order_paid', 'revision' => 1]], 'channel' => 'message', 'enabled' => true]);
        self::assertSame($input['recipients'], $service->get($actor, 'admin', 'order_paid')['recipients']);
        $input['revision'] = 2;
        $input['recipients']['ids'] = [9999];
        $this->rejected(422, fn () => $service->save($actor, 'admin', 'order_paid', $input));
        Db::table('admin_users')->where('id', 1)->update(['status' => 0]);
        $input['recipients']['ids'] = [1];
        $this->rejected(422, fn () => $service->save($actor, 'admin', 'order_paid', $input));
        $customer = $this->input('customer', 'order_paid');
        $customer['recipients'] = ['mode' => 'members', 'ids' => [1]];
        $this->rejected(422, fn () => $service->save($actor, 'customer', 'order_paid', $customer));
    }

    public function testEveryMessageCanSaveDisabledConfigurationsAndKeepTabsTogether(): void
    {
        [$service, $actor] = $this->fixture();
        foreach (array_keys(MessageTypeCatalog::CHANNELS) as $audience) {
            foreach (MessageTypeCatalog::all($audience) as $code => $type) {
                $row = $service->save($actor, $audience, $code, $this->input($audience, $code));
                self::assertSame(1, $row['revision']);
                self::assertSame($type['channels'], array_keys((array) $row['configurations']));
            }
        }
        self::assertSame(30, Db::table('message_configurations')->count());
    }

    public function testRobotCredentialsAreGlobalAndCannotBeSubmittedPerMessage(): void
    {
        [$service, $actor, $repository, , $robots] = $this->fixture();
        $input = $this->input('admin', 'order_paid');
        $input['configurations']['dingtalk']['enabled'] = true;
        $this->rejected(422, fn () => $service->save($actor, 'admin', 'order_paid', $input));
        $shared = \app\modules\system_settings\domain\NotificationRobotDefinition::defaults();
        $shared['dingtalk'] = ['webhook_url' => 'https://oapi.dingtalk.com/robot/send?access_token=PRIVATE_TEST_TOKEN', 'sign_secret' => 'PRIVATE_TEST_SECRET'];
        $robots->save(0, $shared);
        $row = $service->save($actor, 'admin', 'order_paid', $input);
        self::assertTrue($row['robot_status']->dingtalk);
        self::assertStringNotContainsString('PRIVATE_TEST', json_encode($row, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('webhook_url', $repository->get('admin', 'order_paid')['configurations']['dingtalk']);
        $second = $this->input('admin', 'ticket_created');
        $second['configurations']['dingtalk']['enabled'] = true;
        $row = $service->save($actor, 'admin', 'ticket_created', $second);
        self::assertTrue($row['robot_status']->dingtalk);
        $this->rejected(409, fn () => $service->save($actor, 'admin', 'order_paid', $input));
        $input['revision'] = 1;
        $input['configurations']['dingtalk']['webhook_url'] = $shared['dingtalk']['webhook_url'];
        $this->rejected(422, fn () => $service->save($actor, 'admin', 'order_paid', $input));
    }

    public function testChannelTemplateAndWebhookBoundariesRejectInvalidConfigurations(): void
    {
        [$service, $actor] = $this->fixture();
        $input = $this->input('customer', 'register_verification');
        $input['configurations']['message'] = ['enabled' => true, 'body' => '{{code}}'];
        $this->rejected(422, fn () => $service->save($actor, 'customer', 'register_verification', $input));
        $input = $this->input('customer', 'order_paid');
        $input['configurations']['message']['body'] = '{{code}}';
        $this->rejected(422, fn () => $service->save($actor, 'customer', 'order_paid', $input));
        $input['configurations']['message']['body'] = '{% include "secrets" %}';
        $this->rejected(422, fn () => $service->save($actor, 'customer', 'order_paid', $input));
        $input = $this->input('customer', 'order_paid');
        $input['configurations']['wechat']['variables'] = '["invalid"]';
        $this->rejected(422, fn () => $service->save($actor, 'customer', 'order_paid', $input));
        self::assertSame(0, Db::table('message_configurations')->count());
    }

    public function testEnabledMailAndSmsRequireAvailableChannelsAndBindRevision(): void
    {
        [$service, $actor, $repository] = $this->fixture();
        $input = $this->input('customer', 'order_paid');
        $input['configurations']['email']['enabled'] = true;
        $this->rejected(422, fn () => $service->save($actor, 'customer', 'order_paid', $input));
        $input['configurations']['email']['channel_id'] = 7;
        $input['configurations']['sms']['enabled'] = true;
        $input['configurations']['sms']['channel_id'] = 8;
        $row = $service->save($actor, 'customer', 'order_paid', $input);
        self::assertSame(7, $row['configurations']->email->channel_id);
        self::assertSame(4, $repository->get('customer', 'order_paid')['configurations']['email']['channel_revision']);
        $input['revision'] = 1;
        $input['configurations']['email']['channel_id'] = 9;
        $this->rejected(422, fn () => $service->save($actor, 'customer', 'order_paid', $input));
    }

    public function testBatchPreservesTemplatesAndUnselectedChannels(): void
    {
        [$service, $actor, $repository] = $this->fixture();
        $input = $this->input('customer', 'order_paid');
        $input['configurations']['email']['title'] = '专属订单邮件';
        $service->save($actor, 'customer', 'order_paid', $input);
        $before = $repository->get('customer', 'order_paid')['configurations'];
        $result = $service->batch($actor, 'customer', [
            'items' => [['code' => 'order_paid', 'revision' => 1], ['code' => 'service_due', 'revision' => 0]],
            'channel' => 'email', 'channel_id' => 7, 'enabled' => true,
        ]);
        self::assertSame(2, $result['updated']);
        $after = $repository->get('customer', 'order_paid')['configurations'];
        self::assertSame('专属订单邮件', $after['email']['title']);
        self::assertSame($before['email']['body'], $after['email']['body']);
        self::assertSame($before['sms'], $after['sms']);
        self::assertSame($before['wechat'], $after['wechat']);
        self::assertSame(7, $after['email']['channel_id']);
        self::assertSame(4, $after['email']['channel_revision']);
        self::assertTrue($after['email']['enabled']);
    }

    public function testBatchRollsBackEarlierWritesOnConflictOrInvalidTemplate(): void
    {
        [$service, $actor, $repository] = $this->fixture();
        $service->save($actor, 'customer', 'service_due', $this->input('customer', 'service_due'));
        $items = [['code' => 'order_paid', 'revision' => 0], ['code' => 'service_due', 'revision' => 0]];
        $this->rejected(409, fn () => $service->batch($actor, 'customer', [
            'items' => $items, 'channel' => 'email', 'channel_id' => 7, 'enabled' => true,
        ]));
        self::assertSame(0, $repository->get('customer', 'order_paid')['revision']);
        self::assertSame(1, Db::table('message_configurations')->count());
        $bad = $this->input('customer', 'service_due')['configurations'];
        $bad['email']['title'] = '';
        $repository->save('customer', 'service_due', 1, $bad);
        $items[1]['revision'] = 2;
        $this->rejected(422, fn () => $service->batch($actor, 'customer', [
            'items' => $items, 'channel' => 'email', 'channel_id' => 7, 'enabled' => true,
        ]));
        self::assertSame(0, $repository->get('customer', 'order_paid')['revision']);
        self::assertSame(2, $repository->get('customer', 'service_due')['revision']);
    }

    public function testBatchRejectsUnsupportedChannelsForbiddenFieldsAndMissingPermissions(): void
    {
        [$service, $actor, , $viewer] = $this->fixture(['admin.message-configurations.view']);
        $input = ['items' => [['code' => 'order_created', 'revision' => 0]], 'channel' => 'sms', 'enabled' => false];
        $this->rejected(422, fn () => $service->batch($actor, 'customer', $input));
        $input['channel'] = 'email';
        $this->rejected(403, fn () => $service->batch($viewer, 'customer', $input));
        $input['body'] = '批量不得覆盖模板';
        $this->rejected(422, fn () => $service->batch($actor, 'customer', $input));
        unset($input['body']);
        $input['items'][] = $input['items'][0];
        $this->rejected(422, fn () => $service->batch($actor, 'customer', $input));
        $input['items'] = [$input['items'][0]];
        $input['channel_id'] = 9;
        $this->rejected(422, fn () => $service->batch($actor, 'customer', $input));
        self::assertSame(0, Db::table('message_configurations')->count());
    }

    public function testControllerUsesUnifiedResponseAndAuthenticationAndSameOriginWrites(): void
    {
        [$service, $actor] = $this->fixture();
        $context = new \app\modules\admin\auth\application\AdminAuthenticationContext();
        $controller = new \app\modules\message_configuration\controller\AdminMessageConfigurationController($service, $context, new \app\shared\http\ApiResponse());
        $request = new \support\Request("GET /api/admin/v1/message-configurations/customer HTTP/1.1\r\nHost: example.test\r\n\r\n");
        $context->set($request, $actor);
        $response = $controller->index($request, 'customer');
        $body = json_decode($response->rawBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['code', 'message', 'data', 'request_id'], array_keys($body));
        self::assertSame(0, $body['code']);
        self::assertSame(19, $body['data']['total']);
        self::assertSame($body['request_id'], $response->getHeader('X-Request-Id'));
        $reflection = new \ReflectionClass($controller);
        self::assertSame([\app\modules\admin\auth\middleware\AdminAuthMiddleware::class],
            $reflection->getAttributes(\support\annotation\Middleware::class)[0]->getArguments());
        self::assertSame([\app\shared\http\middleware\SameOriginWriteMiddleware::class],
            $reflection->getMethod('update')->getAttributes(\support\annotation\Middleware::class)[0]->getArguments());
        self::assertSame([\app\shared\http\middleware\SameOriginWriteMiddleware::class],
            $reflection->getMethod('batch')->getAttributes(\support\annotation\Middleware::class)[0]->getArguments());
    }

    public function testViewAndUpdatePermissionsAreIndependent(): void
    {
        [$service, , , $viewer] = $this->fixture(['admin.message-configurations.view']);
        self::assertSame(19, $service->list($viewer, 'customer', [])['total']);
        $this->rejected(403, fn () => $service->save($viewer, 'customer', 'order_paid', $this->input('customer', 'order_paid')));
        [$service, , , $updater] = $this->fixture(['admin.message-configurations.update']);
        $this->rejected(403, fn () => $service->get($updater, 'customer', 'order_paid'));
    }

    /** @return array<int, mixed> 数据库真实配置仓储与发送通道公开资料桩 */
    private function fixture(array $permissions = []): array
    {
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $access->method('grantsForAdmin')->willReturn([['permissions' => $permissions]]);
        $channels = $this->createStub(ChannelRepositoryInterface::class);
        $channels->method('list')->willReturn(['data' => [], 'total' => 0]);
        $channels->method('find')->willReturnCallback(static fn (string $category, int $id): array => [
            'id' => $id, 'revision' => 4, 'status' => $id === 9 ? 0 : 1,
            'platform_available' => true, 'configuration_current' => true, 'plugin_id' => $category === 'mail' ? 'smtp' : 'smsbao',
        ]);
        $repository = new DatabaseMessageConfigurationRepository(new SodiumSensitiveDataCipher(str_repeat('f', 64)), new DatabaseTransactionManager());
        $robots = new \app\modules\system_settings\infrastructure\DatabaseNotificationRobotSettingsRepository(new SodiumSensitiveDataCipher(str_repeat('f', 64)), new DatabaseTransactionManager());
        return [new ManageMessageConfigurationsService(new AdminAccessControlService($access), $repository, $channels, new MessageConfigurationValidator(), $robots, new DatabaseTransactionManager(), new \app\modules\message_configuration\validation\BatchMessageConfigurationValidator(), new \app\modules\message\infrastructure\DatabaseMessageRecipientDirectory()),
            new AdminAccount(1, 'founder', '测试管理员', 'hidden', 1, null, true), $repository,
            new AdminAccount(1, 'viewer', '测试查看人', 'hidden', 1, null), $robots];
    }

    private function input(string $audience, string $code): array
    {
        $type = MessageTypeCatalog::get($audience, $code);
        return ['revision' => 0, 'configurations' => array_combine($type['channels'], array_map(
            static fn (string $channel): array => MessageTypeCatalog::defaults($type, $channel), $type['channels']))];
    }

    private function rejected(int $status, callable $action): void
    {
        try { $action(); self::fail('请求应被拒绝'); }
        catch (BusinessException $error) { self::assertSame($status, $error->httpStatus()); }
    }
}
