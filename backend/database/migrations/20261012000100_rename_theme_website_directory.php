<?php

declare(strict_types=1);

use app\modules\website\infrastructure\{FileThemeRegistry, UpgradeWebsiteDirectory};
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 将已安装主题的 template 目录迁为 website，保留主题ID、配置与移动端选择。 */
final class RenameThemeWebsiteDirectory extends AbstractMigration
{
    /** 新资源先发布，登记在事务中切换；失败仍可读取原快照并安全重试。 */
    public function up(): void
    {
        $adapter = $this->getAdapter();
        $prefix = $adapter->getOption('table_prefix') ?? '';
        $quote = fn (string $table): string => $adapter->quoteTableName($prefix . $table);
        $pdo = $adapter->getConnection();
        $registry = new FileThemeRegistry(base_path('themes'), runtime_path('website-themes'), public_path('theme-assets'), (require base_path('config/version.php'))['current']);
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $binding = $pdo->query('SELECT * FROM ' . $quote('site_theme_bindings') . ' WHERE id=1' . $lock)->fetch(PDO::FETCH_ASSOC);
            $rows = $pdo->query('SELECT * FROM ' . $quote('site_theme_releases') . ' ORDER BY id' . $lock)->fetchAll(PDO::FETCH_ASSOC);
            $changed = [];
            $now = date('Y-m-d H:i:s');
            foreach ($rows as $row) {
                if (!in_array('website', json_decode($row['components'], true, flags: JSON_THROW_ON_ERROR), true)) {
                    continue;
                }
                $package = $registry->pinned($row['theme_id'], $row['version'], $row['digest']);
                if (is_dir($package['directory'] . '/website')) {
                    continue;
                }
                $temporary = runtime_path('theme-website-upgrade/' . bin2hex(random_bytes(10)));
                try {
                    (new UpgradeWebsiteDirectory())->convert($package, $temporary);
                    $converted = (new FileThemeRegistry($temporary, runtime_path('website-themes'), public_path('theme-assets'), (require base_path('config/version.php'))['current']))->read($row['theme_id']);
                    $registry->publish($converted);
                    $update = $pdo->prepare('UPDATE ' . $quote('site_theme_releases') . ' SET digest=?,updated_at=? WHERE id=?');
                    $update->execute([$converted['digest'], $now, $row['id']]);
                    $changed[] = (int) $row['id'];
                } finally {
                    if (is_dir($temporary)) {
                        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                        }
                        rmdir($temporary);
                    }
                }
            }
            if ($binding && array_intersect($changed, [(int) $binding['release_id'], (int) $binding['default_release_id']]) !== []) {
                $update = $pdo->prepare('UPDATE ' . $quote('site_theme_bindings') . ' SET revision=revision+1,updated_at=? WHERE id=1');
                $update->execute([$now]);
            }
            foreach ($changed as $id) {
                $update = $pdo->prepare('UPDATE ' . $quote('website_settings') . ' SET revision=revision+1,updated_at=? WHERE active_release_id=?');
                $update->execute([$now, $id]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** 旧快照保留，回退使用数据库备份或前向修复，不能删除客户主题配置。 */
    public function down(): void
    {
        throw new IrreversibleMigrationException('主题目录通过前向迁移修复，恢复数据库时须同时保留主题快照');
    }
}
