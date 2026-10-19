<?php

declare(strict_types=1);

use Phinx\Migration\{AbstractMigration, IrreversibleMigrationException};

/** 保留历史单据，只扩展社区退款的核查、审核与执行证据。 */
final class ControlCommunityRefunds extends AbstractMigration
{
    /** @return void 可中断重放的前向扩展，不自动认定历史交付结果 */
    public function up(): void
    {
        $definitions = [
            'service_provisioning_tasks' => [
                'not_created_reference' => ['string', ['limit' => 160, 'null' => true, 'comment' => '人工核实未创建的供应商工单或核查凭证编号；历史未知结果不回填']],
                'not_created_reason' => ['string', ['limit' => 500, 'null' => true, 'comment' => '人工核实未创建的结论说明，不保存账户及供应秘密']],
                'not_created_by' => ['integer', ['signed' => false, 'null' => true, 'comment' => '核实未创建的管理员内部ID；供应明确失败或历史为空']],
                'not_created_at' => ['datetime', ['null' => true, 'comment' => '人工核实未创建的时间；历史为空']],
            ],
            'refund_requests' => [
                'delivery_task_id' => ['integer', ['signed' => false, 'null' => true, 'comment' => '退款申请时核验的首次开通任务ID；历史未核验为NULL']],
                'delivery_evidence' => ['string', ['limit' => 500, 'null' => true, 'comment' => '申请时未交付证据摘要：供应明确失败或人工核查凭证；不含秘密']],
                'executor_id' => ['integer', ['signed' => false, 'null' => true, 'comment' => '执行余额退款的管理员内部ID；尚未执行或历史为NULL']],
            ],
            'withdrawal_requests' => [
                'executor_id' => ['integer', ['signed' => false, 'null' => true, 'comment' => '登记人工退还打款的管理员内部ID；未执行或历史为NULL']],
                'payout_note' => ['string', ['limit' => 500, 'null' => true, 'comment' => '实际打款渠道、时间及核对说明；不保存收款账号等敏感资料']],
                'receipt_key' => ['string', ['limit' => 64, 'null' => true, 'comment' => '规范化真实打款凭证编号SHA256，用于避免不同退还单重复登记；历史为空']],
            ],
            'finance_operations' => [
                'client_ip' => ['string', ['limit' => 45, 'null' => true, 'comment' => '财务操作时统一解析的客户端IPv4或IPv6；历史及系统调用为NULL']],
                'client_ip_location' => ['string', ['limit' => 255, 'null' => true, 'comment' => '财务操作时IP位置快照；历史及系统调用为NULL']],
            ],
        ];
        foreach ($definitions as $name => $columns) {
            foreach ($columns as $column => [$type, $options]) {
                $table = $this->table($name);
                if (!$table->hasColumn($column)) {
                    $table->addColumn($column, $type, $options)->update();
                }
            }
        }
        $table = $this->table('withdrawal_requests');
        if (!$table->hasIndexByName('uk_withdrawal_receipt_key')) {
            $table->addIndex(['receipt_key'], ['unique' => true, 'name' => 'uk_withdrawal_receipt_key'])->update();
        }
    }

    /** @return void 资金处置及核查证据不能回滚删除 */
    public function down(): void
    {
        throw new IrreversibleMigrationException('资金处置证据仅允许前向修复');
    }
}
