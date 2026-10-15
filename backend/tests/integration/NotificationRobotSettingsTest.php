<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\system_settings\application\ManageNotificationRobotSettingsService;
use app\modules\system_settings\domain\NotificationRobotDefinition;
use app\modules\system_settings\infrastructure\DatabaseNotificationRobotSettingsRepository;
use app\modules\system_settings\validation\NotificationRobotSettingsValidator;
use app\shared\infrastructure\DatabaseTransactionManager;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

final class NotificationRobotSettingsTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testGlobalSecretsAreEncryptedNeverReturnedAndExplicitlyCleared(): void
    {
        [$service, $actor, $repo] = $this->fixture();
        $input = ['revision' => 0, 'robots' => NotificationRobotDefinition::defaults()];
        $input['robots']['dingtalk'] = ['webhook_url' => 'https://oapi.dingtalk.com/robot/send?access_token=PRIVATE_TOKEN', 'sign_secret' => 'PRIVATE_SECRET'];
        $row = $service->save($actor, $input);
        self::assertTrue($row['configured_secrets']->dingtalk['webhook_url']);
        self::assertStringNotContainsString('PRIVATE_', json_encode($row, JSON_THROW_ON_ERROR));
        $stored = Db::table('system_settings')->where('setting_key', NotificationRobotDefinition::SETTING_KEY)->first();
        self::assertSame(0, (int) $stored->is_public);
        self::assertStringNotContainsString('PRIVATE_', $stored->setting_value);
        $input = ['revision' => 1, 'robots' => NotificationRobotDefinition::defaults()];
        $service->save($actor, $input);
        self::assertSame('PRIVATE_SECRET', $repo->get()['robots']['dingtalk']['sign_secret']);
        $this->reject(409, fn () => $service->save($actor, $input));
        $input['revision'] = 2;
        $input['clear_secrets'] = ['dingtalk' => ['webhook_url']];
        $row = $service->save($actor, $input);
        self::assertFalse($row['configured_secrets']->dingtalk['webhook_url']);
        self::assertTrue($row['configured_secrets']->dingtalk['sign_secret']);
    }

    public function testPlatformValidationAndSettingsPermissionsAreEnforced(): void
    {
        [$service, $actor] = $this->fixture();
        $input = ['revision' => 0, 'robots' => NotificationRobotDefinition::defaults()];
        foreach (['http://127.0.0.1/private', 'https://oapi.dingtalk.com.evil.test/robot/send?access_token=x', 'https://user@oapi.dingtalk.com/robot/send?access_token=x'] as $url) {
            $input['robots']['dingtalk']['webhook_url'] = $url;
            $this->reject(422, fn () => $service->save($actor, $input));
        }
        $input['robots'] = NotificationRobotDefinition::defaults();
        $input['clear_secrets'] = ['dingtalk' => [['webhook_url']]];
        $this->reject(422, fn () => $service->save($actor, $input));
        [$viewerService, , , $viewer] = $this->fixture(['admin.page.settings.view']);
        self::assertSame(0, $viewerService->get($viewer)['revision']);
        $this->reject(403, fn () => $viewerService->save($viewer, $input));
        [$deniedService, , , $denied] = $this->fixture(['admin.message-configurations.update']);
        $this->reject(403, fn () => $deniedService->get($denied));
        self::assertSame(0, Db::table('system_settings')->where('setting_key', NotificationRobotDefinition::SETTING_KEY)->count());
    }

    public function testBothRobotPlatformsHaveTheirOwnUrlAndSecretFields(): void
    {
        [$service, $actor, $repo] = $this->fixture();
        $input = ['revision' => 0, 'robots' => NotificationRobotDefinition::defaults()];
        $input['robots']['wecom']['webhook_url'] = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=TEST_KEY';
        $input['robots']['feishu'] = ['webhook_url' => 'https://open.feishu.cn/open-apis/bot/v2/hook/TEST_KEY', 'sign_secret' => 'TEST_SECRET'];
        $service->save($actor, $input);
        self::assertSame($input['robots'], $repo->get()['robots']);
        $input['robots']['wecom']['sign_secret'] = 'NOT_SUPPORTED';
        $this->reject(422, fn () => $service->save($actor, $input));
    }

    private function fixture(array $permissions = []): array
    {
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $access->method('grantsForAdmin')->willReturn([['permissions' => $permissions]]);
        $repo = new DatabaseNotificationRobotSettingsRepository(new SodiumSensitiveDataCipher(str_repeat('f', 64)), new DatabaseTransactionManager());
        return [new ManageNotificationRobotSettingsService(new AdminAccessControlService($access), $repo, new NotificationRobotSettingsValidator()),
            new AdminAccount(1, 'founder', '测试管理员', 'hidden', 1, null, true), $repo,
            new AdminAccount(1, 'viewer', '测试管理员', 'hidden', 1, null)];
    }

    private function reject(int $status, callable $action): void
    {
        try { $action(); self::fail('请求应拒绝'); }
        catch (BusinessException $error) { self::assertSame($status, $error->httpStatus()); }
    }
}
