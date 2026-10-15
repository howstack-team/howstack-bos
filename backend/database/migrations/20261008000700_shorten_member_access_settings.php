<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ShortenMemberAccessSettings extends AbstractMigration
{
    public function up(): void
    {
        $this->renameCategory('会员注册与登录', '注册与登录');
    }

    public function down(): void
    {
        $this->renameCategory('注册与登录', '会员注册与登录');
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
