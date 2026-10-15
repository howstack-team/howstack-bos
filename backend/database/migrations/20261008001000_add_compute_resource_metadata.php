<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 新增选填展示信息，历史数据保持空值，重试时保留已配置值。 */
final class AddComputeResourceMetadata extends AbstractMigration
{
    public function up(): void
    {
        $regions = $this->table('compute_regions');
        if (!$regions->hasColumn('country_code')) {
            $regions->addColumn('country_code', 'string', [
                'limit' => 2, 'default' => '', 'null' => false,
                'comment' => '地域所属国家的两位大写字母代码；空字符串表示未配置',
            ])->update();
        }
        $lines = $this->table('compute_lines');
        if (!$lines->hasColumn('test_ip')) {
            $lines->addColumn('test_ip', 'string', [
                'limit' => 45, 'default' => '', 'null' => false,
                'comment' => '线路连通性测试地址，支持IPv4或IPv6；空字符串表示未配置',
            ])->update();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('请使用前向迁移调整展示字段，避免丢失已配置的国家代码和测试IP。');
    }
}
