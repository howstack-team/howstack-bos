<?php

declare(strict_types=1);

use app\modules\file\domain\FileReferences;
use app\modules\website\infrastructure\{FileThemeRegistry, UpgradeConsoleDeviceLayout};
use app\modules\website\validation\{ThemeSchema, ThemeConfigurationMerger};
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** PC必备、移动端可选；保留主题ID、配置与旧资源，禁止新安装responsive类型。 */
final class MakeMobileThemeOptional extends AbstractMigration
{
    public function up(): void
    {
        $adapter = $this->getAdapter(); $prefix = $adapter->getOption('table_prefix') ?? '';
        $q = fn (string $name): string => $adapter->quoteTableName($prefix . $name);
        $pdo = $adapter->getConnection(); $now = date('Y-m-d H:i:s');
        if (!$this->table('site_theme_bindings')->hasColumn('mobile_enabled')) {
            $this->table('site_theme_bindings')->addColumn('mobile_enabled', 'boolean', ['null' => false, 'default' => false, 'comment' => '是否启用独立移动端模板，只有实际会员主题提供mobile时可开启'])->update();
        }
        $registry = new FileThemeRegistry(base_path('themes'), runtime_path('website-themes'), public_path('theme-assets'), (require base_path('config/version.php'))['current']);
        $binding = $pdo->query('SELECT * FROM ' . $q('site_theme_bindings') . ' WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $selectedConsoleId = (int) $binding['default_release_id'];
        $rows = $pdo->query('SELECT * FROM ' . $q('site_theme_releases') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) if ((int) $row['id'] === (int) $binding['release_id'] && array_intersect(json_decode($row['components'], true), ['pc', 'mobile', 'responsive']) !== []) $selectedConsoleId = (int) $row['id'];
        $enableMobile = false;
        foreach ($rows as $row) {
            $types = json_decode($row['components'], true, flags: JSON_THROW_ON_ERROR);
            // 原来已有独立移动模板的主题继续使用它，原自适应主题转PC后不开启。
            if ((int) $row['id'] === $selectedConsoleId) $enableMobile = in_array('mobile', $types, true);
            if (!in_array('responsive', $types, true)) continue;
            $package = $registry->pinned($row['theme_id'], $row['version'], $row['digest']);
            $temporary = runtime_path('theme-device-upgrade/' . bin2hex(random_bytes(10)));
            try {
                (new UpgradeConsoleDeviceLayout())->convert($package, $temporary);
                $converted = (new FileThemeRegistry($temporary, runtime_path('website-themes'), public_path('theme-assets'), (require base_path('config/version.php'))['current']))->read($row['theme_id']);
                $query = $pdo->prepare('SELECT * FROM ' . $q('site_theme_configurations') . ' WHERE release_id=?');
                $query->execute([$row['id']]); $old = array_column($query->fetchAll(PDO::FETCH_ASSOC), null, 'component'); $query->closeCursor();
                $configurations = [];
                foreach ([...($converted['website'] ? ['website' => ['schema' => $converted['schema'], 'defaults' => $converted['defaults']]] : []), ...$converted['console']] as $type => $definition) {
                    $source = $old[$type] ?? ($type === 'pc' ? $old['responsive'] ?? null : null);
                    $settings = $source ? (new ThemeConfigurationMerger())->merge($definition['schema'], $definition['defaults'], json_decode($source['settings'], true, flags: JSON_THROW_ON_ERROR)) : $definition['defaults'];
                    (new ThemeSchema())->validate($settings, $definition['schema']);
                    $configurations[$type] = ['settings' => $settings, 'revision' => (int) ($source['revision'] ?? 1)];
                }
                $registry->publish($converted);
                $update = $pdo->prepare('UPDATE ' . $q('site_theme_releases') . ' SET digest=?,components=?,updated_at=? WHERE id=?');
                $update->execute([$converted['digest'], json_encode(array_keys($configurations), JSON_THROW_ON_ERROR), $now, $row['id']]);
                $delete = $pdo->prepare('DELETE FROM ' . $q('site_theme_configurations') . ' WHERE release_id=?'); $delete->execute([$row['id']]);
                $deleteRefs = $pdo->prepare('DELETE FROM ' . $q('file_references') . " WHERE scope='site_theme' AND owner LIKE ?"); $deleteRefs->execute([$row['id'] . ':%']);
                foreach ($configurations as $type => $configuration) {
                    $this->table('site_theme_configurations')->insert(['release_id' => $row['id'], 'component' => $type, 'settings' => json_encode($configuration['settings'], JSON_THROW_ON_ERROR), 'revision' => $configuration['revision'], 'created_at' => $now, 'updated_at' => $now])->saveData();
                    foreach (FileReferences::collect($configuration['settings']) as $fileId) $this->table('file_references')->insert(['file_id' => $fileId, 'scope' => 'site_theme', 'owner' => $row['id'] . ':' . $type, 'label' => '主题配置：' . $row['name'], 'expires_at' => null])->saveData();
                }
            } finally {
                if (is_dir($temporary)) {
                    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                    rmdir($temporary);
                }
            }
        }
        $pdo->exec('UPDATE ' . $q('site_theme_bindings') . ' SET mobile_enabled=' . ($enableMobile ? 1 : 0) . ',revision=revision+1 WHERE id=1');
        $this->table('site_theme_releases')->changeColumn('components', 'json', ['null' => false, 'comment' => '主题提供的组件：website官网、pc会员PC模板、mobile可选移动端模板'])->update();
        $this->table('site_theme_configurations')->changeColumn('component', 'string', ['limit' => 16, 'null' => false, 'comment' => '当前展示配置类型：website官网、pc会员PC模板、mobile会员移动端模板'])->update();
    }

    public function down(): void { throw new IrreversibleMigrationException('设备类型通过前向迁移修复，旧快照保留，数据库恢复使用迁移前备份'); }
}
