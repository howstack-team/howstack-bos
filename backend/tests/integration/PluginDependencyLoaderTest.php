<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\plugin\infrastructure\PluginDependencyLoader;
use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** 独立临时 Composer 包，验证本地依赖加载与冲突，不安装外部依赖。 */
final class PluginDependencyLoaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/howstack-dependency-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        $this->root = realpath($this->root);
    }

    protected function tearDown(): void
    {
        foreach (ClassLoader::getRegisteredLoaders() as $vendor => $loader) {
            if (str_starts_with($vendor, $this->root . '/')) {
                $loader->unregister();
            }
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testValidationDoesNotRunAutoloadAndLocalSdkLoadsOnce(): void
    {
        $prefix = 'Sdk' . bin2hex(random_bytes(6)) . '\\';
        $folder = $this->package('first', $prefix);
        PluginDependencyLoader::validate($folder, 'plugins\\mail\\first\\');
        self::assertFalse(defined('DEPENDENCY_LOADED_' . basename($this->root)));
        PluginDependencyLoader::load($folder, 'plugins\\mail\\first\\');
        PluginDependencyLoader::load($folder, 'plugins\\mail\\first\\');
        self::assertTrue(defined('DEPENDENCY_LOADED_' . basename($this->root)));
        $class = $prefix . 'Mailer';
        self::assertSame('1.0.0', $class::VERSION);
        self::assertStringStartsWith($folder . '/vendor/', (new \ReflectionClass($class))->getFileName());
    }

    public function testDifferentVersionsFailBeforeRegisteringSecondSdk(): void
    {
        $prefix = 'Sdk' . bin2hex(random_bytes(6)) . '\\';
        $first = $this->package('first', $prefix);
        $second = $this->package('second', $prefix, '2.0.0');
        PluginDependencyLoader::load($first, 'plugins\\mail\\first\\');
        try {
            PluginDependencyLoader::load($second, 'plugins\\mail\\second\\');
            self::fail('同名不同版本 SDK 应拒绝加载');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('版本冲突', $error->getMessage());
        }
        self::assertArrayNotHasKey($second . '/vendor', ClassLoader::getRegisteredLoaders());
    }

    public function testIdenticalSdkVersionsCanBeSharedWithoutChangingClassResolution(): void
    {
        $prefix = 'Sdk' . bin2hex(random_bytes(6)) . '\\';
        $first = $this->package('first', $prefix);
        $second = $this->package('second', $prefix);
        PluginDependencyLoader::load($first, 'plugins\\mail\\first\\');
        PluginDependencyLoader::load($second, 'plugins\\mail\\second\\');
        $class = $prefix . 'Mailer';
        self::assertStringStartsWith($first . '/vendor/', (new \ReflectionClass($class))->getFileName());
        self::assertArrayHasKey($second . '/vendor', ClassLoader::getRegisteredLoaders());
    }

    public function testDevelopmentDependenciesAreRejected(): void
    {
        $folder = $this->package('first', 'FixtureSdk\\');
        $installedPath = $folder . '/vendor/composer/installed.json';
        $installed = json_decode(file_get_contents($installedPath), true);
        $installed['dev'] = true;
        file_put_contents($installedPath, json_encode($installed));
        $this->reject($folder, '生产模式');
    }

    public function testIdenticalClassmapSdkVersionsCanBeShared(): void
    {
        $prefix = 'ClassmapSdk' . bin2hex(random_bytes(6)) . '\\';
        $class = $prefix . 'Mailer';
        $folders = [$this->package('first', $prefix), $this->package('second', $prefix)];
        foreach ($folders as $folder) {
            $metadata = $folder . '/vendor/composer/installed.json';
            $installed = json_decode(file_get_contents($metadata), true);
            $installed['packages'][0]['autoload'] = ['classmap' => ['src/']];
            $installed['packages'][0]['install-path'] = '../fixture/sdk';
            file_put_contents($metadata, json_encode($installed));
            file_put_contents($folder . '/vendor/composer/autoload_psr4.php', '<?php return [];');
            file_put_contents($folder . '/vendor/composer/autoload_classmap.php', '<?php return '
                . var_export([$class => $folder . '/vendor/fixture/sdk/src/Mailer.php'], true) . ';');
            file_put_contents($folder . '/vendor/autoload.php', '<?php '
                . '$loader = new \\Composer\\Autoload\\ClassLoader(__DIR__);'
                . '$loader->addClassMap(require __DIR__ . "/composer/autoload_classmap.php");'
                . '$loader->register(); return $loader;');
            PluginDependencyLoader::load($folder, 'plugins\\mail\\' . basename($folder) . '\\');
        }
        self::assertSame('1.0.0', $class::VERSION);
        self::assertStringStartsWith($folders[0] . '/vendor/', (new \ReflectionClass($class))->getFileName());
    }

    public function testDistinctSdkCanExtendVendorNamespaceWithoutOverridingExistingClasses(): void
    {
        $prefix = 'ExtendedSdk' . bin2hex(random_bytes(6)) . '\\';
        $first = $this->package('first', $prefix);
        $second = $this->package('second', $prefix . 'Commands\\');
        $this->renameDependency($second);
        PluginDependencyLoader::load($first, 'plugins\\mail\\first\\');
        PluginDependencyLoader::load($second, 'plugins\\mail\\second\\');
        $class = $prefix . 'Commands\\Mailer';
        self::assertSame('1.0.0', $class::VERSION);
        self::assertStringStartsWith($second . '/vendor/', (new \ReflectionClass($class))->getFileName());
    }

    public function testDifferentPackageCannotReplaceAnExistingSdkNamespace(): void
    {
        $prefix = 'CollisionSdk' . bin2hex(random_bytes(6)) . '\\';
        $first = $this->package('first', $prefix);
        $second = $this->package('second', $prefix);
        $this->renameDependency($second);
        PluginDependencyLoader::load($first, 'plugins\\mail\\first\\');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('命名空间冲突');
        PluginDependencyLoader::load($second, 'plugins\\mail\\second\\');
    }

    /** @param string $folder 临时包 @return void 将第二个 SDK 设为独立包身份 */
    private function renameDependency(string $folder): void
    {
        foreach (['composer.lock', 'vendor/composer/installed.json'] as $file) {
            $data = json_decode(file_get_contents($folder . '/' . $file), true);
            $data['packages'][0]['name'] .= '-extension';
            file_put_contents($folder . '/' . $file, json_encode($data));
        }
        $composer = json_decode(file_get_contents($folder . '/composer.json'), true);
        $name = array_key_first($composer['require']);
        $composer['require'] = [$name . '-extension' => '^1.0'];
        file_put_contents($folder . '/composer.json', json_encode($composer));
    }

    public function testLockMismatchAndMissingLockAreRejected(): void
    {
        $folder = $this->package('first', 'FixtureSdk\\');
        $lock = json_decode(file_get_contents($folder . '/composer.lock'), true);
        $lock['packages'][0]['version'] = '2.0.0';
        file_put_contents($folder . '/composer.lock', json_encode($lock));
        $this->reject($folder, '版本不一致');
        unlink($folder . '/composer.lock');
        $this->reject($folder, '元数据缺失');
    }

    public function testRootHooksAndCoreNamespaceAliasesAreRejected(): void
    {
        $folder = $this->package('first', 'FixtureSdk\\');
        $composer = json_decode(file_get_contents($folder . '/composer.json'), true);
        $composer['scripts'] = ['post-install-cmd' => 'echo forbidden'];
        file_put_contents($folder . '/composer.json', json_encode($composer));
        $this->reject($folder, '不开放脚本');
        unset($composer['scripts']);
        $composer['autoload'] = ['psr-4' => ['app\\' => 'api/']];
        file_put_contents($folder . '/composer.json', json_encode($composer));
        $this->reject($folder, '自己的命名空间');
    }

    public function testOutsideAutoloadPathAndCoreSdkNamespaceAreRejected(): void
    {
        $folder = $this->package('first', 'support\\');
        try {
            PluginDependencyLoader::load($folder, 'plugins\\mail\\first\\');
            self::fail('核心命名空间应拒绝');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('不能覆盖核心', $error->getMessage());
        }
        file_put_contents($folder . '/vendor/composer/autoload_psr4.php', '<?php return ["FixtureSdk\\\\" => ["/tmp"]];');
        try {
            PluginDependencyLoader::load($folder, 'plugins\\mail\\first\\');
            self::fail('越界自动加载应拒绝');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('路径越界', $error->getMessage());
        }
    }

    /** @param string $id 本地测试身份 @param string $prefix 测试 SDK 命名空间 @param string $version 精确版本 @return string 临时包目录 */
    private function package(string $id, string $prefix, string $version = '1.0.0'): string
    {
        $folder = $this->root . '/' . $id;
        mkdir($folder . '/vendor/fixture/sdk/src', 0700, true);
        mkdir($folder . '/vendor/composer', 0700);
        $package = ['name' => 'fixture/sdk-' . basename($this->root), 'version' => $version, 'type' => 'library',
            'source' => ['reference' => $version], 'autoload' => ['psr-4' => [$prefix => 'src/']]];
        file_put_contents($folder . '/composer.json', json_encode(['require' => [$package['name'] => '^1.0'], 'config' => ['allow-plugins' => false]]));
        file_put_contents($folder . '/composer.lock', json_encode(['packages' => [$package]]));
        file_put_contents($folder . '/vendor/composer/installed.json', json_encode(['packages' => [$package], 'dev' => false]));
        file_put_contents($folder . '/vendor/composer/autoload_psr4.php', '<?php return ' . var_export([$prefix => [$folder . '/vendor/fixture/sdk/src']], true) . ';');
        file_put_contents($folder . '/vendor/composer/autoload_namespaces.php', '<?php return [];');
        file_put_contents($folder . '/vendor/composer/autoload_classmap.php', '<?php return [];');
        file_put_contents($folder . '/vendor/fixture/sdk/src/Mailer.php', '<?php namespace ' . rtrim($prefix, '\\') . '; final class Mailer { public const VERSION = ' . var_export($version, true) . '; }');
        $constant = var_export('DEPENDENCY_LOADED_' . basename($this->root), true);
        $autoload = '<?php if (!defined(' . $constant . ')) { define(' . $constant . ', true); }'
            . '$loader = new \\Composer\\Autoload\\ClassLoader(__DIR__);'
            . '$loader->addPsr4(' . var_export($prefix, true) . ', __DIR__ . "/fixture/sdk/src");'
            . '$loader->register(); return $loader;';
        file_put_contents($folder . '/vendor/autoload.php', $autoload);
        return $folder;
    }

    /** @param string $folder 包 @param string $message 预期错误 @return void */
    private function reject(string $folder, string $message): void
    {
        try {
            PluginDependencyLoader::validate($folder, 'plugins\\mail\\first\\');
            self::fail('无效依赖应拒绝');
        } catch (RuntimeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
    }
}
