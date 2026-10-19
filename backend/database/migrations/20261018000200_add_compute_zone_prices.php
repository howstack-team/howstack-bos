<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 默认价格不变，按可用区覆盖值随销售版本封存。 */
final class AddComputeZonePrices extends AbstractMigration
{
    public function up(): void
    {
        foreach (['compute_flavors', 'compute_disk_types'] as $name) {
            $table = $this->table($name);
            if (!$table->hasColumn('zone_prices')) {
                $table->addColumn('zone_prices', 'json', [
                    'null' => true,
                    'comment' => '按关联可用区ID及周期保存的独立单价JSON，金额整数分；存储为每GiB单价；NULL或缺项继承默认价，0表示免费',
                ])->update();
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('可用区价格只能通过前向迁移修复，禁止丢弃已配置价格');
    }
}
