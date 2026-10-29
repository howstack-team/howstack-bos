<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\install\infrastructure\BuiltinChannelDependencyInspector;
use FilesystemIterator;
use PDO;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * 使用冻结迁移原文件和真实 SQLite 适配器验证依赖缺失后的安全恢复。
 */
final class BuiltinChannelDependencyRecoveryTest extends TestCase
{
    private const SCHEMA_VERSION = 20261004000100;

    private const CHANNEL_VERSION = 20261007000100;

    /**
     * @var array<string, list<string>> 本次历史迁移登记的官方渠道固定清单
     */
    private const CHANNELS = [
        'payment' => ['alipay_order_code', 'wechat_native'],
        'sms' => ['aliyun_sms', 'tencent_sms', 'submail_sms', 'smsbao'],
        'mail' => ['smtp', 'aliyun_dm', 'tencent_ses'],
        'identity' => ['alipay_identity', 'tencent_faceid'],
        'storage' => ['aliyun_oss', 'tencent_cos', 'qiniu_storage', 'local_storage'],
        'oauth' => ['wechat_login', 'qq_login'],
        'captcha' => ['image_captcha', 'geetest_v4', 'yidun_captcha', 'tencent_captcha', 'aliyun_captcha'],
    ];

    private string $root;

    /**
     * 创建独立目录，不重命名或改写仓库中的插件和迁移。
     * @return void
     */
    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('依赖恢复回归需要 PDO SQLite 驱动');
        }
        $this->root = sys_get_temp_dir() . '/howstack-channel-recovery-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/database/migrations', 0700, true);
    }

    /**
     * 清理当前用例创建的 SQLite 数据库和渠道文件。
     * @return void
     */
    protected function tearDown(): void
    {
        if (!isset($this->root)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->root);
    }

    /**
     * 复现缺 vendor 的失败，补齐发行依赖后安全重跑且保留既有数据与迁移日志。
     *
     * 独立进程不继承已加载的迁移类，原文件无需更改类名或执行逻辑。
     * @return void
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFrozenMigrationRecoversAfterProductionDependenciesAreSupplied(): void
    {
        $this->copyFrozenMigrations();
        $this->createOfficialChannelFixtures();
        $pdo = new PDO('sqlite:' . $this->root . '/database/recovery.sqlite', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $manager = $this->manager($pdo);
        $manager->migrate('recovery', self::SCHEMA_VERSION);
        $this->seedExistingData($pdo);
        $existingInstallation = $this->installations($pdo);
        $existingEvents = $this->events($pdo);
        $baselineLog = $manager->getEnvironment('recovery')->getVersionLog();
        self::assertSame([self::SCHEMA_VERSION], array_map('intval', array_keys($baselineLog)));
        self::assertFalse((new BuiltinChannelDependencyInspector($this->root . '/plugins'))->inspect()['passed']);

        try {
            $manager->migrate('recovery');
            self::fail('真实历史迁移应拒绝缺少发行 vendor 的微信支付渠道');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('wechat_native', $exception->getMessage());
        } finally {
            // 与生产迁移执行器一致，结束 Phinx 异常后可能遗留的事务，允许重新连接重试。
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        self::assertSame($existingInstallation, $this->installations($pdo));
        self::assertSame($existingEvents, $this->events($pdo));
        self::assertSame($baselineLog, $manager->getEnvironment('recovery')->getVersionLog());
        self::assertSame('客户原有数据', $pdo->query('SELECT value FROM recovery_existing_business WHERE id = 1')->fetchColumn());
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM recovery_business_plugin_installations
             WHERE plugin_id IN ('alipay_order_code', 'wechat_native')",
        )->fetchColumn());

        $this->supplyWechatProductionVendor();
        self::assertTrue((new BuiltinChannelDependencyInspector($this->root . '/plugins'))->inspect()['passed']);
        $manager = $this->manager($pdo);
        $manager->migrate('recovery');

        $installations = $this->installations($pdo);
        $events = $this->events($pdo);
        $versionLog = $manager->getEnvironment('recovery')->getVersionLog();
        self::assertCount(22, $installations);
        self::assertCount(43, $events);
        self::assertSame($existingInstallation[0], $this->rowForPlugin($installations, 'smtp'));
        self::assertSame($existingEvents[0], $events[0]);
        foreach ($installations as $installation) {
            if ($installation['plugin_id'] === 'smtp') {
                continue;
            }
            self::assertSame('enabled', $installation['state']);
            self::assertSame(2, (int) $installation['revision']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $installation['package_hash']);
        }
        self::assertSame(21, (int) $pdo->query("SELECT COUNT(*) FROM recovery_business_plugin_events WHERE action = 'install'")->fetchColumn());
        self::assertSame(21, (int) $pdo->query("SELECT COUNT(*) FROM recovery_business_plugin_events WHERE action = 'enable'")->fetchColumn());
        self::assertSame([self::SCHEMA_VERSION, self::CHANNEL_VERSION], array_map('intval', array_keys($versionLog)));
        self::assertSame($baselineLog[self::SCHEMA_VERSION], $versionLog[self::SCHEMA_VERSION]);

        $manager->migrate('recovery');
        // 同时验证历史迁移本身的重入保护，而不只依赖 Phinx 跳过已记录版本。
        $manager->getMigrations('recovery')[self::CHANNEL_VERSION]->up();

        self::assertSame($installations, $this->installations($pdo));
        self::assertSame($events, $this->events($pdo));
        self::assertSame($versionLog, $manager->getEnvironment('recovery')->getVersionLog());
        self::assertSame('客户原有数据', $pdo->query('SELECT value FROM recovery_existing_business WHERE id = 1')->fetchColumn());
        self::assertFalse($pdo->inTransaction());
    }

    /**
     * 逐字复制原迁移到临时完整目录树，保留其 __DIR__ 对插件目录的解析。
     * @return void
     */
    private function copyFrozenMigrations(): void
    {
        foreach ([
            '20261004000100_create_business_plugin_installations.php',
            '20261007000100_register_builtin_channels.php',
        ] as $file) {
            $source = dirname(__DIR__, 2) . '/database/migrations/' . $file;
            $target = $this->root . '/database/migrations/' . $file;
            self::assertTrue(copy($source, $target));
            self::assertSame(hash_file('sha256', $source), hash_file('sha256', $target));
        }
    }

    /**
     * 创建最小官方包，除微信支付外均为不声明 Composer 的纯 PHP 包。
     * @return void
     */
    private function createOfficialChannelFixtures(): void
    {
        foreach (self::CHANNELS as $category => $ids) {
            foreach ($ids as $id) {
                $folder = $this->root . '/plugins/' . $category . '/' . $id;
                mkdir($folder, 0700, true);
                file_put_contents($folder . '/plugin.json', json_encode([
                    'manifest_version' => '1.0',
                    'id' => $id,
                    'category' => $category,
                    'publisher' => 'howstack-team',
                    'version' => '1.0.0',
                ], JSON_THROW_ON_ERROR));
                file_put_contents($folder . '/Adapter.php', '<?php return null;');
            }
        }
        $wechat = $this->root . '/plugins/payment/wechat_native';
        file_put_contents($wechat . '/composer.json', json_encode([
            'require' => ['wechatpay/wechatpay' => '^1.5'],
            'config' => ['allow-plugins' => false],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($wechat . '/composer.lock', json_encode([
            'packages' => [$this->wechatDependency()],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * 将真实 Phinx 管理器连接到仅位于临时目录中的 SQLite 文件。
     * @param PDO $pdo 隔离的 SQLite 连接
     * @return Manager 真实迁移管理器
     */
    private function manager(PDO $pdo): Manager
    {
        return new Manager(new Config([
            'paths' => ['migrations' => $this->root . '/database/migrations'],
            'environments' => [
                'default_migration_table' => 'recovery_migration_versions',
                'default_environment' => 'recovery',
                'recovery' => [
                    'name' => $this->root . '/database/recovery.sqlite',
                    'connection' => $pdo,
                    'migration_table' => 'recovery_migration_versions',
                    'table_prefix' => 'recovery_',
                ],
            ],
            'version_order' => Config::VERSION_ORDER_CREATION_TIME,
        ]), new ArrayInput([]), new BufferedOutput());
    }

    /**
     * 保留一条已停用的渠道与原审计，并放置独立业务数据作为保护断言。
     * @param PDO $pdo 隔离的 SQLite 连接
     * @return void
     */
    private function seedExistingData(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE recovery_existing_business (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $statement = $pdo->prepare('INSERT INTO recovery_existing_business (id, value) VALUES (1, ?)');
        $statement->execute(['客户原有数据']);
        $statement = $pdo->prepare('INSERT INTO recovery_business_plugin_installations
            (plugin_id, category, version, package_hash, state, revision, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            'smtp', 'mail', '0.9.0', str_repeat('a', 64), 'disabled', 9,
            '2026-01-01 10:00:00', '2026-02-02 11:00:00',
        ]);
        $statement = $pdo->prepare('INSERT INTO recovery_business_plugin_events
            (plugin_id, action, from_state, to_state, actor_id, package_hash, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            'smtp', 'disable', 'enabled', 'disabled', 42,
            str_repeat('a', 64), '2026-02-02 11:00:00',
        ]);
    }

    /**
     * 补交与锁文件完全一致的静态生产依赖，不安装或加载真实 SDK。
     * @return void
     */
    private function supplyWechatProductionVendor(): void
    {
        $vendor = $this->root . '/plugins/payment/wechat_native/vendor';
        mkdir($vendor . '/composer', 0700, true);
        file_put_contents($vendor . '/autoload.php', '<?php return null;');
        file_put_contents($vendor . '/composer/installed.json', json_encode([
            'packages' => [$this->wechatDependency()],
            'dev' => false,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * 提供锁文件与生产安装清单共有的 SDK 身份。
     * @return array{name: string, version: string, type: string} 静态依赖元数据
     */
    private function wechatDependency(): array
    {
        return ['name' => 'wechatpay/wechatpay', 'version' => '1.5.0', 'type' => 'library'];
    }

    /**
     * 读取完整渠道登记快照以比较未知字段和既有值是否发生变化。
     * @param PDO $pdo 隔离的 SQLite 连接
     * @return list<array<string, mixed>> 渠道登记快照
     */
    private function installations(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM recovery_business_plugin_installations ORDER BY id')->fetchAll();
    }

    /**
     * 读取完整审计快照以验证失败和重复执行均不追加事件。
     * @param PDO $pdo 隔离的 SQLite 连接
     * @return list<array<string, mixed>> 生命周期审计快照
     */
    private function events(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM recovery_business_plugin_events ORDER BY id')->fetchAll();
    }

    /**
     * 从快照中提取要验证保留的原渠道记录。
     * @param list<array<string, mixed>> $rows 渠道登记快照
     * @param string                    $id   渠道标识
     * @return array<string, mixed> 指定渠道记录
     */
    private function rowForPlugin(array $rows, string $id): array
    {
        $byPlugin = array_column($rows, null, 'plugin_id');
        self::assertArrayHasKey($id, $byPlugin);

        return $byPlugin[$id];
    }
}
