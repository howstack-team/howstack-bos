<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\compute\application\ManageCloudWorkspaceConfigurationService;
use app\modules\product\infrastructure\DatabaseProductRepository;
use app\modules\compute\validation\CloudOfferValidator;
use app\modules\provider\infrastructure\DatabaseProviderBindingWriter;
use app\shared\infrastructure\DatabaseTransactionManager;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 真实数据库验证首次保存互斥、多配置选择、版本冲突及只读权限。 */
final class CloudWorkspaceConfigurationTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testReadDoesNotInitializeAndFirstSaveCannotCreateDuplicate(): void
    {
        $repository = $this->repository();
        self::assertSame([], $repository->workspaceConfiguration()['choices']);
        self::assertSame(0, Db::table('offers')->count());
        $saved = $repository->saveWorkspaceConfiguration(null, $this->input('cloud'), null);
        self::assertSame($saved['id'], $repository->workspaceConfiguration()['default_configuration_id']);
        try {
            $repository->saveWorkspaceConfiguration(null, $this->input('duplicate'), null);
            self::fail('旧页面不能再次初始化配置');
        } catch (BusinessException $error) {
            self::assertSame(409, $error->httpStatus());
        }
        self::assertSame(1, Db::table('offers')->count());
    }

    public function testSingleExistingConfigurationIsReadWithoutChangingPointer(): void
    {
        $repository = $this->repository();
        $saved = $repository->save(null, $this->input('existing'));
        $state = $repository->workspaceConfiguration();
        self::assertSame($saved['id'], $state['configuration']['id']);
        self::assertNull($state['default_configuration_id']);
    }

    public function testMultipleExistingConfigurationsNeedExplicitChoiceAndRemainIndependent(): void
    {
        $repository = $this->repository();
        $first = $repository->save(null, $this->input('one'));
        $second = $repository->save(null, $this->input('two'));
        self::assertNull($repository->workspaceConfiguration()['configuration']);
        $selected = $repository->saveWorkspaceConfiguration($first['id'], $first, null);
        self::assertSame($first['id'], $repository->workspaceConfiguration()['configuration']['id']);
        self::assertSame($second, $repository->find($second['id']));
        try {
            $repository->saveWorkspaceConfiguration($second['id'], $second, null);
            self::fail('过期默认指针不能覆盖已选择的配置');
        } catch (BusinessException $error) {
            self::assertSame(409, $error->httpStatus());
        }
        self::assertSame($selected, $repository->find($first['id']));
    }

    public function testStaleRevisionRollsBackPointerSelection(): void
    {
        $repository = $this->repository();
        $first = $repository->saveWorkspaceConfiguration(null, $this->input('one'), null);
        $second = $repository->save(null, $this->input('two'));
        $repository->save($second['id'], $second);
        try {
            $repository->saveWorkspaceConfiguration($second['id'], $second, $first['id']);
            self::fail('过期草稿不能切换默认指针');
        } catch (BusinessException $error) {
            self::assertSame(409, $error->httpStatus());
        }
        self::assertSame($first['id'], $repository->workspaceConfiguration()['configuration']['id']);
    }

    public function testReadAndWritePermissionsAreIndependent(): void
    {
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $access->method('grantsForAdmin')->willReturn([['permissions' => ['admin.cloud-products.view']]]);
        $service = new ManageCloudWorkspaceConfigurationService(new AdminAccessControlService($access), $this->repository());
        $viewer = new AdminAccount(2, 'viewer', '查看用户', 'hidden', 1, null, false);
        self::assertSame([], $service->get($viewer)['choices']);
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('无权执行此操作');
        $service->save($viewer, null, $this->input('blocked'), null);
    }

    private function repository(): DatabaseProductRepository
    {
        return new \app\modules\product\infrastructure\DatabaseProductRepository(new DatabaseTransactionManager(), new DatabaseProviderBindingWriter(), 'cloud_server');
    }

    private function input(string $code): array
    {
        return (new CloudOfferValidator())->validate(['name' => '云服务器', 'code' => $code, 'draft' => ['schema_version' => '2.0.0']]);
    }
}
