<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class AddFinanceWorkflows extends AbstractMigration
{
    public function up(): void
    {
        foreach (['recharges', 'refund_requests', 'invoice_records', 'withdrawal_requests'] as $name) {
            $table = $this->table($name);
            foreach ([
                'operator_id' => ['integer', ['signed' => false, 'null' => true, 'comment' => '创建单据的后台管理员标识；历史记录为空']],
                'remark' => ['string', ['limit' => 255, 'default' => '', 'comment' => '创建时的业务备注，不含密钥及收款账户']],
                'review_reason' => ['string', ['limit' => 255, 'default' => '', 'comment' => '审核意见；驳回时必须填写']],
            ] as $column => [$type, $options]) {
                if (!$table->hasColumn($column)) {
                    $table->addColumn($column, $type, $options);
                }
            }
            $table->update();
        }
        foreach ([
            'recharges' => ['external_reference' => ['string', ['limit' => 80, 'null' => true, 'comment' => '线下收款凭证编号；不表示支付插件自动确认']]],
            'invoice_records' => [
                'red_number' => ['string', ['limit' => 80, 'null' => true, 'comment' => '实际红字发票号码；人工登记外部已完成的红冲']],
                'red_at' => ['datetime', ['null' => true, 'comment' => '红冲结果登记时间']],
            ],
        ] as $name => $columns) {
            $table = $this->table($name);
            foreach ($columns as $column => [$type, $options]) {
                if (!$table->hasColumn($column)) {
                    $table->addColumn($column, $type, $options);
                }
            }
            $table->update();
        }
        if (!$this->hasTable('finance_operations')) {
            $this->table('finance_operations', ['engine' => 'InnoDB', 'comment' => '财务写操作幂等与不可变审计记录'])
                ->addColumn('request_key', 'string', ['limit' => 128, 'comment' => '资源、管理员及客户端随机请求标识组成的幂等键'])
                ->addColumn('request_hash', 'string', ['limit' => 64, 'comment' => '规范化请求 SHA256 摘要；同键不同内容拒绝'])
                ->addColumn('actor_id', 'integer', ['signed' => false, 'comment' => '执行本次资金操作的管理员标识'])
                ->addColumn('resource', 'string', ['limit' => 24, 'comment' => '单据资源：recharges/refunds/invoices/withdrawals'])
                ->addColumn('action', 'string', ['limit' => 24, 'comment' => '操作：create/approve/reject/execute/issue/red/payout'])
                ->addColumn('result_json', 'text', ['null' => true, 'comment' => '提交后返回的单据标识；与业务事务一同提交'])
                ->addColumn('created_at', 'datetime', ['comment' => '操作创建时间'])
                ->addIndex(['request_key'], ['unique' => true, 'name' => 'uk_finance_operation_request'])
                ->create();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('财务审计与交易记录不可删除');
    }
}
