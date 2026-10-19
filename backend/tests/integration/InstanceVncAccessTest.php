<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\provider\contract\ProviderVncGatewayInterface;
use app\modules\service_instance\application\OpenInstanceVncService;
use app\modules\service_instance\contract\VncAuditLoggerInterface;
use app\modules\service_instance\infrastructure\DatabaseServiceLifecycleRepository;
use app\modules\service_instance\infrastructure\model\{ServiceInstanceModel as Service, ProvisioningTaskModel as Provision};
use app\shared\plugin\dto\provider\VncConnection;
use app\shared\plugin\exception\PluginException;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 真实迁移和服务查询；模拟外部VNC签发，验证网络调用前后资格均被检查。 */
final class InstanceVncAccessTest extends TestCase
{
    use IsolatedBusinessDatabase;

    private function fixture(): array
    {
        $member = $this->member();
        $order = $this->order($member);
        $service = Service::query()->create(['instance_no' => 'bos-vnc-fixture', 'product_type' => 'cloud_server', 'member_id' => $member,
            'display_name' => 'VNC验收实例', 'status' => 'active', 'activated_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + 86400)]);
        Provision::query()->create(['service_id' => $service->id, 'order_id' => $order, 'order_unit_id' => (int) Db::table('order_item_units')->value('id'),
            'request_key' => $service->instance_no, 'external_id' => '123', 'adapter_id' => 'fixture', 'package_hash' => str_repeat('a', 64),
            'connection_encrypted' => 'original-connection-cipher', 'status' => 'succeeded', 'context' => [], 'next_attempt_at' => date('Y-m-d H:i:s')]);
        $gateway = new class implements ProviderVncGatewayInterface {
            public int $opens = 0;
            public bool $supported = true, $fail = false;
            public ?\Closure $during = null;
            public function supported(array $source): bool
            {
                return $this->supported;
            }
            public function open(array $source): VncConnection
            {
                TestCase::assertSame(0, Db::connection()->transactionLevel());
                TestCase::assertSame('123', $source['external_id']);
                TestCase::assertSame('original-connection-cipher', $source['connection_encrypted']);
                $this->opens++;
                if ($this->during) {
                    ($this->during)();
                }
                if ($this->fail) {
                    throw new PluginException('secret-vnc-password=https://provider-secret', 'vnc_unavailable');
                }
                return new VncConnection('wss://vnc.example.test/cloud?token=fixture-token', 'vnc-secret');
            }
        };
        $audit = new class implements VncAuditLoggerInterface {
            public array $events = [];
            public function record(string $actor, int $id, string $outcome): void
            {
                $this->events[] = [$actor, $id, $outcome];
            }
        };
        $app = new OpenInstanceVncService(new DatabaseServiceLifecycleRepository(), $gateway, $audit);
        return [$member, $service->id, $app, $gateway, $audit];
    }

    public function testOwnInstanceIssuesOnlyVncCredentialsAndReadCheckDoesNotIssue(): void
    {
        [$member, $id, $app, $gateway, $audit] = $this->fixture();
        self::assertSame(['allowed' => true], $app->access($member, $id));
        self::assertSame(0, $gateway->opens);
        $result = $app->open($member, $id, 'member:' . $member);
        self::assertSame(['websocket_url', 'password', 'connect_timeout_seconds', 'session_timeout_seconds'], array_keys($result));
        self::assertSame('vnc-secret', $result['password']);
        self::assertSame(900, $result['session_timeout_seconds']);
        self::assertSame([['member:' . $member, $id, 'issued']], $audit->events);
        self::assertStringNotContainsString('vnc-secret', json_encode($audit->events));
        self::assertSame(1, Service::query()->count());
        self::assertSame([], Provision::query()->first()->context);
    }

    public function testForeignOwnerAndInactiveOrExpiredInstancesCannotReachProvider(): void
    {
        [$member, $id, $app, $gateway] = $this->fixture();
        try {
            $app->open($member + 100, $id, 'member:other');
            self::fail('禁止跨会员连接');
        } catch (BusinessException $error) {
            self::assertSame(404, $error->httpStatus());
        }
        foreach (['expired', 'suspended', 'terminated', 'provisioning'] as $status) {
            Service::query()->whereKey($id)->update(['status' => $status]);
            try {
                $app->open($member, $id, 'member:' . $member);
                self::fail('禁止未就绪实例连接');
            } catch (BusinessException $error) {
                self::assertSame(409, $error->httpStatus());
            }
        }
        Service::query()->whereKey($id)->update(['status' => 'active', 'expires_at' => '2026-01-01 00:00:00']);
        try {
            $app->access($member, $id);
            self::fail('到期进程未执行也必须拦截');
        } catch (BusinessException $error) {
            self::assertSame(409, $error->httpStatus());
        }
        self::assertSame(0, $gateway->opens);
    }

    public function testTransferDuringProviderRequestCannotLeakIssuedCredentials(): void
    {
        [$member, $id, $app, $gateway, $audit] = $this->fixture();
        $other = $this->member('13800138002');
        $gateway->during = static fn () => Service::query()->whereKey($id)->update(['member_id' => $other]);
        try {
            $app->open($member, $id, 'member:' . $member);
            self::fail('签发后必须重新核验归属');
        } catch (BusinessException $error) {
            self::assertSame(404, $error->httpStatus());
        }
        self::assertSame('failed', $audit->events[0][2]);
    }

    public function testExpiryAndBindingChangeDuringRequestAreRejected(): void
    {
        [$member, $id, $app, $gateway] = $this->fixture();
        $gateway->during = static fn () => Provision::query()->where('service_id', $id)->update(['external_id' => '124']);
        try {
            $app->open($member, $id, 'member:' . $member);
            self::fail('供应身份改变后不能回传原凭据');
        } catch (BusinessException $error) {
            self::assertSame(409, $error->httpStatus());
        }
        Provision::query()->where('service_id', $id)->update(['external_id' => '123']);
        $gateway->during = static fn () => Service::query()->whereKey($id)->update(['expires_at' => '2026-01-01 00:00:00']);
        $this->expectException(BusinessException::class);
        $app->open($member, $id, 'member:' . $member);
    }

    public function testProviderErrorIsSanitizedAndUnsupportedAccessDoesNotIssue(): void
    {
        [$member, $id, $app, $gateway, $audit] = $this->fixture();
        $gateway->supported = false;
        try {
            $app->access($member, $id);
            self::fail('不支持VNC时拒绝连接');
        } catch (BusinessException $error) {
            self::assertSame(409, $error->httpStatus());
        }
        self::assertSame(0, $gateway->opens);
        $gateway->fail = true;
        try {
            $app->open($member, $id, 'member:' . $member);
            self::fail('供应失败不能伪造成功');
        } catch (BusinessException $error) {
            self::assertSame(503, $error->httpStatus());
            self::assertStringNotContainsString('secret', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertSame('failed', $audit->events[0][2]);
    }

    public function testClientSessionWindowNeverExceedsRemainingSubscription(): void
    {
        [$member, $id, $app] = $this->fixture();
        Service::query()->whereKey($id)->update(['expires_at' => date('Y-m-d H:i:s', time() + 60)]);
        $result = $app->open($member, $id, 'member:' . $member);
        self::assertGreaterThan(0, $result['session_timeout_seconds']);
        self::assertLessThanOrEqual(60, $result['session_timeout_seconds']);
    }
}
