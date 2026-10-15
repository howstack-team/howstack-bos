<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 每个主题只保留当前安装记录；优先保留正在使用的配置，旧资源快照不删除。 */
final class KeepOneInstalledTheme extends AbstractMigration
{
    public function up(): void
    {
        $adapter = $this->getAdapter(); $prefix = $adapter->getOption('table_prefix') ?? '';
        $q = fn (string $name): string => $adapter->quoteTableName($prefix . $name);
        $pdo = $adapter->getConnection();
        $binding = $pdo->query('SELECT * FROM ' . $q('site_theme_bindings') . ' WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $website = $pdo->query('SELECT active_release_id FROM ' . $q('website_settings') . ' WHERE id=1')->fetchColumn();
        $rows = $pdo->query('SELECT id,theme_id FROM ' . $q('site_theme_releases') . ' ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        $groups = [];
        foreach ($rows as $row) $groups[$row['theme_id']][] = (int) $row['id'];
        $ownsTransaction = !$pdo->inTransaction(); if ($ownsTransaction) $pdo->beginTransaction();
        try {
            foreach ($groups as $ids) {
                $keep = $ids[0];
                foreach ([(int) $binding['release_id'], (int) $binding['default_release_id'], (int) $website] as $active) if (in_array($active, $ids, true)) { $keep = $active; break; }
                foreach ($ids as $id) if ($id !== $keep) {
                    foreach (['release_id', 'default_release_id'] as $column) $pdo->exec('UPDATE ' . $q('site_theme_bindings') . ' SET ' . $column . '=' . $keep . ',revision=revision+1 WHERE ' . $column . '=' . $id);
                    $pdo->exec('UPDATE ' . $q('website_settings') . ' SET active_release_id=' . $keep . ',revision=revision+1 WHERE active_release_id=' . $id);
                    $pdo->exec('DELETE FROM ' . $q('site_theme_configurations') . ' WHERE release_id=' . $id);
                    $pdo->exec('DELETE FROM ' . $q('site_theme_releases') . ' WHERE id=' . $id);
                    $pdo->exec("DELETE FROM " . $q('file_references') . " WHERE scope='site_theme' AND owner LIKE '" . $id . ":%'");
                }
            }
            if ($ownsTransaction) $pdo->commit();
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $error;
        }
        $table = $this->table('site_theme_releases');
        if (!$table->hasIndex(['theme_id'])) $table->addIndex(['theme_id'], ['unique' => true, 'name' => 'idx_site_theme_identity'])->update();
        if ($table->hasIndex(['theme_id', 'version'])) $table->removeIndex(['theme_id', 'version'])->update();
        if ($adapter->getAdapterType() === 'mysql') {
            $pdo->exec('ALTER TABLE ' . $q('site_theme_releases') . " COMMENT='已安装主题，每个主题标识仅保留当前包资料'");
            $pdo->exec('ALTER TABLE ' . $q('site_theme_configurations') . " COMMENT='已安装主题各组件的当前展示配置'");
        }
    }
    public function down(): void { throw new IrreversibleMigrationException('主题历史记录已合并，恢复须使用升级前备份；资源快照仍保留'); }
}
