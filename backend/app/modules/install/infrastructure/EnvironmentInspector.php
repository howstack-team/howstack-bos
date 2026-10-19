<?php

declare(strict_types=1);

namespace app\modules\install\infrastructure;

use app\modules\install\contract\EnvironmentInspectorInterface;
use PDO;

/**
 * 检测安装所需的 PHP 环境、目录权限和前端资源。
 */
final class EnvironmentInspector implements EnvironmentInspectorInterface
{
    /**
     * 执行完整安装环境检测。
     * @return array{passed: bool, items: list<array<string, bool|string>>} 环境检测结果
     */
    public function inspect(): array
    {
        $items = [];
        $items[] = $this->item(
            'php_version',
            'PHP 版本',
            PHP_VERSION,
            version_compare(PHP_VERSION, '8.3.0', '>='),
            true,
            '要求 PHP 8.3 或更高版本',
        );

        foreach ([
            'pdo_mysql' => 'PDO MySQL',
            'redis' => 'Redis 扩展',
            'json' => 'JSON 扩展',
            'mbstring' => 'Mbstring 扩展',
            'openssl' => 'OpenSSL 扩展',
            'fileinfo' => 'Fileinfo 扩展',
        ] as $extension => $label) {
            $loaded = extension_loaded($extension);
            $items[] = $this->item(
                "extension_{$extension}",
                $label,
                $loaded ? '已安装' : '未安装',
                $loaded,
                true,
                $loaded ? '' : "请安装并启用 PHP {$extension} 扩展",
            );
        }

        $mysqlDriverAvailable = in_array('mysql', PDO::getAvailableDrivers(), true);
        $items[] = $this->item(
            'pdo_mysql_driver',
            'PDO MySQL 驱动',
            $mysqlDriverAvailable ? '可用' : '不可用',
            $mysqlDriverAvailable,
            true,
            $mysqlDriverAvailable ? '' : '当前 PHP 环境未加载 pdo_mysql 驱动，请安装并启用后重新检测',
        );

        $basePath = base_path(false);
        $envPath = $basePath . '/.env';
        $envWritable = is_file($envPath) ? is_writable($envPath) : is_writable($basePath);
        $items[] = $this->item(
            'env_writable',
            '系统配置写入',
            $envWritable ? '可写' : '不可写',
            $envWritable,
            true,
            $envWritable ? '' : '请为系统安装目录授予当前服务进程创建和修改配置文件的权限',
        );

        $runtimePath = runtime_path();
        $runtimeWritable = is_dir($runtimePath) && is_writable($runtimePath);
        $items[] = $this->item(
            'runtime_writable',
            '系统运行目录',
            $runtimeWritable ? '可写' : '不可写',
            $runtimeWritable,
            true,
            $runtimeWritable ? '' : '请确保系统运行目录存在，并授予当前服务进程写入权限',
        );

        $assetPaths = [
            public_path() . '/install-assets/app/index.html',
        ];
        $applicationScripts = glob(public_path() . '/install-assets/app/assets/*.js') ?: [];
        $applicationStyles = glob(public_path() . '/install-assets/app/assets/*.css') ?: [];
        $assetsReadable = array_all(
            $assetPaths,
            static fn (string $path): bool => is_file($path) && is_readable($path),
        ) && array_any(
            $applicationScripts,
            static fn (string $path): bool => is_file($path) && is_readable($path),
        ) && array_any(
            $applicationStyles,
            static fn (string $path): bool => is_file($path) && is_readable($path),
        );
        $items[] = $this->item(
            'install_assets',
            '安装页面资源',
            $assetsReadable ? '完整' : '缺失',
            $assetsReadable,
            true,
            $assetsReadable ? '' : '安装页面资源不完整，请重新上传完整的系统安装包',
        );

        $opcacheLoaded = extension_loaded('Zend OPcache');
        $items[] = $this->item(
            'extension_opcache',
            'OPcache 扩展',
            $opcacheLoaded ? '已安装' : '未安装',
            $opcacheLoaded,
            false,
            $opcacheLoaded ? '' : '推荐安装并启用 PHP OPcache 扩展以提升运行性能，未安装不影响继续安装',
        );

        $eventLoaded = extension_loaded('event');
        $items[] = $this->item(
            'extension_event',
            'Event 扩展',
            $eventLoaded ? '已安装' : '未安装',
            true,
            false,
            $eventLoaded ? '' : '可选的性能扩展，不影响安装',
        );

        $passed = true;
        foreach ($items as $item) {
            if ($item['required'] && !$item['passed']) {
                $passed = false;
                break;
            }
        }

        return ['passed' => $passed, 'items' => $items];
    }

    /**
     * 创建单个环境检测结果项。
     * @param string $key      稳定检测项标识
     * @param string $name     检测项名称
     * @param string $value    当前检测值
     * @param bool   $passed   是否通过检测
     * @param bool   $required 是否为必需项
     * @param string $help     未通过时的处理提示
     * @return array<string, bool|string> 环境检测结果项
     */
    private function item(
        string $key,
        string $name,
        string $value,
        bool $passed,
        bool $required,
        string $help,
    ): array {
        return compact('key', 'name', 'value', 'passed', 'required', 'help');
    }
}
