<?php

declare(strict_types=1);

use app\modules\website\infrastructure\FileThemeRegistry;
use app\modules\website\validation\{ThemeSchema, WebsiteSettings};
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 将官网SEO和公司资料迁出旧归属，升级默认主题并按原角色范围补齐页面权限。 */
final class SeparateWebsiteManagementSettings extends AbstractMigration
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
        $themeTable = $table('website_theme_settings');
        $systemTable = $table('system_settings');
        $website = $query("SELECT * FROM {$websiteTable} WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        if (!$website) throw new RuntimeException('官网配置缺失');
        $settings = json_decode($website['settings'], true, flags: JSON_THROW_ON_ERROR);
        $active = $query("SELECT settings FROM {$themeTable} WHERE theme_id = ?", [$website['active_theme_id']])->fetchColumn();
        $default = $query("SELECT * FROM {$themeTable} WHERE theme_id = 'default'")->fetch(PDO::FETCH_ASSOC);
        $oldDefault = $default ? json_decode($default['settings'], true, flags: JSON_THROW_ON_ERROR) : [];
        $company = ($active ? json_decode($active, true, flags: JSON_THROW_ON_ERROR)['pages']['company'] ?? [] : []) ?: ($oldDefault['pages']['company'] ?? []);
        $defaults = WebsiteSettings::defaults();
        $settings['company'] ??= array_replace($defaults['company'], array_intersect_key($company, $defaults['company']));
        foreach (['seo_keywords' => 'site.keywords', 'seo_description' => 'site.description'] as $field => $oldKey) {
            $value = $query("SELECT setting_value FROM {$systemTable} WHERE setting_key = ?", [$oldKey])->fetchColumn();
            if (!isset($settings[$field]) || $settings[$field] === '') $settings[$field] = $value === false ? '' : $value;
        }
        $settings['seo_title'] ??= '';
        (new ThemeSchema())->validate($settings, WebsiteSettings::schema());
        $root = dirname(__DIR__, 2);
        $version = require $root . '/config/version.php';
        $registry = new FileThemeRegistry($root . '/themes', $root . '/runtime/website-themes', $root . '/public/theme-assets', $version['current']);
        $package = $registry->read('default');
        if ($default) {
            $oldDefault['pages']['company'] = ['show_contact' => $oldDefault['pages']['company']['show_contact'] ?? true];
            (new ThemeSchema())->validate($oldDefault, $package['schema']);
            $registry->publish($package);
        }
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');
            $pointer = $default && $website['active_theme_id'] === 'default';
            $changed = $settings !== json_decode($website['settings'], true, flags: JSON_THROW_ON_ERROR) || ($pointer && $website['active_theme_digest'] !== $package['digest']);
            if ($changed) $query("UPDATE {$websiteTable} SET settings = ?, revision = revision + 1, updated_at = ?, active_theme_version = ?, active_theme_digest = ? WHERE id = 1",
                [json_encode($settings, JSON_THROW_ON_ERROR), $now, $pointer ? $package['manifest']['version'] : $website['active_theme_version'], $pointer ? $package['digest'] : $website['active_theme_digest']]);
            if ($default) {
                if ($oldDefault !== json_decode($default['settings'], true, flags: JSON_THROW_ON_ERROR)) $query("UPDATE {$themeTable} SET settings = ?, revision = revision + 1, updated_at = ? WHERE theme_id = 'default'", [json_encode($oldDefault, JSON_THROW_ON_ERROR), $now]);
                $query('UPDATE ' . $table('website_themes') . " SET name = ?, version = ?, digest = ?, updated_at = ? WHERE theme_id = 'default'", [$package['manifest']['name'], $package['manifest']['version'], $package['digest'], $now]);
            }
            $query("DELETE FROM {$systemTable} WHERE setting_key IN ('site.keywords', 'site.description')");
            $permissions = $table('admin_role_permissions');
            foreach (['base', 'navigation'] as $section) foreach (['view', 'update'] as $action) {
                $code = 'admin.website.' . $section . '.' . $action;
                $query("INSERT INTO {$permissions} (role_id, permission_code) SELECT source.role_id, ? FROM {$permissions} source WHERE source.permission_code = ? AND NOT EXISTS (SELECT 1 FROM {$permissions} existing WHERE existing.role_id = source.role_id AND existing.permission_code = ?)", [$code, 'admin.page.website.' . $action, $code]);
            }
            if ($ownTransaction) $pdo->commit();
        } catch (Throwable $error) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $query("ALTER TABLE {$websiteTable} MODIFY settings JSON NOT NULL COMMENT '官网品牌图片、SEO、公司资料及导航页脚配置，不含系统密钥'");
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('官网资料已独立维护，不能回填旧主题或覆盖系统设置；请使用前向迁移修复。');
    }
}
