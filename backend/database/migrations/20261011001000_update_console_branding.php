<?php

declare(strict_types=1);

use app\modules\website\infrastructure\FileThemeRegistry;
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 安装内置会员中心品牌修复版本；仅升级使用旧内置版本的入口，保留版本回退。 */
final class UpdateConsoleBranding extends AbstractMigration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        $prefix = $adapter->getOption('table_prefix') ?? '';
        $q = static fn (string $name): string => $adapter->quoteTableName($prefix . $name);
        $pdo = $adapter->getConnection();
        $version = require dirname(__DIR__, 2) . '/config/version.php';
        $registry = new FileThemeRegistry(dirname(__DIR__, 2) . '/themes', runtime_path('website-themes'), public_path('theme-assets'), $version['current']);
        $package = $registry->read('official-console');
        $find = $pdo->prepare('SELECT id,digest FROM ' . $q('site_theme_releases') . ' WHERE theme_id=? AND version=?');
        $find->execute(['official-console', $package['manifest']['version']]);
        $release = $find->fetch(PDO::FETCH_ASSOC);
        $find->closeCursor();
        if ($release && $release['digest'] !== $package['digest']) {
            throw new RuntimeException('内置会员主题同版本内容不一致，请增加版本号后升级');
        }
        $registry->publish($package);
        $now = date('Y-m-d H:i:s');
        if (!$release) {
            $this->table('site_theme_releases')->insert([
                'theme_id' => 'official-console', 'name' => $package['manifest']['name'],
                'version' => $package['manifest']['version'], 'digest' => $package['digest'],
                'components' => json_encode(array_keys($package['console']), JSON_THROW_ON_ERROR),
                'installed_by' => null, 'installed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ])->saveData();
            $find->execute(['official-console', $package['manifest']['version']]);
            $release = $find->fetch(PDO::FETCH_ASSOC);
            $find->closeCursor();
            foreach ($package['console'] as $type => $definition) {
                $this->table('site_theme_configurations')->insert([
                    'release_id' => $release['id'], 'component' => $type,
                    'settings' => json_encode($definition['defaults'], JSON_THROW_ON_ERROR),
                    'revision' => 1, 'created_at' => $now, 'updated_at' => $now,
                ])->saveData();
            }
        }
        // 仅自适应内置入口升级。第三方主题、PC/移动端选择及官网入口不变。
        $binding = $pdo->query('SELECT responsive FROM ' . $q('console_theme_bindings') . ' WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if (!$binding || $binding['responsive'] === null || (int) $binding['responsive'] === (int) $release['id']) {
            return;
        }
        $old = $pdo->prepare('SELECT theme_id,version FROM ' . $q('site_theme_releases') . ' WHERE id=?');
        $old->execute([$binding['responsive']]);
        $previous = $old->fetch(PDO::FETCH_ASSOC);
        $old->closeCursor();
        if (!$previous || $previous['theme_id'] !== 'official-console' || !version_compare($previous['version'], $package['manifest']['version'], '<')) {
            return;
        }
        $update = $pdo->prepare('UPDATE ' . $q('console_theme_bindings') . ' SET responsive=?,revision=revision+1,updated_at=? WHERE id=1 AND responsive=?');
        $update->execute([$release['id'], $now, $binding['responsive']]);
        $update->closeCursor();
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('通过主题管理切换原版本，禁止删除已经交付的主题快照');
    }
}
