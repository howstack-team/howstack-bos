<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** 注册与登录配置共用分类，保留既有分类标识和客户自定义名称。 */
final class RenameMemberAccessSettings extends AbstractMigration
{
    public function up(): void
    {
        $this->renameCategory('会员注册', '会员注册与登录');
    }

    public function down(): void
    {
        $this->renameCategory('会员注册与登录', '会员注册');
    }

    private function renameCategory(string $previousName, string $name): void
    {
        $table = $this->getAdapter()->quoteTableName(
            $this->getAdapter()->getOption('table_prefix') . 'system_setting_categories',
        );
        $this->execute(
            "UPDATE {$table} SET name = ?, updated_at = ? WHERE code = ? AND name = ?",
            [$name, date('Y-m-d H:i:s'), 'registration', $previousName],
        );
    }
}
