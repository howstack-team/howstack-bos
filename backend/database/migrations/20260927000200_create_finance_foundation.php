<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** 财务单据与不可变资金流水的前向基础结构。 */
final class CreateFinanceFoundation extends AbstractMigration
{
    public function up(): void
    {
        $options = ['id' => false, 'primary_key' => ['id'], 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'];

        if (!$this->hasTable('order_item_units')) {
            $this->table('order_item_units', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('order_item_id', 'integer', ['signed' => false])
                ->addColumn('unit_index', 'integer', ['signed' => false])
                ->addColumn('service_id', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('allocated_minor', 'biginteger', ['signed' => false])
                ->addColumn('refunded_minor', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('reserved_refund_minor', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('created_at', 'datetime')
                ->addIndex(['order_item_id', 'unit_index'], ['unique' => true, 'name' => 'uk_order_unit_index'])
                ->addIndex(['service_id'], ['unique' => true, 'name' => 'uk_order_unit_service'])
                ->create();
        }

        if (!$this->hasTable('wallet_accounts')) {
            $this->table('wallet_accounts', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('member_id', 'integer', ['signed' => false])
                ->addColumn('currency', 'string', ['limit' => 3, 'default' => 'CNY'])
                ->addColumn('fund_type', 'string', ['limit' => 24, 'default' => 'recharge_cash'])
                ->addColumn('available_minor', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('frozen_minor', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('version', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addIndex(['member_id', 'currency', 'fund_type'], ['unique' => true, 'name' => 'uk_wallet_member_currency_type'])
                ->create();
        }

        if (!$this->hasTable('recharges')) {
            $this->table('recharges', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('recharge_no', 'string', ['limit' => 40])
                ->addColumn('member_id', 'integer', ['signed' => false])
                ->addColumn('amount_minor', 'biginteger', ['signed' => false])
                ->addColumn('currency', 'string', ['limit' => 3, 'default' => 'CNY'])
                ->addColumn('channel', 'string', ['limit' => 24])
                ->addColumn('status', 'string', ['limit' => 24])
                ->addColumn('paid_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addIndex(['recharge_no'], ['unique' => true, 'name' => 'uk_recharge_no'])
                ->addIndex(['member_id', 'created_at'], ['name' => 'idx_recharge_member_time'])
                ->create();
        }

        if (!$this->hasTable('payment_transactions')) {
            $this->table('payment_transactions', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('payment_no', 'string', ['limit' => 40])
                ->addColumn('member_id', 'integer', ['signed' => false])
                ->addColumn('purpose', 'string', ['limit' => 16])
                ->addColumn('purpose_id', 'integer', ['signed' => false])
                ->addColumn('channel', 'string', ['limit' => 24])
                ->addColumn('channel_trade_no', 'string', ['limit' => 80, 'null' => true])
                ->addColumn('amount_minor', 'biginteger', ['signed' => false])
                ->addColumn('currency', 'string', ['limit' => 3, 'default' => 'CNY'])
                ->addColumn('status', 'string', ['limit' => 24])
                ->addColumn('paid_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addIndex(['payment_no'], ['unique' => true, 'name' => 'uk_payment_no'])
                ->addIndex(['channel', 'channel_trade_no'], ['unique' => true, 'name' => 'uk_payment_channel_trade'])
                ->addIndex(['purpose', 'purpose_id'], ['name' => 'idx_payment_purpose'])
                ->addIndex(['member_id', 'created_at'], ['name' => 'idx_payment_member_time'])
                ->create();
        }

        if (!$this->hasTable('payment_notifications')) {
            $this->table('payment_notifications', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('channel', 'string', ['limit' => 24])
                ->addColumn('notification_id', 'string', ['limit' => 100])
                ->addColumn('payment_id', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('status', 'string', ['limit' => 24])
                ->addColumn('received_at', 'datetime')
                ->addColumn('processed_at', 'datetime', ['null' => true])
                ->addIndex(['channel', 'notification_id'], ['unique' => true, 'name' => 'uk_payment_notification'])
                ->create();
        }

        if (!$this->hasTable('wallet_funding_lots')) {
            $this->table('wallet_funding_lots', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('account_id', 'integer', ['signed' => false])
                ->addColumn('recharge_id', 'integer', ['signed' => false])
                ->addColumn('credited_minor', 'biginteger', ['signed' => false])
                ->addColumn('remaining_minor', 'biginteger', ['signed' => false])
                ->addColumn('created_at', 'datetime')
                ->addIndex(['recharge_id'], ['unique' => true, 'name' => 'uk_wallet_lot_recharge'])
                ->addIndex(['account_id', 'id'], ['name' => 'idx_wallet_lot_account'])
                ->create();
        }

        if (!$this->hasTable('wallet_entries')) {
            $this->table('wallet_entries', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('entry_no', 'string', ['limit' => 48])
                ->addColumn('account_id', 'integer', ['signed' => false])
                ->addColumn('member_id', 'integer', ['signed' => false])
                ->addColumn('entry_type', 'string', ['limit' => 32])
                ->addColumn('source_type', 'string', ['limit' => 32])
                ->addColumn('source_id', 'integer', ['signed' => false])
                ->addColumn('amount_minor', 'biginteger')
                ->addColumn('available_after_minor', 'biginteger', ['signed' => false])
                ->addColumn('created_at', 'datetime')
                ->addIndex(['entry_no'], ['unique' => true, 'name' => 'uk_wallet_entry_no'])
                ->addIndex(['source_type', 'source_id', 'entry_type'], ['unique' => true, 'name' => 'uk_wallet_entry_source'])
                ->addIndex(['member_id', 'created_at'], ['name' => 'idx_wallet_entry_member_time'])
                ->create();
        }

        if (!$this->hasTable('wallet_consumptions')) {
            $this->table('wallet_consumptions', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('order_id', 'integer', ['signed' => false])
                ->addColumn('funding_lot_id', 'integer', ['signed' => false])
                ->addColumn('consumed_minor', 'biginteger', ['signed' => false])
                ->addColumn('restored_minor', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('created_at', 'datetime')
                ->addIndex(['order_id', 'funding_lot_id'], ['unique' => true, 'name' => 'uk_wallet_consumption_order_lot'])
                ->create();
        }

        if (!$this->hasTable('refund_requests')) {
            $this->table('refund_requests', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('refund_no', 'string', ['limit' => 48])
                ->addColumn('member_id', 'integer', ['signed' => false])
                ->addColumn('source_type', 'string', ['limit' => 16])
                ->addColumn('source_id', 'integer', ['signed' => false])
                ->addColumn('payment_id', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('amount_minor', 'biginteger', ['signed' => false])
                ->addColumn('currency', 'string', ['limit' => 3, 'default' => 'CNY'])
                ->addColumn('destination', 'string', ['limit' => 24])
                ->addColumn('status', 'string', ['limit' => 24])
                ->addColumn('reason', 'string', ['limit' => 255, 'default' => ''])
                ->addColumn('reviewer_id', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('reviewed_at', 'datetime', ['null' => true])
                ->addColumn('completed_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addIndex(['refund_no'], ['unique' => true, 'name' => 'uk_refund_no'])
                ->addIndex(['source_type', 'source_id'], ['name' => 'idx_refund_source'])
                ->addIndex(['member_id', 'created_at'], ['name' => 'idx_refund_member_time'])
                ->create();
        }

        if (!$this->hasTable('refund_attempts')) {
            $this->table('refund_attempts', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('refund_id', 'integer', ['signed' => false])
                ->addColumn('channel', 'string', ['limit' => 24])
                ->addColumn('channel_refund_no', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('status', 'string', ['limit' => 24])
                ->addColumn('error_code', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('created_at', 'datetime')
                ->addColumn('confirmed_at', 'datetime', ['null' => true])
                ->addIndex(['refund_id', 'id'], ['name' => 'idx_refund_attempt'])
                ->addIndex(['channel', 'channel_refund_no'], ['unique' => true, 'name' => 'uk_refund_channel_no'])
                ->create();
        }

        if (!$this->hasTable('invoice_records')) {
            $this->table('invoice_records', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('invoice_no', 'string', ['limit' => 48])
                ->addColumn('member_id', 'integer', ['signed' => false])
                ->addColumn('order_id', 'integer', ['signed' => false])
                ->addColumn('red_of_id', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('amount_minor', 'biginteger', ['signed' => false])
                ->addColumn('currency', 'string', ['limit' => 3, 'default' => 'CNY'])
                ->addColumn('invoice_type', 'string', ['limit' => 16])
                ->addColumn('title', 'string', ['limit' => 160])
                ->addColumn('status', 'string', ['limit' => 24])
                ->addColumn('external_number', 'string', ['limit' => 80, 'null' => true])
                ->addColumn('attachment_path', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('reviewer_id', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('reviewed_at', 'datetime', ['null' => true])
                ->addColumn('issued_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addIndex(['invoice_no'], ['unique' => true, 'name' => 'uk_invoice_no'])
                ->addIndex(['order_id', 'created_at'], ['name' => 'idx_invoice_order_time'])
                ->addIndex(['member_id', 'created_at'], ['name' => 'idx_invoice_member_time'])
                ->create();
        }

        if (!$this->hasTable('invoice_allocations')) {
            $this->table('invoice_allocations', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('invoice_id', 'integer', ['signed' => false])
                ->addColumn('order_unit_id', 'integer', ['signed' => false])
                ->addColumn('amount_minor', 'biginteger', ['signed' => false])
                ->addIndex(['invoice_id', 'order_unit_id'], ['unique' => true, 'name' => 'uk_invoice_unit'])
                ->addIndex(['order_unit_id'], ['name' => 'idx_invoice_allocation_unit'])
                ->create();
        }

        if (!$this->hasTable('withdrawal_requests')) {
            $this->table('withdrawal_requests', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('withdrawal_no', 'string', ['limit' => 48])
                ->addColumn('member_id', 'integer', ['signed' => false])
                ->addColumn('source_type', 'string', ['limit' => 24])
                ->addColumn('source_id', 'integer', ['signed' => false])
                ->addColumn('amount_minor', 'biginteger', ['signed' => false])
                ->addColumn('currency', 'string', ['limit' => 3, 'default' => 'CNY'])
                ->addColumn('status', 'string', ['limit' => 24])
                ->addColumn('payee_snapshot', 'text', ['null' => true])
                ->addColumn('reason', 'string', ['limit' => 255, 'default' => ''])
                ->addColumn('reviewer_id', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('reviewed_at', 'datetime', ['null' => true])
                ->addColumn('payout_reference', 'string', ['limit' => 80, 'null' => true])
                ->addColumn('payout_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addIndex(['withdrawal_no'], ['unique' => true, 'name' => 'uk_withdrawal_no'])
                ->addIndex(['source_type', 'source_id'], ['name' => 'idx_withdrawal_source'])
                ->addIndex(['member_id', 'created_at'], ['name' => 'idx_withdrawal_member_time'])
                ->create();
        }

        if (!$this->hasTable('finance_outbox')) {
            $this->table('finance_outbox', $options)
                ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
                ->addColumn('event_key', 'string', ['limit' => 100])
                ->addColumn('event_type', 'string', ['limit' => 48])
                ->addColumn('aggregate_id', 'integer', ['signed' => false])
                ->addColumn('payload', 'text')
                ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending'])
                ->addColumn('attempts', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('created_at', 'datetime')
                ->addColumn('processed_at', 'datetime', ['null' => true])
                ->addIndex(['event_key'], ['unique' => true, 'name' => 'uk_finance_outbox_key'])
                ->addIndex(['status', 'id'], ['name' => 'idx_finance_outbox_status'])
                ->create();
        }

        if ($this->getAdapter()->getOption('adapter') !== 'sqlite') {
            $prefix = (string) $this->getAdapter()->getOption('table_prefix');
            foreach ([
                ['order_item_units', 'order_item_id', 'order_items'],
                ['wallet_accounts', 'member_id', 'members'],
                ['recharges', 'member_id', 'members'],
                ['payment_transactions', 'member_id', 'members'],
                ['wallet_funding_lots', 'account_id', 'wallet_accounts'],
                ['wallet_funding_lots', 'recharge_id', 'recharges'],
                ['payment_notifications', 'payment_id', 'payment_transactions'],
                ['wallet_entries', 'account_id', 'wallet_accounts'],
                ['wallet_entries', 'member_id', 'members'],
                ['wallet_consumptions', 'order_id', 'orders'],
                ['wallet_consumptions', 'funding_lot_id', 'wallet_funding_lots'],
                ['refund_requests', 'member_id', 'members'],
                ['refund_requests', 'payment_id', 'payment_transactions'],
                ['refund_attempts', 'refund_id', 'refund_requests'],
                ['invoice_records', 'member_id', 'members'],
                ['invoice_records', 'order_id', 'orders'],
                ['invoice_allocations', 'invoice_id', 'invoice_records'],
                ['invoice_allocations', 'order_unit_id', 'order_item_units'],
                ['withdrawal_requests', 'member_id', 'members'],
            ] as [$tableName, $column, $parent]) {
                $table = $this->table($tableName);
                if (!$table->hasForeignKey($column)) {
                    $table->addForeignKey($column, $parent, 'id', [
                        'constraint' => $prefix . 'fk_' . $tableName . '_' . $column,
                        'delete' => 'RESTRICT', 'update' => 'RESTRICT',
                    ])->update();
                }
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('财务记录不可回滚删除，请使用前向修复迁移。');
    }
}
