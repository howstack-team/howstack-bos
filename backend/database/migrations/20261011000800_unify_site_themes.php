<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;
use app\modules\website\infrastructure\FileThemeRegistry;

/** 统一主题版本目录，迁入既有官网配置，不改变当前官网启用指针。 */
final class UnifySiteThemes extends AbstractMigration
{
    public function up(): void
    {
        foreach (['site_theme_releases' => '统一主题不可变版本目录', 'site_theme_configurations' => '主题各版本各组件展示配置', 'console_theme_bindings' => '会员中心展示类型及入口绑定'] as $name => $comment) {
            if ($this->hasTable($name)) {
                continue;
            }
            $table = $this->table($name, ['id' => false, 'primary_key' => ['id'], 'comment' => $comment]);
            $identityType = $this->getAdapter()->getConnection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'integer' : 'biginteger';
            $table->addColumn('id', $identityType, ['identity' => true, 'signed' => false, 'null' => false, 'comment' => '内部自增主键']);
            if ($name === 'site_theme_releases') {
                foreach (['theme_id' => [64, '主题稳定标识'], 'name' => [80, '主题展示名称'], 'version' => [32, '主题语义版本'], 'digest' => [64, '完整主题包SHA256摘要']] as $field => [$limit, $label]) {
                    $table->addColumn($field, 'string', ['limit' => $limit, 'null' => false, 'comment' => $label]);
                }
                $table->addColumn('components', 'json', ['null' => false, 'comment' => '支持的组件：website官网responsive自适应desktop桌面mobile移动'])
                    ->addColumn('installed_by', 'biginteger', ['signed' => false, 'null' => true, 'comment' => '安装管理员ID，系统内置为空'])
                    ->addColumn('installed_at', 'datetime', ['null' => false, 'comment' => '主题安装时间'])
                    ->addIndex(['theme_id', 'version'], ['unique' => true, 'name' => 'uniq_site_theme_version']);
            } elseif ($name === 'site_theme_configurations') {
                $table->addColumn('release_id', 'biginteger', ['signed' => false, 'null' => false, 'comment' => '统一主题版本ID'])
                    ->addColumn('component', 'string', ['limit' => 16, 'null' => false, 'comment' => '配置所属组件及展示类型'])
                    ->addColumn('settings', 'json', ['null' => false, 'comment' => '经组件Schema验证的公开展示配置'])
                    ->addColumn('revision', 'integer', ['default' => 1, 'null' => false, 'comment' => '组件配置修订号，防止并发覆盖'])
                    ->addIndex(['release_id', 'component'], ['unique' => true, 'name' => 'uniq_site_theme_configuration']);
            } else {
                $table->addColumn('mode', 'string', ['limit' => 16, 'default' => 'responsive', 'null' => false, 'comment' => '展示模式：responsive自适应split分别配置']);
                foreach (['responsive' => '自适应模板，作为默认及回退入口', 'desktop' => 'PC专用模板', 'mobile' => '移动端专用模板'] as $field => $label) {
                    $table->addColumn($field, 'biginteger', ['signed' => false, 'null' => true, 'comment' => $label . '的主题版本ID']);
                }
                $table->addColumn('revision', 'integer', ['default' => 1, 'null' => false, 'comment' => '入口绑定修订号，防止并发覆盖']);
            }
            $table->addColumn('created_at', 'datetime', ['null' => false, 'comment' => '记录创建时间'])
                ->addColumn('updated_at', 'datetime', ['null' => false, 'comment' => '最后修改时间'])->create();
        }
        $adapter = $this->getAdapter();
        $prefix = $adapter->getOption('table_prefix') ?? '';
        $q = fn (string $name): string => $adapter->quoteTableName($prefix . $name);
        $pdo = $adapter->getConnection();
        $versionConfiguration = require dirname(__DIR__, 2) . '/config/version.php';
        $registry = new FileThemeRegistry(dirname(__DIR__, 2) . '/themes', runtime_path('website-themes'), public_path('theme-assets'), (string) $versionConfiguration['current']);
        $existing = $pdo->query('SELECT * FROM ' . $q('website_themes'))->fetchAll(PDO::FETCH_ASSOC);
        $website = $pdo->query('SELECT * FROM ' . $q('website_settings') . ' WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $existing[] = ['theme_id' => $website['active_theme_id'], 'version' => $website['active_theme_version'], 'digest' => $website['active_theme_digest'], 'installed_by' => null];
        $packages = [];
        foreach ($existing as $row) {
            $package = $registry->pinned($row['theme_id'], $row['version'], $row['digest']);
            $packages[$row['theme_id'] . ':' . $row['version']] = [$package, $row['installed_by']];
        }
        $official = $registry->read('official-console');
        $registry->publish($official);
        $packages['official-console:1.0.0'] = [$official, null];
        $now = date('Y-m-d H:i:s');
        foreach ($packages as [$package, $actor]) {
            $id = $package['manifest']['id'];
            $version = $package['manifest']['version'];
            $find = $pdo->prepare('SELECT id FROM ' . $q('site_theme_releases') . ' WHERE theme_id=? AND version=?');
            $find->execute([$id, $version]);
            if ($find->fetchColumn()) {
                continue;
            }
            $this->table('site_theme_releases')->insert(['theme_id' => $id, 'name' => $package['manifest']['name'], 'version' => $version,
                'digest' => $package['digest'], 'components' => json_encode(array_merge($package['website'] ? ['website'] : [], array_keys($package['console'])), JSON_THROW_ON_ERROR),
                'installed_by' => $actor, 'installed_at' => $now, 'created_at' => $now, 'updated_at' => $now])->saveData();
            $find->execute([$id, $version]);
            $releaseId = (int) $find->fetchColumn();
            $components = $package['console'];
            if ($package['website']) {
                $lookup = $pdo->prepare('SELECT settings,revision FROM ' . $q('website_theme_settings') . ' WHERE theme_id=?');
                $lookup->execute([$id]);
                $saved = $lookup->fetch(PDO::FETCH_ASSOC);
                $components['website'] = ['defaults' => $saved ? json_decode($saved['settings'], true, 32, JSON_THROW_ON_ERROR) : $package['defaults']];
            }
            foreach ($components as $component => $definition) {
                $this->table('site_theme_configurations')->insert(['release_id' => $releaseId, 'component' => $component,
                    'settings' => json_encode($definition['defaults'], JSON_THROW_ON_ERROR), 'revision' => 1, 'created_at' => $now, 'updated_at' => $now])->saveData();
            }
            if ($id === 'official-console' && !$this->fetchRow('SELECT id FROM ' . $q('console_theme_bindings') . ' WHERE id=1')) {
                $this->table('console_theme_bindings')->insert(['id' => 1, 'mode' => 'responsive', 'responsive' => $releaseId, 'desktop' => null, 'mobile' => null,
                    'revision' => 1, 'created_at' => $now, 'updated_at' => $now])->saveData();
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('主题版本和入口绑定包含线上配置，请使用前向迁移。');
    }
}
