<?php

declare(strict_types=1);

namespace app\modules\software_maintenance\infrastructure;

use app\modules\official_platform\exception\OfficialPlatformException;
use app\modules\official_platform\infrastructure\PrivateInstallationFiles;
use app\modules\plugin\contract\PluginInstallationRepositoryInterface;
use app\modules\plugin\infrastructure\LocalPluginPackageRegistry;
use app\modules\software_maintenance\contract\ReleaseInstallerInterface;
use app\modules\system_update\application\SystemMigrationService;
use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use Throwable;

/** 受信包的固定本地安装器；不接收远程命令，也不运行插件迁移脚本。 */
final readonly class LocalReleaseInstaller implements ReleaseInstallerInterface
{
    /**
     * @param VerifiedZipPackage $zip 路径预检
     * @param DatabaseBackup $backup 数据库备份
     * @param SystemMigrationService $migrations 受控迁移
     * @param PluginInstallationRepositoryInterface $plugins 生命周期写端口
     * @param string $root 后端根目录
     * @param string $private 私有持久目录
     * @param string $version 当前代码版本
     * @param MigrationDatabaseConfiguration $database 当前安装的数据库连接
     */
    public function __construct(
        private VerifiedZipPackage $zip,
        private DatabaseBackup $backup,
        private SystemMigrationService $migrations,
        private PluginInstallationRepositoryInterface $plugins,
        private string $root,
        private string $private,
        private string $version,
        private MigrationDatabaseConfiguration $database,
    ) {
    }

    /** @inheritDoc */
    public function prepareDirectory(string $directory): void
    {
        if (!preg_match('~^' . preg_quote($this->private . '/jobs/', '~') . '[a-f0-9-]{36}$~D', $directory)) {
            throw new OfficialPlatformException('INVALID_DOWNLOAD_PATH');
        }
        PrivateInstallationFiles::ensureDirectory($directory);
    }

    /** @inheritDoc */
    public function install(array $job, array $manifest, string $archive, callable $checkpoint): void
    {
        $work = $this->private . '/jobs/' . $job['request_id'];
        $checkpoint('precheck', '正在验证发布包');
        $files = $this->zip->extract($archive, $work . '/stage');
        if ($job['kind'] === 'application') {
            $this->installPlugin($job, $manifest, $work, $files, $checkpoint);
            return;
        }
        $release = $this->json($work . '/stage/howstack-release.json');
        if (($release['schema_version'] ?? '') !== 1 || ($release['software_code'] ?? '') !== 'howstack-bos'
            || ($release['version'] ?? '') !== $manifest['version'] || ($release['from_version'] ?? '') !== $this->version
            || !is_string($release['minimum_php'] ?? null) || version_compare(PHP_VERSION, $release['minimum_php'], '<')
            || !is_array($release['files'] ?? null) || $release['files'] === [] || !is_array($release['remove'] ?? null)) {
            throw new OfficialPlatformException('PACKAGE_PRECHECK_FAILED');
        }
        $expected = ['howstack-release.json'];
        foreach ($release['files'] as $path => $hash) {
            $this->corePath($path);
            if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)
                || !is_file($work . '/stage/files/' . $path) || !hash_equals($hash, hash_file('sha256', $work . '/stage/files/' . $path))) {
                throw new OfficialPlatformException('PACKAGE_INTEGRITY_ERROR');
            }
            if (str_starts_with($path, 'database/migrations/') && is_file($this->root . '/' . $path)
                && !hash_equals($hash, hash_file('sha256', $this->root . '/' . $path))) {
                throw new OfficialPlatformException('PACKAGE_PRECHECK_FAILED');
            }
            $expected[] = 'files/' . $path;
        }
        foreach ($release['remove'] as $path) {
            $this->corePath($path);
            // 历史迁移禁止删除，更新不允许覆盖已有迁移内容。
            if (str_starts_with($path, 'database/migrations/') || isset($release['files'][$path])) {
                throw new OfficialPlatformException('PACKAGE_PRECHECK_FAILED');
            }
        }
        sort($expected);
        sort($files);
        if ($expected !== $files || !isset($release['files']['config/version.php'])) {
            throw new OfficialPlatformException('PACKAGE_PRECHECK_FAILED');
        }
        $this->enterMaintenance($job['request_id']);
        $checkpoint('backup', '已进入维护模式，正在备份数据库和文件');
        try {
            // 等待已进入处理的短请求完成；长时任务须在发布前由运维排空。
            usleep(10000000);
            $this->backup->create($this->database, $work . '/database.sql');
            $paths = array_unique(array_merge(array_keys($release['files']), $release['remove']));
            $journal = $this->backupFiles($paths, $work);
            PrivateInstallationFiles::write($work . '/file-journal.json', json_encode($journal, JSON_THROW_ON_ERROR));
            $checkpoint('installing', '备份完成，正在替换系统文件');
            try {
                foreach ($release['files'] as $path => $hash) {
                    $this->putFile($work . '/stage/files/' . $path, $this->root . '/' . $path, 0644);
                }
                foreach ($release['remove'] as $path) {
                    if (is_file($this->root . '/' . $path) && !unlink($this->root . '/' . $path)) {
                        throw new OfficialPlatformException('STATE_IO_ERROR');
                    }
                }
                // 配置由受信核心代码产生；检查版本一致后再进入不可自动回滚的迁移阶段。
                $configurationVersion = require $this->root . '/config/version.php';
                if (($configurationVersion['current'] ?? '') !== $manifest['version']) {
                    throw new OfficialPlatformException('PACKAGE_PRECHECK_FAILED');
                }
            } catch (Throwable $error) {
                try {
                    $this->restoreFiles($journal, $work);
                } catch (Throwable $recoveryError) {
                    throw new OfficialPlatformException('FILE_RECOVERY_FAILED', $recoveryError);
                }
                throw $error;
            }
        } catch (Throwable $error) {
            if (!($error instanceof OfficialPlatformException) || $error->reason !== 'FILE_RECOVERY_FAILED') {
                $this->leaveMaintenance($job['request_id']);
            }
            throw $error;
        }
        $checkpoint('migrating', '正在执行前向数据库迁移');
        // 新PHP进程加载新依赖及迁移，失败后保持维护模式并保留完整备份。
        $this->command([PHP_BINARY, $this->root . '/webman', 'system:migrate', '--force']);
    }

    /** @inheritDoc */
    public function restart(): void
    {
        $this->command([PHP_BINARY, $this->root . '/start.php', 'restart', '-d']);
    }

    /** @inheritDoc */
    public function confirm(array $job): bool
    {
        if ($job['kind'] === 'system' && $this->version !== $job['target_version']) {
            return false;
        }
        if ($job['kind'] === 'system') {
            $status = $this->migrations->status($this->database, $this->version);
            if ($status->databaseVersion !== $this->version || $status->migrations->pending !== [] || $status->migrations->missing !== []) {
                return false;
            }
            $this->leaveMaintenance($job['request_id']);
            return true;
        }
        $packages = new LocalPluginPackageRegistry($this->root . '/plugins', $this->version);
        $package = $packages->packages()[$job['target_code']] ?? null;
        return $package !== null && $package['version'] === $job['target_version'];
    }

    /**
     * @param array<string,mixed> $job 任务
     * @param array<string,mixed> $manifest 外层元数据
     * @param string $work 临时目录
     * @param list<string> $files 文件
     * @param callable(string,string):void $checkpoint 阶段
     * @return void
     */
    private function installPlugin(array $job, array $manifest, string $work, array $files, callable $checkpoint): void
    {
        $raw = $this->json($work . '/stage/plugin.json');
        if (($raw['category'] ?? '') !== 'provider' || ($raw['id'] ?? '') !== $manifest['application_code']
            || ($raw['version'] ?? '') !== $manifest['version'] || $manifest['permissions'] !== []) {
            throw new OfficialPlatformException('PACKAGE_PRECHECK_FAILED');
        }
        $id = $raw['id'];
        $folder = $this->root . '/plugins/provider/' . $id;
        $this->safeDestination($folder);
        $record = $this->plugins->all()[$id] ?? null;
        if ($job['action'] === 'update' && ($record === null || !in_array($record['state'], ['installed', 'disabled'], true))) {
            throw new OfficialPlatformException('PLUGIN_MUST_BE_DISABLED');
        }
        // 已卸载应用可保留文件；重新安装仍走备份和包校验，不覆盖未登记目录。
        if ($job['action'] === 'install' && file_exists($folder) && ($record['state'] ?? null) !== 'uninstalled') {
            throw new OfficialPlatformException('PLUGIN_ALREADY_EXISTS');
        }
        $checkpoint('backup', '正在备份插件文件');
        PrivateInstallationFiles::ensureDirectory($work . '/previous');
        if (is_dir($folder) && !rename($folder, $work . '/previous/' . $id)) {
            throw new OfficialPlatformException('STATE_IO_ERROR');
        }
        $checkpoint('installing', '正在安装已确认的插件');
        try {
            PrivateInstallationFiles::ensureDirectory(dirname($folder));
            if (!rename($work . '/stage', $folder)) {
                throw new OfficialPlatformException('STATE_IO_ERROR');
            }
            // 专用CLI进程首次加载新包；固定接口校验失败时恢复原目录。
            $packages = new LocalPluginPackageRegistry($this->root . '/plugins', $this->version);
            $package = $packages->packages()[$id] ?? throw new OfficialPlatformException('PACKAGE_PRECHECK_FAILED');
            $this->plugins->transition($package, $job['action'] === 'install' ? 'install' : 'upgrade', (int) ($record['revision'] ?? 0),
                $job['actor_id'] === null ? null : (int) $job['actor_id'], ['client_ip' => $job['client_ip'], 'client_ip_location' => $job['client_ip_location']]);
        } catch (Throwable $error) {
            if (is_dir($folder)) {
                if (!rename($folder, $work . '/rejected')) {
                    throw new OfficialPlatformException('FILE_RECOVERY_FAILED', $error);
                }
            }
            if (is_dir($work . '/previous/' . $id) && !rename($work . '/previous/' . $id, $folder)) {
                throw new OfficialPlatformException('FILE_RECOVERY_FAILED', $error);
            }
            throw $error;
        }
    }

    /**
     * @param string $path 后端相对路径
     * @return void 核心白名单与历史迁移保护
     */
    private function corePath(string $path): void
    {
        if (str_contains($path, '\\') || str_contains($path, "\0") || preg_match('~(^|/)(\.|\.\.)(/|$)|//~', $path)
            || !preg_match('~^(app/|support/|vendor/|database/migrations/|public/(admin/|console/|install-assets/app/)|config/|themes/default/|composer\.(json|lock)$|start\.php$|webman$)~D', $path)
            || preg_match('~^config/(database|authentication|official_platform|client_ip)\.php$~D', $path)) {
            throw new OfficialPlatformException('UNSAFE_PACKAGE_PATH');
        }
        $destination = $this->root . '/' . $path;
        $this->safeDestination($destination);

    }

    /**
     * @param string $path 固定根目录内路径
     * @return void 防止已有符号链接劫持覆盖
     */
    private function safeDestination(string $path): void
    {
        if (!str_starts_with($path, $this->root . '/')) {
            throw new OfficialPlatformException('UNSAFE_PACKAGE_PATH');
        }
        for ($current = $path; strlen($current) > strlen($this->root); $current = dirname($current)) {
            if (is_link($current)) {
                throw new OfficialPlatformException('UNSAFE_PACKAGE_PATH');
            }
        }
    }

    /**
     * @param list<string> $paths 文件
     * @param string $work 临时目录
     * @return array<string,bool> 文件恢复日志
     */
    private function backupFiles(array $paths, string $work): array
    {
        $journal = [];
        foreach ($paths as $path) {
            $source = $this->root . '/' . $path;
            $journal[$path] = is_file($source);
            if ($journal[$path]) {
                $this->putFile($source, $work . '/previous/' . $path, 0600);
            }
        }
        return $journal;
    }

    /**
     * @param array<string,bool> $journal 备份日志
     * @param string $work 私有目录
     * @return void 文件恢复；失败须人工处理
     */
    private function restoreFiles(array $journal, string $work): void
    {
        foreach ($journal as $path => $existed) {
            if ($existed) {
                $this->putFile($work . '/previous/' . $path, $this->root . '/' . $path, 0644);
            } elseif (is_file($this->root . '/' . $path) && !unlink($this->root . '/' . $path)) {
                throw new OfficialPlatformException('FILE_RECOVERY_FAILED');
            }
        }
    }

    /**
     * @param string $source 已验证文件
     * @param string $destination 受控目标
     * @param int $mode 权限
     * @return void 原子替换
     */
    private function putFile(string $source, string $destination, int $mode): void
    {
        if (str_starts_with($destination, $this->root . '/')) {
            $this->safeDestination($destination);
        }
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true)) {
            throw new OfficialPlatformException('STATE_IO_ERROR');
        }
        $temporary = $destination . '.howstack-' . bin2hex(random_bytes(8));
        if (!copy($source, $temporary) || !chmod($temporary, $mode) || !rename($temporary, $destination)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw new OfficialPlatformException('STATE_IO_ERROR');
        }
    }

    /**
     * @param string $file 小体积JSON
     * @return array<string,mixed> 声明
     */
    private function json(string $file): array
    {
        if (!is_file($file) || filesize($file) > 1048576) {
            throw new OfficialPlatformException('PACKAGE_PRECHECK_FAILED');
        }
        try {
            $value = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new OfficialPlatformException('PACKAGE_PRECHECK_FAILED', $error);
        }
        if (!is_array($value)) {
            throw new OfficialPlatformException('PACKAGE_PRECHECK_FAILED');
        }
        return $value;
    }

    /**
     * @param string $id 实例任务UUID
     * @return void 阻止业务写入
     */
    private function enterMaintenance(string $id): void
    {
        PrivateInstallationFiles::write($this->private . '/maintenance.json', json_encode(['request_id' => $id, 'started_at' => time()], JSON_THROW_ON_ERROR));
    }

    /**
     * @param string $id 当前任务
     * @return void 仅移除本任务维护标志
     */
    private function leaveMaintenance(string $id): void
    {
        $file = $this->private . '/maintenance.json';
        if (is_file($file) && ($this->json($file)['request_id'] ?? '') === $id && !unlink($file)) {
            throw new OfficialPlatformException('STATE_IO_ERROR');
        }
    }

    /**
     * @param list<string> $arguments 固定命令与参数，无shell
     * @return void 仅成功退出确认完成
     */
    private function command(array $arguments): void
    {
        $process = proc_open($arguments, [['pipe', 'r'], ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']], $pipes, $this->root);
        if (!is_resource($process)) {
            throw new OfficialPlatformException('MAINTENANCE_COMMAND_FAILED');
        }
        fclose($pipes[0]);
        if (proc_close($process) !== 0) {
            throw new OfficialPlatformException('MAINTENANCE_COMMAND_FAILED');
        }
    }
}
