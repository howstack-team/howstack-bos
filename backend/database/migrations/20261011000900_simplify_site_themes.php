<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;
use app\modules\website\infrastructure\FileThemeRegistry;
use app\modules\file\domain\FileReferences;

/** 统一版本配置为唯一来源；删除旧主题目录及配置表，不修改已执行迁移。 */
final class SimplifySiteThemes extends AbstractMigration
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        $prefix = $adapter->getOption('table_prefix') ?? '';
        $q = fn (string $name): string => $adapter->quoteTableName($prefix . $name);
        $pdo = $adapter->getConnection();
        $website = $pdo->query('SELECT * FROM ' . $q('website_settings') . ' WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $find = $pdo->prepare('SELECT id FROM ' . $q('site_theme_releases') . ' WHERE digest=?');
        $find->execute([$website['active_theme_digest']]);
        $active = $find->fetchColumn(); $find->closeCursor();
        if (!$active) throw new RuntimeException('当前官网主题未登记，停止迁移');
        $obsolete = [];
        $releases = $pdo->query('SELECT * FROM ' . $q('site_theme_releases'))->fetchAll(PDO::FETCH_ASSOC);
        foreach ($releases as $release) {
            $types = json_decode($release['components'], true, flags: JSON_THROW_ON_ERROR);
            if (array_diff($types, ['website']) === []) continue;
            $manifestPath = runtime_path('website-themes/' . $release['digest'] . '/' . $release['theme_id'] . '/theme.json');
            $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            $entries = $manifest['components']['console']['entries'] ?? [];
            if (!array_filter($entries, fn (array $entry): bool => !isset($entry['index']))) continue;
            $config = $pdo->prepare('SELECT settings FROM ' . $q('site_theme_configurations') . ' WHERE release_id=?'); $config->execute([$release['id']]);
            if ($release['theme_id'] !== 'official-console' || array_filter($config->fetchAll(PDO::FETCH_COLUMN), fn ($value) => json_decode($value, true) !== [])) {
                throw new RuntimeException('旧会员模块主题含定制配置，请先备份并重新交付HTML主题包');
            }
            $obsolete[] = $release;
        }
        $version = require dirname(__DIR__, 2) . '/config/version.php';
        $registry = new FileThemeRegistry(dirname(__DIR__, 2) . '/themes', runtime_path('website-themes'), public_path('theme-assets'), $version['current']);
        $official = $registry->read('official-console');
        $registry->publish($official);
        $find = $pdo->prepare('SELECT id FROM ' . $q('site_theme_releases') . ' WHERE theme_id=? AND version=?');
        $find->execute(['official-console', $official['manifest']['version']]);
        $officialId = $find->fetchColumn();
        $now = date('Y-m-d H:i:s');
        if (!$officialId) {
            $this->table('site_theme_releases')->insert(['theme_id' => 'official-console', 'name' => $official['manifest']['name'], 'version' => $official['manifest']['version'],
                'digest' => $official['digest'], 'components' => json_encode(array_keys($official['console']), JSON_THROW_ON_ERROR), 'installed_by' => null, 'installed_at' => $now, 'created_at' => $now, 'updated_at' => $now])->saveData();
            $find->execute(['official-console', $official['manifest']['version']]); $officialId = $find->fetchColumn();
            foreach ($official['console'] as $type => $definition) $this->table('site_theme_configurations')->insert(['release_id' => $officialId, 'component' => $type,
                'settings' => json_encode($definition['defaults'], JSON_THROW_ON_ERROR), 'revision' => 1, 'created_at' => $now, 'updated_at' => $now])->saveData();
        }
        // 旧内置模块构建仅用于开发，此次替换为普通HTML构建。第三方旧包须重新交付，不能静默丢弃定制配置。
        // 迁移前最后一次同步当前官网的定制内容，之后不再维护两套主题配置。
        $legacy = $pdo->prepare('SELECT settings FROM ' . $q('website_theme_settings') . ' WHERE theme_id=?');
        $legacy->execute([$website['active_theme_id']]);
        $update = $pdo->prepare('UPDATE ' . $q('site_theme_configurations') . ' SET settings=? WHERE release_id=? AND component=?');
        $update->execute([$legacy->fetchColumn(), $active, 'website']); $legacy->closeCursor(); $update->closeCursor(); $find->closeCursor();
        if (!$this->table('website_settings')->hasColumn('active_release_id')) {
            $this->table('website_settings')->addColumn('active_release_id', 'biginteger', ['signed' => false, 'null' => true, 'comment' => '当前官网主题版本ID，关联统一版本目录'])->update();
        }
        $pdo->exec('UPDATE ' . $q('website_settings') . ' SET active_release_id=' . (int) $active . ' WHERE id=1');
        $this->table('website_settings')->changeColumn('active_release_id', 'biginteger', ['signed' => false, 'null' => false, 'comment' => '当前官网主题版本ID，关联统一版本目录'])
            ->removeColumn('active_theme_id')->removeColumn('active_theme_version')->removeColumn('active_theme_digest')->update();

        foreach ($obsolete as $release) {
            $pdo->exec('UPDATE ' . $q('console_theme_bindings') . ' SET mode=\'responsive\',responsive=' . (int) $officialId . ',desktop=NULL,mobile=NULL,revision=revision+1 WHERE responsive=' . (int) $release['id'] . ' OR desktop=' . (int) $release['id'] . ' OR mobile=' . (int) $release['id']);
            $pdo->exec('DELETE FROM ' . $q('site_theme_configurations') . ' WHERE release_id=' . (int) $release['id']);
            $pdo->exec('DELETE FROM ' . $q('site_theme_releases') . ' WHERE id=' . (int) $release['id']);
        }
        // 所有版本的素材引用迁入唯一的配置归属，保留引用删除保护。
        $configs = $pdo->query('SELECT release_id,component,settings FROM ' . $q('site_theme_configurations'))->fetchAll(PDO::FETCH_ASSOC);
        $check = $pdo->prepare('SELECT 1 FROM ' . $q('file_references') . ' WHERE file_id=? AND scope=? AND owner=?');
        foreach ($configs as $config) foreach (FileReferences::collect(json_decode($config['settings'], true, flags: JSON_THROW_ON_ERROR)) as $fileId) {
            $owner = $config['release_id'] . ':' . $config['component']; $check->execute([$fileId, 'site_theme', $owner]);
            if (!$check->fetchColumn()) $this->table('file_references')->insert(['file_id' => $fileId, 'scope' => 'site_theme', 'owner' => $owner, 'label' => '主题配置', 'expires_at' => null])->saveData();
        }
        $pdo->exec('DELETE FROM ' . $q('file_references') . ' WHERE scope=\'theme\'');
        $check->closeCursor();
        $this->table('website_theme_settings')->drop()->save();
        $this->table('website_themes')->drop()->save();
    }
    public function down(): void { throw new IrreversibleMigrationException('使用前向迁移恢复主题配置，禁止丢弃定制内容'); }
}
