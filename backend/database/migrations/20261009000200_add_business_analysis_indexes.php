<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** 经营分析时间窗口查询索引；复用交易事实，不新建重复统计账本。 */
final class AddBusinessAnalysisIndexes extends AbstractMigration
{
    private const array INDEXES = [
        'orders' => ['idx_analysis_order_paid' => ['currency', 'paid_at']],
        'refund_requests' => ['idx_analysis_refund_completed' => ['currency', 'source_type', 'status', 'completed_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $indexes) {
            foreach ($indexes as $index => $columns) {
                $table = $this->table($name);
                if (!$table->hasIndexByName($index)) {
                    $table->addIndex($columns, ['name' => $index])->update();
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $name => $indexes) {
            foreach (array_keys($indexes) as $index) {
                $table = $this->table($name);
                if ($table->hasIndexByName($index)) {
                    $table->removeIndexByName($index)->update();
                }
            }
        }
    }
}
