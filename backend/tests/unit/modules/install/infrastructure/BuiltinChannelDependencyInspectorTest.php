<?php

declare(strict_types=1);

namespace tests\unit\modules\install\infrastructure;

use app\modules\install\contract\EnvironmentInspectorInterface;
use app\modules\install\infrastructure\BuiltinChannelDependencyInspector;
use app\modules\install\infrastructure\EnvironmentInspector;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 通过隔离插件包验证安装前依赖检查，不执行包内 PHP 或连接外部服务。
 */
final class BuiltinChannelDependencyInspectorTest extends TestCase
{
    private string $root;

    /**
     * 创建本次用例独占的插件目录。
     * @return void
     */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/howstack-install-dependencies-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    /**
     * 清理用例创建的文件和目录。
     * @return void
     */
    protected function tearDown(): void
    {
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
     * 缺席的历史渠道和任意第三方插件均不产生安装必需项。
     * @return void
     */
    public function testAbsentBuiltinDirectoriesAndThirdPartyPackagesAreIgnored(): void
    {
        $folder = $this->folder('payment', 'third_party_gateway');
        file_put_contents($folder . '/composer.json', '{invalid-json');
        $provider = $this->folder('provider', 'magic_cloud');
        file_put_contents($provider . '/composer.json', '{invalid-json');
        $wrongCategory = $this->folder('feature', 'wechat_native');
        file_put_contents($wrongCategory . '/composer.json', '{invalid-json');

        $inspector = new BuiltinChannelDependencyInspector($this->root);

        self::assertInstanceOf(EnvironmentInspectorInterface::class, $inspector);
        self::assertSame(['passed' => true, 'items' => []], $inspector->inspect());
    }

    /**
     * 未声明 Composer 的纯 PHP 内置渠道可以继续安装。
     * @return void
     */
    public function testPurePhpBuiltinChannelDoesNotRequireVendor(): void
    {
        $this->folder('storage', 'local_storage');

        $report = (new BuiltinChannelDependencyInspector($this->root))->inspect();

        self::assertTrue($report['passed']);
        foreach ($report['items'] as $item) {
            self::assertTrue($item['passed']);
        }
    }

    /**
     * 已声明微信支付依赖但缺少 vendor 时返回可操作且不含服务器路径的失败项。
     * @return void
     */
    public function testMissingWechatVendorProducesRequiredFailureWithSafeBuildHelp(): void
    {
        $this->declaredWechatPackage();

        $report = (new BuiltinChannelDependencyInspector($this->root))->inspect();
        $item = $this->wechatItem($report);

        self::assertFalse($report['passed']);
        self::assertFalse($item['passed']);
        self::assertTrue($item['required']);
        foreach (['key', 'name', 'value', 'help'] as $field) {
            self::assertIsString($item[$field]);
        }
        self::assertNotSame('', $item['name']);
        self::assertNotSame('', $item['value']);
        self::assertStringContainsString('plugins/payment/wechat_native', $item['help']);
        self::assertStringContainsString('composer install', $item['help']);
        self::assertStringContainsString('--no-dev', $item['help']);
        self::assertStringContainsString('vendor', $item['help']);
        self::assertStringNotContainsString($this->root, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * 补齐与锁文件一致的最小生产依赖后，同一检查器重新检查能够恢复。
     * @return void
     */
    public function testSupplyingProductionDependenciesAllowsRetryWithoutRecreatingInspector(): void
    {
        $folder = $this->declaredWechatPackage();
        $inspector = new BuiltinChannelDependencyInspector($this->root);
        self::assertFalse($inspector->inspect()['passed']);

        $this->productionVendor($folder);
        $report = $inspector->inspect();

        self::assertTrue($report['passed']);
        self::assertTrue($this->wechatItem($report)['passed']);
    }

    /**
     * 锁文件、生产清单或 Composer 声明被篡改时拒绝继续。
     * @param string $scenario 损坏依赖的方式
     * @return void
     */
    #[DataProvider('invalidDependencyScenarios')]
    public function testTamperedAndDevelopmentDependenciesAreRejected(string $scenario): void
    {
        $folder = $this->declaredWechatPackage();
        $this->productionVendor($folder);
        $installed = $this->readJson($folder . '/vendor/composer/installed.json');
        $composer = $this->readJson($folder . '/composer.json');

        switch ($scenario) {
            case 'different-version':
                $installed['packages'][0]['version'] = '9.9.9';
                break;
            case 'different-reference':
                $installed['packages'][0]['dist']['reference'] = 'unexpected-source-reference';
                break;
            case 'unexpected-package':
                $installed['packages'][] = ['name' => 'fixture/unlocked', 'version' => '1.0.0'];
                break;
            case 'development-mode':
                $installed['dev'] = true;
                break;
            case 'development-package':
                $lock = $this->readJson($folder . '/composer.lock');
                $lock['packages-dev'] = [['name' => 'fixture/development', 'version' => '1.0.0']];
                $installed['packages'][] = $lock['packages-dev'][0];
                $this->writeJson($folder . '/composer.lock', $lock);
                break;
            case 'composer-hook':
                $composer['scripts'] = ['post-install-cmd' => 'echo do-not-disclose-fixture-secret'];
                break;
            case 'missing-lock':
                unlink($folder . '/composer.lock');
                break;
        }
        $this->writeJson($folder . '/vendor/composer/installed.json', $installed);
        $this->writeJson($folder . '/composer.json', $composer);

        $report = (new BuiltinChannelDependencyInspector($this->root))->inspect();

        self::assertFalse($report['passed']);
        self::assertFalse($this->wechatItem($report)['passed']);
        $serialized = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($this->root, $serialized);
        self::assertStringNotContainsString('do-not-disclose-fixture-secret', $serialized);
    }

    /**
     * 提供应被拒绝的依赖元数据状态。
     * @return array<string, array{string}> 场景及其测试参数
     */
    public static function invalidDependencyScenarios(): array
    {
        return [
            'installed version differs from lock' => ['different-version'],
            'installed source differs from lock' => ['different-reference'],
            'unlocked package exists' => ['unexpected-package'],
            'vendor includes development mode' => ['development-mode'],
            'vendor includes a development package' => ['development-package'],
            'root Composer hook exists' => ['composer-hook'],
            'lock file is absent' => ['missing-lock'],
        ];
    }

    /**
     * 无 composer.json 却遗留锁文件或 vendor 的内置包应失败。
     * @param string $artifact 不完整依赖中残留的文件或目录
     * @return void
     */
    #[DataProvider('orphanDependencyArtifacts')]
    public function testOrphanDependencyArtifactsAreRejected(string $artifact): void
    {
        $folder = $this->folder('storage', 'local_storage');
        if ($artifact === 'vendor') {
            mkdir($folder . '/vendor', 0700);
        } else {
            $this->writeJson($folder . '/composer.lock', ['packages' => []]);
        }

        $report = (new BuiltinChannelDependencyInspector($this->root))->inspect();

        self::assertFalse($report['passed']);
        self::assertCount(1, $report['items']);
        self::assertSame('builtin_channel_local_storage_dependencies', $report['items'][0]['key']);
        self::assertFalse($report['items'][0]['passed']);
    }

    /**
     * 提供缺少声明时仍需拒绝的依赖残留。
     * @return array<string, array{string}> 残留类型及其参数
     */
    public static function orphanDependencyArtifacts(): array
    {
        return ['lock only' => ['composer.lock'], 'vendor only' => ['vendor']];
    }

    /**
     * 检查只读取静态 JSON，不执行自动加载器、映射或 installed.php。
     * @return void
     */
    public function testInspectionNeverExecutesPhpDependencyFiles(): void
    {
        $folder = $this->declaredWechatPackage();
        $this->productionVendor($folder);
        $marker = $this->root . '/autoload-executed';
        $maliciousPhp = '<?php file_put_contents(' . var_export($marker, true) . ', "executed"); return [];';
        foreach ([
            'autoload.php',
            'composer/autoload_psr4.php',
            'composer/autoload_namespaces.php',
            'composer/autoload_classmap.php',
            'composer/autoload_files.php',
            'composer/installed.php',
        ] as $file) {
            file_put_contents($folder . '/vendor/' . $file, $maliciousPhp);
        }
        $loaders = spl_autoload_functions();

        $report = (new BuiltinChannelDependencyInspector($this->root))->inspect();

        self::assertTrue($report['passed']);
        self::assertTrue($this->wechatItem($report)['passed']);
        self::assertFileDoesNotExist($marker);
        self::assertSame($loaders, spl_autoload_functions());
    }

    /**
     * 全部安装环境检查应合并渠道必需项和原有 PHP 检查。
     * @return void
     */
    public function testEnvironmentReportIncludesBuiltinChannelFailures(): void
    {
        $this->declaredWechatPackage();
        $inspector = new EnvironmentInspector(new BuiltinChannelDependencyInspector($this->root));

        $report = $inspector->inspect();

        self::assertFalse($report['passed']);
        self::assertFalse($this->wechatItem($report)['passed']);
        self::assertContains('php_version', array_column($report['items'], 'key'));
    }

    /**
     * 创建一个固定类别下的隔离包目录。
     * @param string $category 插件类别
     * @param string $id       插件标识
     * @return string 插件目录
     */
    private function folder(string $category, string $id): string
    {
        $folder = $this->root . '/' . $category . '/' . $id;
        mkdir($folder, 0700, true);

        return $folder;
    }

    /**
     * 创建只有生产依赖声明和锁文件的微信支付渠道。
     * @return string 微信支付插件目录
     */
    private function declaredWechatPackage(): string
    {
        $folder = $this->folder('payment', 'wechat_native');
        $this->writeJson($folder . '/composer.json', [
            'require' => ['php' => '>=8.3', 'wechatpay/wechatpay' => '^1.5'],
            'config' => ['allow-plugins' => false],
        ]);
        $this->writeJson($folder . '/composer.lock', ['packages' => [$this->dependency()]]);

        return $folder;
    }

    /**
     * 写入最小静态生产依赖清单，避免安装或加载真实 SDK。
     * @param string $folder 插件目录
     * @return void
     */
    private function productionVendor(string $folder): void
    {
        mkdir($folder . '/vendor/composer', 0700, true);
        $this->writeJson($folder . '/vendor/composer/installed.json', [
            'packages' => [$this->dependency()],
            'dev' => false,
        ]);
        file_put_contents($folder . '/vendor/autoload.php', '<?php return null;');
    }

    /**
     * 提供锁文件与安装清单共用的精确生产依赖身份。
     * @return array{name: string, version: string, type: string, dist: array{reference: string}} 依赖元数据
     */
    private function dependency(): array
    {
        return [
            'name' => 'wechatpay/wechatpay',
            'version' => '1.5.0',
            'type' => 'library',
            'dist' => ['reference' => 'fixture-production-reference'],
        ];
    }

    /**
     * 从环境报告中选取稳定标识对应的微信支付检查项。
     * @param array{passed: bool, items: list<array<string, bool|string>>} $report 环境报告
     * @return array<string, bool|string> 微信支付检查项
     */
    private function wechatItem(array $report): array
    {
        $items = array_column($report['items'], null, 'key');
        self::assertArrayHasKey('builtin_channel_wechat_native_dependencies', $items);

        return $items['builtin_channel_wechat_native_dependencies'];
    }

    /**
     * 将测试元数据写入私有临时目录。
     * @param string               $path 目标文件
     * @param array<string, mixed> $data 测试元数据
     * @return void
     */
    private function writeJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * 读取测试元数据以构造损坏状态。
     * @param string $path 测试文件
     * @return array<string, mixed> 测试元数据
     */
    private function readJson(string $path): array
    {
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
