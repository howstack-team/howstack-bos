<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PhinxCoreMigrationRunner, PdoSchemaInspector, SystemUpdatePdoFactory};
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 旧角色升级只迁移原查看范围，不附带新的操作授权。 */
final class CloudSectionMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private initializeDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261008000700;
        $this->initializeDatabase();
    }

    public function testExistingReadScopesMapToSectionsWithoutGrantingWritePermissions(): void
    {
        $resourceRole = $this->role('resource-reader', ['admin.compute-resources.view']);
        $purchaseRole = $this->role('purchase-reader', ['admin.cloud-products.view']);
        $manualRole = $this->role('manual-page', ['admin.cloud-configuration.storage.view']);
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        self::assertSame([20261008000800, 20261008000900, 20261008001000], $runner->migrate($configuration)->executed);
        $codes = static fn (int $id): array => Db::table('admin_role_permissions')->where('role_id', $id)->orderBy('permission_code')->pluck('permission_code')->all();
        self::assertSame(['admin.cloud-configuration.deployment.view', 'admin.cloud-configuration.network.view', 'admin.cloud-configuration.specification.view', 'admin.cloud-configuration.storage.view', 'admin.compute-resources.view'], $codes($resourceRole));
        self::assertSame(['admin.cloud-configuration.purchase.view', 'admin.cloud-products.view'], $codes($purchaseRole));
        self::assertSame(['admin.cloud-configuration.storage.view'], $codes($manualRole));
        self::assertSame([], $runner->migrate($configuration)->executed);
        self::assertCount(5, $codes($resourceRole));
    }

    /** @param list<string> $codes 原权限 @return int 测试角色标识 */
    private function role(string $code, array $codes): int
    {
        $id = Db::table('admin_roles')->insertGetId(['name' => $code, 'code' => $code, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        foreach ($codes as $permission) Db::table('admin_role_permissions')->insert(['role_id' => $id, 'permission_code' => $permission]);
        return $id;
    }
}
