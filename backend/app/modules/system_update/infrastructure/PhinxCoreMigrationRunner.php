<?php

declare(strict_types=1);

namespace app\modules\system_update\infrastructure;

use app\modules\system_update\contract\CoreMigrationRunnerInterface;
use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\domain\MigrationResult;
use app\modules\system_update\domain\MigrationStatus;
use app\modules\system_update\exception\SystemUpdateException;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * 使用 Phinx 执行并追踪核心数据库迁移。
 */
final readonly class PhinxCoreMigrationRunner implements CoreMigrationRunnerInterface
{
    private const ENVIRONMENT = 'runtime';

    /**
     * 初始化核心数据库迁移执行器。
     * @param SystemUpdatePdoFactory $connections 系统更新数据库连接工厂
     * @param PdoSchemaInspector     $schema      PDO 数据库结构检查器
     */
    public function __construct(
        private SystemUpdatePdoFactory $connections,
        private PdoSchemaInspector $schema,
    ) {
    }

    /**
     * 查询核心数据库迁移状态。
     * @param MigrationDatabaseConfiguration $configuration 数据库迁移连接配置
     * @return MigrationStatus 数据库迁移状态
     * @throws SystemUpdateException 迁移目录、连接或版本日志不可读取时抛出
     */
    public function status(MigrationDatabaseConfiguration $configuration): MigrationStatus
    {
        try {
            [$manager] = $this->manager($configuration);

            return $this->statusFromManager(
                $manager,
                $this->schema->hasTable(
                    $this->connections->connect($configuration),
                    $configuration->tablePrefix . 'migration_versions',
                ),
            );
        } catch (SystemUpdateException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SystemUpdateException('数据库迁移状态读取失败，请检查数据库连接和迁移文件', 500, $exception);
        }
    }

    /**
     * 执行尚未应用的核心数据库迁移。
     * @param MigrationDatabaseConfiguration $configuration 数据库迁移连接配置
     * @return MigrationResult 数据库迁移执行结果
     * @throws SystemUpdateException 迁移文件缺失或执行失败时抛出
     */
    public function migrate(MigrationDatabaseConfiguration $configuration): MigrationResult
    {
        $connection = null;
        try {
            [$manager, $output] = $this->manager($configuration);
            $connection = $manager->getEnvironment(self::ENVIRONMENT)->getAdapter()->getConnection();
            $before = $this->statusFromManager(
                $manager,
                $this->schema->hasTable(
                    $this->connections->connect($configuration),
                    $configuration->tablePrefix . 'migration_versions',
                ),
            );
            if ($before->missing !== []) {
                throw new SystemUpdateException('数据库存在代码包中缺失的迁移记录，禁止继续更新', 409);
            }

            $manager->migrate(self::ENVIRONMENT);
            $after = $this->statusFromManager($manager, true);
            $executed = array_values(array_diff($after->applied, $before->applied));
            sort($executed);

            return new MigrationResult($executed, $after, trim($output->fetch()));
        } catch (SystemUpdateException $exception) {
            if ($connection instanceof \PDO && $connection->inTransaction()) $connection->rollBack();
            throw $exception;
        } catch (Throwable $exception) {
            // Phinx 迁移异常时不一定结束事务；释放失败连接的锁，允许受控重试。
            if ($connection instanceof \PDO && $connection->inTransaction()) $connection->rollBack();
            throw new SystemUpdateException('数据库迁移执行失败，请检查表结构、权限和迁移日志', 500, $exception);
        }
    }

    /**
     * 创建绑定当前数据库配置的 Phinx 管理器。
     * @param MigrationDatabaseConfiguration $configuration 数据库迁移连接配置
     * @return array{Manager, BufferedOutput} Phinx 管理器与输出缓冲区
     * @throws SystemUpdateException 核心迁移目录不存在时抛出
     */
    private function manager(MigrationDatabaseConfiguration $configuration): array
    {
        $migrationPath = base_path(false) . '/database/migrations';
        if (!is_dir($migrationPath)) {
            throw new SystemUpdateException('核心数据库迁移目录不存在', 500);
        }

        $phinxConfiguration = new Config([
            'paths' => ['migrations' => $migrationPath],
            'environments' => [
                'default_migration_table' => $configuration->tablePrefix . 'migration_versions',
                'default_environment' => self::ENVIRONMENT,
                self::ENVIRONMENT => [
                    'name' => $configuration->database,
                    'connection' => $this->connections->connect($configuration),
                    'migration_table' => $configuration->tablePrefix . 'migration_versions',
                    'table_prefix' => $configuration->tablePrefix,
                ],
            ],
            'version_order' => Config::VERSION_ORDER_CREATION_TIME,
        ]);
        $output = new BufferedOutput();

        return [new Manager($phinxConfiguration, new ArrayInput([]), $output), $output];
    }

    /**
     * 从 Phinx 管理器计算迁移状态。
     * @param Manager $manager              Phinx 迁移管理器
     * @param bool    $migrationTableExists 迁移版本表是否已经存在
     * @return MigrationStatus 数据库迁移状态
     */
    private function statusFromManager(
        Manager $manager,
        bool $migrationTableExists,
    ): MigrationStatus
    {
        $available = array_map('intval', array_keys($manager->getMigrations(self::ENVIRONMENT)));
        $applied = $migrationTableExists
            ? array_map(
                'intval',
                array_keys($manager->getEnvironment(self::ENVIRONMENT)->getVersionLog()),
            )
            : [];
        $pending = array_values(array_diff($available, $applied));
        $missing = array_values(array_diff($applied, $available));
        sort($available);
        sort($applied);
        sort($pending);
        sort($missing);

        return new MigrationStatus(
            $applied,
            $pending,
            $missing,
            $available === [] ? 0 : max($available),
        );
    }
}
