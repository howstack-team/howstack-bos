<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\system_update\domain\MigrationDatabaseConfiguration;
use app\modules\system_update\infrastructure\{PdoSchemaInspector, PhinxCoreMigrationRunner, SystemUpdatePdoFactory};
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 历史数据升级、自定义前缀、部分DDL中断重放与MySQL中文注释。 */
final class ControlledRefundMigrationTest extends TestCase
{
    use IsolatedBusinessDatabase { setUp as private createDatabase; }

    protected function setUp(): void
    {
        $this->initialMigrationVersion = 20261019000100;
        $this->createDatabase();
    }

    public function testHistoryStaysUnchangedAndInterruptedExpansionCanResume(): void
    {
        $member = $this->member();
        $now = date('Y-m-d H:i:s');
        $id = $this->row('withdrawal_requests', ['withdrawal_no' => 'HISTORICAL-PAID', 'member_id' => $member,
            'source_type' => 'recharge', 'source_id' => 1, 'amount_minor' => 123, 'status' => 'paid',
            'payout_reference' => 'HISTORICAL-BANK-001', 'created_at' => $now, 'updated_at' => $now]);
        $before = (array) Db::table('withdrawal_requests')->where('id', $id)->first();
        // 模拟首个DDL已完成而后续中断，迁移必须跳过已有字段并补齐其余结构。
        $pdo = Db::connection()->getPdo();
        $mysql = Db::connection()->getDriverName() === 'mysql';
        $pdo->exec('ALTER TABLE orm_service_provisioning_tasks ADD not_created_reference VARCHAR(160) NULL' . ($mysql ? " COMMENT '人工核查凭证编号'" : ''));
        $configuration = MigrationDatabaseConfiguration::fromArray(Db::connection()->getConfig());
        $runner = new PhinxCoreMigrationRunner(new SystemUpdatePdoFactory(), new PdoSchemaInspector());
        self::assertSame([20261019000200], $runner->migrate($configuration)->executed);
        self::assertSame([], $runner->migrate($configuration)->executed);
        $after = (array) Db::table('withdrawal_requests')->where('id', $id)->first();
        self::assertSame($before, array_intersect_key($after, $before));
        foreach (['executor_id', 'payout_note', 'receipt_key'] as $column) self::assertNull($after[$column]);
        self::assertSame(0, Db::table('service_provisioning_tasks')->count(), '不能将历史记录自动认定为未创建');
        if ($mysql) {
            foreach ([
                'service_provisioning_tasks' => ['not_created_reference', 'not_created_reason', 'not_created_by', 'not_created_at'],
                'refund_requests' => ['delivery_task_id', 'delivery_evidence', 'executor_id'],
                'withdrawal_requests' => ['executor_id', 'payout_note', 'receipt_key'],
                'finance_operations' => ['client_ip', 'client_ip_location'],
            ] as $table => $fields) {
                $columns = Db::select('SELECT COLUMN_NAME AS name,COLUMN_COMMENT AS comment FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['orm_' . $table]);
                foreach ($fields as $field) {
                    $matched = array_values(array_filter($columns, static fn (object $row): bool => $row->name === $field));
                    self::assertCount(1, $matched);
                    self::assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $matched[0]->comment);
                }
            }
            $index = Db::select('SHOW INDEX FROM orm_withdrawal_requests WHERE Key_name = ?', ['uk_withdrawal_receipt_key']);
            self::assertCount(1, $index);
            self::assertSame(0, (int) $index[0]->Non_unique);
        }
    }
}
