<?php

declare(strict_types=1);

use app\modules\website\infrastructure\{FileThemeRegistry, UpgradeInstalledThemePackage};
use app\modules\file\domain\FileReferences;
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 合并主题选择；旧快照不覆盖，公共设置、主题配置和素材引用保留。 */
final class ConsolidateThemeSelection extends AbstractMigration
{
    public function up(): void
    {
        $adapter = $this->getAdapter(); $prefix = $adapter->getOption('table_prefix') ?? '';
        $q = fn (string $name): string => $adapter->quoteTableName($prefix . $name);
        $pdo = $adapter->getConnection(); $now = date('Y-m-d H:i:s');
        $registry = new FileThemeRegistry(base_path('themes'), runtime_path('website-themes'), public_path('theme-assets'), (require base_path('config/version.php'))['current']);
        $default = $registry->read('default');
        if (!$default['website'] || !isset($default['console']['responsive'])) throw new RuntimeException('default必须包含完整自适应官网与会员中心，请先构建模板');
        $registry->publish($default);
        $register = function (array $package, ?int $actor = null) use ($pdo, $q, $now): int {
            $find = $pdo->prepare('SELECT id,digest FROM ' . $q('site_theme_releases') . ' WHERE theme_id=? AND version=?');
            $find->execute([$package['manifest']['id'], $package['manifest']['version']]); $row = $find->fetch(PDO::FETCH_ASSOC); $find->closeCursor();
            if ($row && $row['digest'] !== $package['digest']) throw new RuntimeException('主题同版本内容冲突，请增加版本号');
            if (!$row) {
            $this->table('site_theme_releases')->insert(['theme_id' => $package['manifest']['id'], 'name' => $package['manifest']['name'], 'version' => $package['manifest']['version'], 'digest' => $package['digest'],
                'components' => json_encode([...($package['website'] ? ['website'] : []), ...array_keys($package['console'])], JSON_THROW_ON_ERROR), 'installed_by' => $actor, 'installed_at' => $now, 'created_at' => $now, 'updated_at' => $now])->saveData();
            $find->execute([$package['manifest']['id'], $package['manifest']['version']]); $id = (int) $find->fetchColumn(); $find->closeCursor();
            } else $id = (int) $row['id'];
            foreach ([...($package['website'] ? ['website' => ['defaults' => $package['defaults']]] : []), ...$package['console']] as $component => $definition) {
                $configuration = $pdo->prepare('SELECT id FROM ' . $q('site_theme_configurations') . ' WHERE release_id=? AND component=?');
                $configuration->execute([$id, $component]); $present = $configuration->fetchColumn(); $configuration->closeCursor();
                if ($present) continue;
                $this->table('site_theme_configurations')->insert(['release_id' => $id, 'component' => $component, 'settings' => json_encode($definition['defaults'], JSON_THROW_ON_ERROR), 'revision' => 1, 'created_at' => $now, 'updated_at' => $now])->saveData();
            }
            return $id;
        };
        $defaultId = $register($default);
        $website = $pdo->query('SELECT * FROM ' . $q('website_settings') . ' WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $console = $this->hasTable('console_theme_bindings') ? $pdo->query('SELECT * FROM ' . $q('console_theme_bindings') . ' WHERE id=1')->fetch(PDO::FETCH_ASSOC) : null;
        $map = []; $oldIds = []; $sourceWebsite = (int) $website['active_release_id'];
        $old = $pdo->query('SELECT * FROM ' . $q('site_theme_releases') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($old as $release) {
            $id = (int) $release['id']; if ($id === $defaultId) { $map[$id] = $id; continue; }
            if ($release['theme_id'] === 'official-console') { $map[$id] = $defaultId; $oldIds[] = $id; continue; }
            $package = $registry->pinned($release['theme_id'], $release['version'], $release['digest']);
            if (is_dir($package['directory'] . '/template') || !isset($package['manifest']['components']) && !isset($package['manifest']['settings_schema'])) { $map[$id] = $id; continue; }
            $numbers = explode('.', $release['version']);
            // 中断重试复用内容相同的转换版本，不重复创建新版本或覆盖配置。
            while (true) {
                $numbers[2] = (int) $numbers[2] + 1; $version = implode('.', $numbers);
                $temporary = runtime_path('theme-upgrade/' . bin2hex(random_bytes(10)));
                try {
                    (new UpgradeInstalledThemePackage())->convert($package, $temporary, $version, $default);
                    $converted = (new FileThemeRegistry($temporary, runtime_path('website-themes'), public_path('theme-assets'), (require base_path('config/version.php'))['current']))->read($release['theme_id']);
                    $check = $pdo->prepare('SELECT digest FROM ' . $q('site_theme_releases') . ' WHERE theme_id=? AND version=?');
                    $check->execute([$release['theme_id'], $version]); $existingDigest = $check->fetchColumn(); $check->closeCursor();
                    if ($existingDigest !== false && $existingDigest !== $converted['digest']) continue;
                    $registry->publish($converted); $map[$id] = $register($converted, $release['installed_by'] === null ? null : (int) $release['installed_by']); $oldIds[] = $id;
                    break;
                } finally {
                    if (is_dir($temporary)) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); rmdir($temporary); }
                }
            }
        }
        // 旧系统允许两套选择；合并时以官网选择为准，官网为内置且会员为第三方时优先第三方会员主题。
        $selected = $map[$sourceWebsite] ?? $defaultId;
        $previous = array_column($old, null, 'id');
        if (($previous[$sourceWebsite]['theme_id'] ?? '') === 'default') {
            $selected = $defaultId;
            foreach (['responsive', 'desktop', 'mobile'] as $slot) {
                $candidate = (int) ($console[$slot] ?? 0);
                if ($candidate && !in_array($previous[$candidate]['theme_id'] ?? '', ['default', 'official-console'], true)) { $selected = $map[$candidate]; break; }
            }
        }
        // 复制各版本已保存配置，不回写旧快照，也不丢失配置修订。
        foreach ($map as $oldId => $newId) {
            if ($oldId === $newId) continue;
            foreach ($pdo->query('SELECT * FROM ' . $q('site_theme_configurations') . ' WHERE release_id=' . $oldId)->fetchAll(PDO::FETCH_ASSOC) as $config) {
                $component = $config['component'] === 'desktop' ? 'pc' : $config['component'];
                if ($newId === $defaultId && $component === 'website' && $oldId !== $sourceWebsite) continue;
                if ($newId === $defaultId && $component !== 'website' && !in_array($oldId, array_filter([$console['responsive'] ?? null, $console['desktop'] ?? null, $console['mobile'] ?? null]))) continue;
                $update = $pdo->prepare('UPDATE ' . $q('site_theme_configurations') . ' SET settings=?,revision=?,updated_at=? WHERE release_id=? AND component=?');
                $update->execute([$config['settings'], $config['revision'], $now, $newId, $component]); $update->closeCursor();
            }
        }
        // 升级默认官网时，当前定制内容同时进入新的默认回退版本，转换版本也保留自己的配置。
        if (($previous[$sourceWebsite]['theme_id'] ?? '') === 'default' && $sourceWebsite !== $defaultId) {
            $source = $pdo->query('SELECT settings,revision FROM ' . $q('site_theme_configurations') . " WHERE release_id=" . $sourceWebsite . " AND component='website'")->fetch(PDO::FETCH_ASSOC);
            if ($source) {
                $update = $pdo->prepare('UPDATE ' . $q('site_theme_configurations') . " SET settings=?,revision=?,updated_at=? WHERE release_id=? AND component='website'");
                $update->execute([$source['settings'], $source['revision'], $now, $defaultId]); $update->closeCursor();
            }
        }
        if (!$this->hasTable('site_theme_bindings')) {
            $this->table('site_theme_bindings', ['id' => false, 'primary_key' => ['id'], 'comment' => '官网及会员中心统一主题选择'])
                ->addColumn('id', 'biginteger', ['signed' => false, 'null' => false, 'comment' => '单例主键，固定为1'])
                ->addColumn('release_id', 'biginteger', ['signed' => false, 'null' => false, 'comment' => '当前整套主题版本ID，关联site_theme_releases'])
                ->addColumn('default_release_id', 'biginteger', ['signed' => false, 'null' => false, 'comment' => 'default完整主题版本ID，缺少整个组件时使用'])
                ->addColumn('revision', 'biginteger', ['signed' => false, 'default' => 1, 'null' => false, 'comment' => '统一选择修订号，每次切换递增'])
                ->addColumn('created_at', 'datetime', ['null' => false, 'comment' => '统一选择创建时间'])
                ->addColumn('updated_at', 'datetime', ['null' => false, 'comment' => '统一选择最后更新时间'])->create();
        }
        if (!$pdo->query('SELECT id FROM ' . $q('site_theme_bindings') . ' WHERE id=1')->fetchColumn()) $this->table('site_theme_bindings')->insert(['id' => 1, 'release_id' => $selected, 'default_release_id' => $defaultId, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now])->saveData();
        $selected = (int) $pdo->query('SELECT release_id FROM ' . $q('site_theme_bindings') . ' WHERE id=1')->fetchColumn();
        $types = $pdo->query('SELECT components FROM ' . $q('site_theme_releases') . ' WHERE id=' . $selected)->fetchColumn();
        $effective = in_array('website', json_decode($types, true), true) ? $selected : $defaultId;
        $pdo->exec('UPDATE ' . $q('website_settings') . ' SET active_release_id=' . $effective . ',revision=revision+1 WHERE id=1 AND active_release_id<>' . $effective);
        foreach ($oldIds as $id) { $pdo->exec('DELETE FROM ' . $q('site_theme_configurations') . ' WHERE release_id=' . $id); $pdo->exec('DELETE FROM ' . $q('site_theme_releases') . ' WHERE id=' . $id); $pdo->exec("DELETE FROM " . $q('file_references') . " WHERE scope='site_theme' AND owner LIKE '" . $id . ":%'"); }
        $check = $pdo->prepare('SELECT 1 FROM ' . $q('file_references') . ' WHERE file_id=? AND scope=? AND owner=?');
        foreach ($pdo->query('SELECT release_id,component,settings FROM ' . $q('site_theme_configurations'))->fetchAll(PDO::FETCH_ASSOC) as $config) foreach (FileReferences::collect(json_decode($config['settings'], true)) as $fileId) {
            $owner = $config['release_id'] . ':' . $config['component']; $check->execute([$fileId, 'site_theme', $owner]);
            if (!$check->fetchColumn()) $this->table('file_references')->insert(['file_id' => $fileId, 'scope' => 'site_theme', 'owner' => $owner, 'label' => '主题配置', 'expires_at' => null])->saveData();
            $check->closeCursor();
        }
        if ($this->hasTable('console_theme_bindings')) $this->table('console_theme_bindings')->drop()->save();
    }
    public function down(): void { throw new IrreversibleMigrationException('统一主题配置使用前向迁移修复，旧快照保留用于恢复'); }
}
