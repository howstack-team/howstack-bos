<?php

declare(strict_types=1);

use app\modules\file\domain\FileReferences;
use app\modules\website\infrastructure\FileThemeRegistry;
use app\modules\website\validation\{ThemeConfigurationMerger, ThemeSchema};
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 将内置主题更新到含独立移动会员模板的发行包，保留客户配置及设备选择。 */
final class AddOfficialMobileConsole extends AbstractMigration
{
    /**
     * 发布内置资源后在同一事务中更新登记及配置，不修改历史包或第三方主题。
     * @return void
     * @throws Throwable 资源发布、配置校验或数据库写入失败
     */
    public function up(): void
    {
        $adapter = $this->getAdapter();
        $prefix = $adapter->getOption('table_prefix') ?? '';
        $quote = fn (string $table): string => $adapter->quoteTableName($prefix . $table);
        $pdo = $adapter->getConnection();
        $registry = new FileThemeRegistry(
            base_path('themes'),
            runtime_path('website-themes'),
            public_path('theme-assets'),
            (require base_path('config/version.php'))['current'],
        );
        $package = $registry->read('default');
        if (!isset($package['console']['mobile'])) {
            throw new RuntimeException('发行包缺少默认主题移动端构建，请先执行会员主题构建');
        }
        $definitions = ['website' => ['schema' => $package['schema'], 'defaults' => $package['defaults']], ...$package['console']];
        // 文件先原子发布；数据库失败仍引用原快照，允许安全重试。
        $registry->publish($package);
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $pdo->query('SELECT id FROM ' . $quote('site_theme_bindings') . ' WHERE id=1' . $lock)->fetchColumn();
            $row = $pdo->query('SELECT * FROM ' . $quote('site_theme_releases') . " WHERE theme_id='default'" . $lock)->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new RuntimeException('默认主题尚未初始化，停止更新');
            }
            $select = $pdo->prepare('SELECT * FROM ' . $quote('site_theme_configurations') . ' WHERE release_id=?');
            $select->execute([$row['id']]);
            $existing = array_column($select->fetchAll(PDO::FETCH_ASSOC), null, 'component');
            foreach ($existing as $type => $configuration) {
                if (!isset($definitions[$type])) {
                    throw new RuntimeException('内置主题存在未支持的配置组件，请先处理后更新');
                }
            }
            $now = date('Y-m-d H:i:s');
            $insert = $pdo->prepare('INSERT INTO ' . $quote('site_theme_configurations')
                . ' (release_id,component,settings,revision,created_at,updated_at) VALUES (?,?,?,?,?,?)');
            $update = $pdo->prepare('UPDATE ' . $quote('site_theme_configurations') . ' SET settings=?,revision=revision+1,updated_at=? WHERE id=?');
            $references = $pdo->prepare('DELETE FROM ' . $quote('file_references') . " WHERE scope='site_theme' AND owner=?");
            $reference = $pdo->prepare('INSERT INTO ' . $quote('file_references') . ' (file_id,scope,owner,label,expires_at) VALUES (?,?,?,?,NULL)');
            foreach ($definitions as $type => $definition) {
                $stored = $existing[$type] ?? null;
                $settings = $stored === null ? $definition['defaults'] : (new ThemeConfigurationMerger())->merge(
                    $definition['schema'], $definition['defaults'], json_decode($stored['settings'], true, flags: JSON_THROW_ON_ERROR),
                );
                (new ThemeSchema())->validate($settings, $definition['schema']);
                $json = json_encode($settings, JSON_THROW_ON_ERROR);
                if ($stored === null) {
                    $insert->execute([$row['id'], $type, $json, 1, $now, $now]);
                } elseif ($settings !== json_decode($stored['settings'], true, flags: JSON_THROW_ON_ERROR)) {
                    $update->execute([$json, $now, $stored['id']]);
                }
                $owner = $row['id'] . ':' . $type;
                $references->execute([$owner]);
                foreach (FileReferences::collect($settings) as $fileId) {
                    $reference->execute([$fileId, 'site_theme', $owner, '主题配置：' . $package['manifest']['name']]);
                }
            }
            $updateTheme = $pdo->prepare('UPDATE ' . $quote('site_theme_releases') . ' SET name=?,version=?,digest=?,components=?,updated_at=? WHERE id=?');
            $updateTheme->execute([$package['manifest']['name'], $package['manifest']['version'], $package['digest'], json_encode(array_keys($definitions), JSON_THROW_ON_ERROR), $now, $row['id']]);
            // 使正在编辑的绑定感知包能力变化；不切换选中主题、不自动开启移动模板。
            $updateBinding = $pdo->prepare('UPDATE ' . $quote('site_theme_bindings') . ' SET revision=revision+1,updated_at=? WHERE id=1 AND (release_id=? OR default_release_id=?)');
            $updateBinding->execute([$now, $row['id'], $row['id']]);
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

    /**
     * 已发布资源通过下一条前向迁移恢复，不删除可能仍在浏览器使用的文件。
     * @return void
     * @throws IrreversibleMigrationException 禁止丢弃客户配置
     */
    public function down(): void
    {
        throw new IrreversibleMigrationException('默认主题更新保留历史资源，请使用前向迁移或迁移前备份恢复');
    }
}
