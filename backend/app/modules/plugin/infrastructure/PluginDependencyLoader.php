<?php

declare(strict_types=1);

namespace app\modules\plugin\infrastructure;

use Composer\Autoload\ClassLoader;
use RuntimeException;

/** 加载已审核包自己的 Composer 依赖，不执行 Composer 命令或安装脚本。 */
final class PluginDependencyLoader
{
    /** @var array<string, ClassLoader> 当前进程已加载的依赖；更新后必须完整重启 */
    private static array $loaded = [];

    /**
     * 只读取声明和锁定资料，不执行依赖中的 PHP。
     * @param string $folder 已验证的插件目录
     * @param string $namespace 插件自己的命名空间前缀
     * @return void
     * @throws RuntimeException 依赖未构建、存在未锁定包或越界声明
     */
    public static function validate(string $folder, string $namespace): void
    {
        if (!is_file($folder . '/composer.json')) {
            if (file_exists($folder . '/composer.lock') || file_exists($folder . '/vendor')) {
                throw new RuntimeException('插件依赖缺少 composer.json');
            }
            return;
        }
        $composer = self::json($folder . '/composer.json');
        if (!empty($composer['scripts']) || !empty($composer['autoload']['files'])
            || !empty($composer['autoload']['psr-0']) || !empty($composer['autoload']['classmap'])
            || ($composer['config']['vendor-dir'] ?? 'vendor') !== 'vendor'
            || ($composer['config']['allow-plugins'] ?? false) !== false) {
            throw new RuntimeException('插件 Composer 不开放脚本、启动文件、安装扩展或自定义依赖目录');
        }
        foreach (array_keys($composer['autoload']['psr-4'] ?? []) as $prefix) {
            if (!str_starts_with($prefix, $namespace)) {
                throw new RuntimeException('插件 Composer 只能声明自己的命名空间');
            }
        }
        $lock = self::json($folder . '/composer.lock');
        $installed = self::installed($folder . '/vendor');
        if (!is_file($folder . '/vendor/autoload.php') || ($installed['dev'] ?? true) !== false) {
            throw new RuntimeException('插件依赖未按生产模式构建，请交付锁文件和 vendor');
        }
        $expected = array_column($lock['packages'] ?? [], null, 'name');
        $actual = array_column($installed['packages'], null, 'name');
        if (array_diff_key($expected, $actual) !== [] || array_diff_key($actual, $expected) !== []) {
            throw new RuntimeException('插件依赖与锁文件不一致');
        }
        foreach ($actual as $name => $package) {
            if (($package['type'] ?? '') === 'composer-plugin'
                || self::identity($package) !== self::identity($expected[$name])) {
                throw new RuntimeException('插件依赖版本不一致或包含 Composer 安装扩展');
            }
        }
        foreach (array_keys($composer['require'] ?? []) as $name) {
            if ($name !== 'php' && !str_starts_with($name, 'ext-') && !isset($actual[$name])) {
                throw new RuntimeException('插件声明的依赖未安装');
            }
        }
    }

    /**
     * 宿主确认安装状态及包摘要后调用；同名依赖不同版本明确拒绝，不依赖加载顺序。
     * @param string $folder 已批准的包目录
     * @param string $namespace 当前插件命名空间
     * @return void
     * @throws RuntimeException 依赖越界、版本冲突或加载失败
     */
    public static function load(string $folder, string $namespace): void
    {
        self::validate($folder, $namespace);
        if (!is_file($folder . '/composer.json')) {
            return;
        }
        $vendor = realpath($folder . '/vendor');
        if ($vendor === false || isset(self::$loaded[$vendor])) {
            return;
        }
        $packages = array_column(self::installed($vendor)['packages'], null, 'name');
        $psr4 = self::map($vendor . '/composer/autoload_psr4.php');
        $psr0 = self::map($vendor . '/composer/autoload_namespaces.php');
        $classmap = self::map($vendor . '/composer/autoload_classmap.php');
        $files = is_file($vendor . '/composer/autoload_files.php')
            ? self::map($vendor . '/composer/autoload_files.php') : [];
        $root = realpath($folder) . '/';
        foreach ([$psr4, $psr0, $classmap, $files] as $map) {
            foreach ($map as $paths) {
                foreach ((array) $paths as $path) {
                    $resolved = is_string($path) ? realpath($path) : false;
                    if ($resolved === false || !str_starts_with($resolved, $root)) {
                        throw new RuntimeException('插件 Composer 自动加载路径越界');
                    }
                }
            }
        }
        $prefixes = array_unique([...array_keys($psr4), ...array_keys($psr0), ...array_keys($classmap)]);
        foreach ($prefixes as $prefix) {
            if ($prefix === '' || preg_match('/^(app|support|webman|illuminate|di)\\\\/i', $prefix)
                || (str_starts_with(strtolower($prefix), 'plugins\\') && !str_starts_with($prefix, $namespace))) {
                throw new RuntimeException('插件依赖不能覆盖核心或其他插件的命名空间');
            }
        }
        foreach (ClassLoader::getRegisteredLoaders() as $existingVendor => $loader) {
            $existing = is_file($existingVendor . '/composer/installed.json')
                ? array_column(self::installed($existingVendor)['packages'], null, 'name') : [];
            foreach (array_intersect_key($packages, $existing) as $name => $package) {
                if (self::identity($package) !== self::identity($existing[$name])) {
                    throw new RuntimeException('插件 SDK 版本冲突，请在构建时隔离依赖包身份和命名空间');
                }
            }
            $oldPrefixes = [
                ...array_keys($loader->getPrefixesPsr4()),
                ...array_keys($loader->getPrefixes()),
                ...array_keys($loader->getClassMap()),
            ];
            foreach ($prefixes as $prefix) {
                // Composer 自身共用基础设施；插件自身类由系统 plugins PSR-4 映射覆盖。
                if ($prefix === 'Composer\\InstalledVersions' || str_starts_with($prefix, $namespace)) {
                    continue;
                }
                foreach ($oldPrefixes as $old) {
                    if ($old === '' || !self::overlaps($prefix, $old)
                        || !self::sharesFiles($prefix, $old, $loader, $psr4, $psr0, $classmap)) {
                        continue;
                    }
                    if (str_ends_with($prefix, '\\') && strcasecmp($prefix, $old) === 0
                        && isset($psr4[$prefix], $loader->getPrefixesPsr4()[$old])
                        && self::compatibleNamespace($prefix, (array) $psr4[$prefix], $loader, $packages, $existing, $classmap, $vendor)) {
                        continue;
                    }
                    // 宽前缀与已加载的具体类重叠时，按该类的实际所属包比较；
                    // 腾讯云业务 SDK 和 common 都声明 TencentCloud，不能把 common 误认作业务包。
                    $candidate = str_ends_with($prefix, '\\') && !str_ends_with($old, '\\') ? $old : $prefix;
                    $owner = self::owner($candidate, $packages, $classmap, $vendor);
                    if ($owner === null || !isset($existing[$owner])
                        || self::identity($packages[$owner]) !== self::identity($existing[$owner])) {
                        throw new RuntimeException('插件 SDK 命名空间冲突，请在构建时使用独立前缀');
                    }
                }
            }
        }
        $loader = require $vendor . '/autoload.php';
        if (!$loader instanceof ClassLoader) {
            throw new RuntimeException('插件 Composer 自动加载器无效');
        }
        // 已加载的同版本依赖保持原解析顺序，不覆盖系统或先前插件的类。
        $loader->unregister();
        $loader->register(false);
        self::$loaded[$vendor] = $loader;
    }

    /**
     * 类映射只比较完整类名；命名空间前缀必须在反斜线边界重叠。
     * @param string $first 类名或命名空间前缀
     * @param string $second 类名或命名空间前缀
     * @return bool 是否可能解析同一个类
     */
    private static function overlaps(string $first, string $second): bool
    {
        $first = strtolower($first);
        $second = strtolower($second);
        return $first === $second
            || (str_ends_with($first, '\\') && str_starts_with($second, $first))
            || (str_ends_with($second, '\\') && str_starts_with($first, $second));
    }

    /**
     * 独立子命名空间可扩展同一个厂商前缀，例如 GuzzleHttp\Command；仍拒绝同名类或实际目录覆盖。
     * @param string $current 当前类或前缀
     * @param string $existing 已加载类或前缀
     * @param ClassLoader $loader 已注册加载器
     * @param array<string, mixed> $psr4 新包 PSR-4 映射
     * @param array<string, mixed> $psr0 新包 PSR-0 映射
     * @param array<string, mixed> $classmap 新包类映射
     * @return bool 是否可能覆盖已存在文件
     */
    private static function sharesFiles(
        string $current,
        string $existing,
        ClassLoader $loader,
        array $psr4,
        array $psr0,
        array $classmap,
    ): bool
    {
        if (!str_ends_with($current, '\\')) {
            return $loader->findFile($current) !== false;
        }
        if (!str_ends_with($existing, '\\')) {
            if (isset($classmap[$existing])) {
                return true;
            }
            $suffix = str_replace('\\', '/', substr($existing, strlen($current))) . '.php';
            foreach ((array) ($psr4[$current] ?? []) as $path) {
                if (is_file($path . '/' . $suffix)) {
                    return true;
                }
            }
            return isset($psr0[$current]);
        }
        if (strcasecmp($current, $existing) === 0 || isset($psr0[$current]) || isset($loader->getPrefixes()[$existing])) {
            return true;
        }
        $currentIsChild = strlen($current) > strlen($existing);
        $suffix = str_replace('\\', '/', substr($currentIsChild ? $current : $existing, strlen($currentIsChild ? $existing : $current)));
        $paths = $currentIsChild ? ($loader->getPrefixesPsr4()[$existing] ?? []) : ($psr4[$current] ?? []);
        foreach ((array) $paths as $path) {
            if (is_dir($path . '/' . $suffix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 多个腾讯云子 SDK 声明同一厂商前缀，按实际类文件确认互不覆盖或来自同一锁定依赖。
     * @param string $prefix 相同 PSR-4 前缀
     * @param list<string> $paths 当前前缀目录
     * @param ClassLoader $loader 现有加载器
     * @param array<string, array<string, mixed>> $packages 当前依赖
     * @param array<string, array<string, mixed>> $existing 现有依赖
     * @param array<string, mixed> $classmap 当前类映射
     * @param string $vendor 当前 vendor
     * @return bool 是否可以共享前缀
     */
    private static function compatibleNamespace(
        string $prefix,
        array $paths,
        ClassLoader $loader,
        array $packages,
        array $existing,
        array $classmap,
        string $vendor,
    ): bool
    {
        foreach ($paths as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen(rtrim($directory, '/')) + 1, -4);
                $class = $prefix . str_replace('/', '\\', $relative);
                if ($loader->findFile($class) === false) {
                    continue;
                }
                $owner = self::owner($class, $packages, $classmap, $vendor);
                if ($owner === null || !isset($existing[$owner])
                    || self::identity($packages[$owner]) !== self::identity($existing[$owner])) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @param string $path 固定 JSON 文件
     * @return array<string, mixed> 依赖元数据
     * @throws RuntimeException 元数据无效
     */
    private static function json(string $path): array
    {
        if (!is_file($path) || is_link($path) || filesize($path) > 2097152) {
            throw new RuntimeException('插件 Composer 元数据缺失或无效');
        }
        $value = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('插件 Composer 元数据必须为对象');
        }
        return $value;
    }

    /**
     * @param string $vendor 当前依赖目录
     * @return array<string, mixed> 已安装包资料
     * @throws RuntimeException 元数据无效
     */
    private static function installed(string $vendor): array
    {
        $data = self::json($vendor . '/composer/installed.json');
        if (!is_array($data['packages'] ?? null) || !array_is_list($data['packages'])) {
            throw new RuntimeException('插件 Composer 安装清单无效');
        }
        return $data;
    }

    /**
     * @param string $path 已审核生成文件
     * @return array<string, mixed> Composer 自动加载映射
     * @throws RuntimeException 映射无效
     */
    private static function map(string $path): array
    {
        $value = require $path;
        if (!is_array($value)) {
            throw new RuntimeException('插件 Composer 自动加载映射无效');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $package 锁定依赖
     * @return array{mixed, mixed} 精确版本及来源标识
     */
    private static function identity(array $package): array
    {
        return [$package['version'] ?? null, $package['source']['reference'] ?? $package['dist']['reference'] ?? null];
    }

    /**
     * @param string $prefix 类或命名空间
     * @param array<string, array<string, mixed>> $packages 依赖
     * @param array<string, mixed> $classmap 本插件生成的类映射
     * @param string $vendor 本插件依赖目录
     * @return string|null 声明该命名空间或类的包
     */
    private static function owner(string $prefix, array $packages, array $classmap, string $vendor): ?string
    {
        $bestOwner = null;
        $bestLength = -1;
        foreach ($packages as $name => $package) {
            $installPath = $package['install-path'] ?? null;
            $directory = is_string($installPath) ? realpath($vendor . '/composer/' . $installPath) : false;
            if ($directory !== false && isset($classmap[$prefix])
                && str_starts_with($classmap[$prefix], $directory . '/')) {
                return $name;
            }
            foreach (['psr-4', 'psr-0'] as $kind) {
                foreach (array_keys($package['autoload'][$kind] ?? []) as $declared) {
                    if (str_starts_with($prefix, $declared) && strlen($declared) > $bestLength) {
                        $bestOwner = $name;
                        $bestLength = strlen($declared);
                    }
                }
            }
        }
        return $bestOwner;
    }
}
