<?php

declare(strict_types=1);

use app\modules\website\infrastructure\FileThemeRegistry;
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 删除平台副标题设置记录，发布不再依赖该字段的默认主题模板。 */
final class RemovePlatformSubtitle extends AbstractMigration
{
    public function up(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        $table = fn (string $name): string => $this->getAdapter()->quoteTableName($this->getAdapter()->getOption('table_prefix') . $name);
        $query = static function (string $sql, array $parameters = []) use ($pdo): PDOStatement {
            $statement = $pdo->prepare($sql);
            $statement->execute($parameters);
            return $statement;
        };
        $websiteTable = $table('website_settings');
        $catalogTable = $table('website_themes');
        $default = $query("SELECT digest FROM {$catalogTable} WHERE theme_id = 'default'")->fetch(PDO::FETCH_ASSOC);
        $package = null;
        if ($default) {
            $root = dirname(__DIR__, 2);
            $version = require $root . '/config/version.php';
            $registry = new FileThemeRegistry($root . '/themes', $root . '/runtime/website-themes', $root . '/public/theme-assets', $version['current']);
            $package = $registry->read('default');
            $registry->publish($package);
        }
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $query('DELETE FROM ' . $table('system_settings') . " WHERE setting_key = 'site.subtitle'");
            if ($package && !hash_equals($default['digest'], $package['digest'])) {
                $now = date('Y-m-d H:i:s');
                $query("UPDATE {$catalogTable} SET version = ?, digest = ?, updated_at = ? WHERE theme_id = 'default'", [$package['manifest']['version'], $package['digest'], $now]);
                $query("UPDATE {$websiteTable} SET active_theme_version = ?, active_theme_digest = ?, revision = revision + 1, updated_at = ? WHERE active_theme_id = 'default' AND active_theme_digest <> ?", [$package['manifest']['version'], $package['digest'], $now, $package['digest']]);
            }
            if ($ownTransaction) $pdo->commit();
        } catch (Throwable $error) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('平台副标题已删除，请使用前向迁移修复，不恢复旧设置。');
    }
}
