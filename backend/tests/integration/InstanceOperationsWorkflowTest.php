<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\service_instance\application\{OperateInstanceService, ProcessServiceLifecycleService};
use app\modules\service_instance\contract\InstanceImageOptionsInterface;
use app\modules\service_instance\infrastructure\{DatabaseServiceLifecycleRepository, DatabaseInstanceOperationRepository, DatabaseMemberServiceRepository};
use app\modules\service_instance\infrastructure\model\{LifecycleTaskModel as Task, ServiceInstanceModel as Service, ProvisioningTaskModel as Provision};
use app\modules\provider\contract\{ProviderLifecycleGatewayInterface, ProviderInstanceOperationsGatewayInterface};
use app\modules\order\infrastructure\DatabaseOrderProvisioningRepository;
use app\shared\infrastructure\{DatabaseTransactionManager, SodiumSensitiveDataCipher};
use app\shared\plugin\dto\provider\LifecycleResult;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\{IsolatedBusinessDatabase, MessageRuntimeFactory};

/** 真实迁移、密文、事务与互斥；仅外部供应和类型目录使用替身。 */
final class InstanceOperationsWorkflowTest extends TestCase
{
    use IsolatedBusinessDatabase;

    private function fixture(): array
    {
        $member = $this->member();
        $order = $this->order($member);
        $unit = (int) Db::table('order_item_units')->value('id');
        $service = Service::query()->create(['instance_no' => 'bos-operations', 'product_type' => 'cloud_server', 'member_id' => $member,
            'display_name' => '运维测试实例', 'status' => 'active', 'origin_order_unit_id' => $unit,
            'activated_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + 864000)]);
        Provision::query()->create(['service_id' => $service->id, 'order_id' => $order, 'order_unit_id' => $unit, 'request_key' => 'bos-operations',
            'status' => 'succeeded', 'external_id' => '123', 'adapter_id' => 'fixture', 'package_hash' => str_repeat('a', 64),
            'context' => ['offer_id' => 1, 'version_id' => 2, 'provisioning' => ['unchanged' => true]], 'next_attempt_at' => date('Y-m-d H:i:s')]);
        $gateway = new class implements ProviderLifecycleGatewayInterface, ProviderInstanceOperationsGatewayInterface {
            public array $calls = [];
            public string $result = 'pending';
            public function assertSupported(array $source, string $action = 'renew'): void
            {
            }
            public function actions(array $source): array
            {
                return ['power_on', 'power_off', 'reboot', 'reinstall', 'reset_password'];
            }
            public function execute(array $task): LifecycleResult
            {
                TestCase::assertSame(0, Db::connection()->transactionLevel());
                TestCase::assertNull(Task::query()->find($task['id'])->password_encrypted);
                $this->calls[] = $task;
                return new LifecycleResult($this->result, providerTaskId: '456', powerStatus: 'on');
            }
        };
        $images = new class implements InstanceImageOptionsInterface {
            public function images(array $context): array
            {
                return [['id' => 7, 'name' => 'Ubuntu', 'external_id' => '88', 'default_port' => 22, 'default_username' => 'root']];
            }
        };
        $cipher = new SodiumSensitiveDataCipher(str_repeat('secret', 16));
        $repo = new DatabaseServiceLifecycleRepository();
        $tx = new DatabaseTransactionManager();
        $operations = new OperateInstanceService($repo, new DatabaseInstanceOperationRepository(), $images, $gateway, $cipher, $tx, $this->createStub(\app\modules\provider\contract\ProviderVncGatewayInterface::class));
        $worker = new ProcessServiceLifecycleService($repo, new DatabaseOrderProvisioningRepository(), $gateway, $tx, MessageRuntimeFactory::publisher());
        return [$member, $service->id, $operations, $worker, $repo, $gateway, $cipher, $tx];
    }

    private function input(string $action = 'reset_password', string $key = 'operations-fixture-request'): array
    {
        return ['action' => $action, 'request_key' => $key, 'acknowledged' => true]
            + (in_array($action, ['reset_password', 'reinstall'], true) ? ['password' => 'Test@123456'] : [])
            + ($action === 'reinstall' ? ['image_id' => 7] : []);
    }

    public function testValidationRejectsOversizedOrUnsafeSecretsAndFiltersUnknownFields(): void
    {
        foreach ([str_repeat('x', 1024), 'short', 'Password with space', "Pass\nword123", '中文Password123'] as $password) {
            try {
                \app\modules\service_instance\validation\InstanceOperationValidator::validate(1, array_replace($this->input(), ['password' => $password]), true);
                self::fail('必须校验运维密码');
            } catch (BusinessException $error) {
                self::assertSame(422, $error->httpStatus());
                self::assertArrayHasKey('password', $error->data()['errors']);
                self::assertStringNotContainsString($password, json_encode($error->data()));
            }
        }
        $data = \app\modules\service_instance\validation\InstanceOperationValidator::validate(1, $this->input('power_on') + ['password' => 'Ignored@123', 'external_id' => 'other', 'format_data_disk' => 1], true);
        self::assertArrayNotHasKey('password', $data);
        self::assertArrayNotHasKey('external_id', $data);
        self::assertArrayNotHasKey('format_data_disk', $data);
    }

    public function testIdempotencyEncryptsPasswordAndPublicProjectionExcludesSecrets(): void
    {
        [$member, $id, $operations, , , , $cipher] = $this->fixture();
        $result = $operations->submit($member, $id, $this->input(), 'member:' . $member);
        self::assertSame($result, $operations->submit($member, $id, $this->input(), 'member:' . $member));
        self::assertSame(1, Task::query()->count());
        $task = Task::query()->first();
        self::assertSame('Test@123456', $cipher->decrypt($task->password_encrypted));
        self::assertTrue(password_verify('Test@123456', $task->password_digest));
        self::assertFalse(str_contains(json_encode($task), 'password_encrypted'));
        self::assertFalse(str_contains(json_encode($operations->options($member, $id)), 'Test@123456'));
        self::assertFalse((new DatabaseMemberServiceRepository())->find($member, $id)['initial_password_available']);
        $this->expectException(BusinessException::class);
        $operations->submit($member, $id, array_replace($this->input(), ['password' => 'Different@123']), 'member:' . $member);
    }

    public function testOwnershipExpiryAndConcurrentActionsAreRejectedBeforeExternalCalls(): void
    {
        [$member, $id, $operations, , , $gateway] = $this->fixture();
        try {
            $operations->submit($member + 100, $id, $this->input(), 'member:other');
            self::fail('禁止越权');
        } catch (BusinessException) {
            self::assertSame(0, Task::query()->count());
        }
        $operations->submit($member, $id, $this->input('power_off'), 'member:' . $member);
        try {
            $operations->submit($member, $id, $this->input('reboot', 'operations-another-key'), 'member:' . $member);
            self::fail('禁止并行');
        } catch (BusinessException) {
            self::assertSame(1, Task::query()->count());
        }
        self::assertSame([], $gateway->calls);
        Task::query()->update(['status' => 'succeeded']);
        Service::query()->whereKey($id)->update(['expires_at' => '2026-01-01 00:00:00']);
        $this->expectException(BusinessException::class);
        $operations->submit($member, $id, $this->input('power_on', 'operations-expired-key'), 'member:' . $member);
    }

    public function testPendingIsQueriedWithOriginalReferenceAndNeverReplayed(): void
    {
        [$member, $id, $operations, $worker, , $gateway] = $this->fixture();
        $result = $operations->submit($member, $id, $this->input(), 'member:' . $member);
        $worker->tick();
        self::assertSame('waiting', Task::query()->find($result['id'])->status);
        self::assertSame('456', Task::query()->find($result['id'])->provider_task_id);
        $gateway->result = 'succeeded';
        Task::query()->whereKey($result['id'])->update(['next_attempt_at' => date('Y-m-d H:i:s', time() - 1)]);
        $worker->tick();
        self::assertSame([false, true], array_column($gateway->calls, 'query_only'));
        self::assertSame('456', $gateway->calls[1]['provider_task_id']);
        self::assertNull($gateway->calls[1]['password_encrypted']);
        self::assertSame('succeeded', Task::query()->find($result['id'])->status);
        self::assertSame('on', Service::query()->find($id)->power_status);
        self::assertSame(0, $worker->tick());
    }

    public function testReinstallUpdatesCurrentImageWithoutChangingOriginalSnapshot(): void
    {
        [$member, $id, $operations, $worker, , $gateway] = $this->fixture();
        $origin = Provision::query()->first()->context;
        $gateway->result = 'succeeded';
        $operations->submit($member, $id, $this->input('reinstall'), 'member:' . $member);
        $worker->tick();
        $image = json_decode(Service::query()->find($id)->getRawOriginal('installed_image'), true);
        self::assertSame(7, $image['id']);
        self::assertArrayNotHasKey('external_id', $image);
        self::assertSame($origin, Provision::query()->first()->context);
        self::assertSame(1, Service::query()->count());
    }

    public function testVerifiedFailureIsTerminalAndAllowsFreshRequest(): void
    {
        [$member, $id, $operations, $worker, , $gateway] = $this->fixture();
        $gateway->result = 'failed';
        $result = $operations->submit($member, $id, $this->input('reboot'), 'member:' . $member);
        $worker->tick();
        self::assertSame('failed', Task::query()->find($result['id'])->status);
        self::assertSame(0, $worker->tick());
        $operations->submit($member, $id, $this->input('reboot', 'operations-fresh-request'), 'member:' . $member);
        self::assertSame(2, Task::query()->count());
    }

    public function testLeaseRecoveryCannotResubmitOrWriteStaleResult(): void
    {
        [$member, $id, $operations, , $repo, , , $tx] = $this->fixture();
        $result = $operations->submit($member, $id, $this->input('reinstall'), 'member:' . $member);
        $first = $tx->run(fn () => $repo->claim($result['id']));
        self::assertTrue($tx->run(fn () => $repo->submitted($first)));
        Task::query()->whereKey($result['id'])->update(['lease_until' => date('Y-m-d H:i:s', time() - 1)]);
        $second = $tx->run(fn () => $repo->claim($result['id']));
        self::assertTrue($second['query_only']);
        self::assertNull($second['password_encrypted']);
        self::assertFalse($tx->run(fn () => $repo->finish($first, new LifecycleResult('succeeded'), null)));
        self::assertSame('processing', Task::query()->find($result['id'])->status);
    }

    public function testExpiredQueuedSecretIsPurgedAndNotSent(): void
    {
        [$member, $id, $operations, $worker, , $gateway] = $this->fixture();
        $result = $operations->submit($member, $id, $this->input(), 'member:' . $member);
        Task::query()->whereKey($result['id'])->update(['credential_expires_at' => '2026-01-01 00:00:00']);
        $worker->tick();
        self::assertNull(Task::query()->find($result['id'])->password_encrypted);
        self::assertSame('failed', Task::query()->find($result['id'])->status);
        self::assertSame([], $gateway->calls);
    }

    public function testExpiryCannotRaceReinstallAndLateSuccessCannotReactivateService(): void
    {
        [$member, $id, $operations, $worker, , $gateway] = $this->fixture();
        $result = $operations->submit($member, $id, $this->input('reinstall'), 'member:' . $member);
        $worker->tick();
        Service::query()->whereKey($id)->update(['expires_at' => '2026-01-01 00:00:00']);
        $worker->tick();
        self::assertSame(['reinstall'], array_column($gateway->calls, 'action'));
        self::assertSame('expired', Service::query()->find($id)->status);
        $gateway->result = 'succeeded';
        Task::query()->whereKey($result['id'])->update(['next_attempt_at' => date('Y-m-d H:i:s', time() - 1)]);
        $worker->tick();
        self::assertSame('expired', Service::query()->find($id)->status);
        Task::query()->where('action', 'suspend')->update(['next_attempt_at' => date('Y-m-d H:i:s', time() - 1)]);
        $worker->tick();
        self::assertContains('suspend', array_column($gateway->calls, 'action'));
    }
}
