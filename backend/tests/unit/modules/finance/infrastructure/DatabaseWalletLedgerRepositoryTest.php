<?php

declare(strict_types=1);

namespace tests\unit\modules\finance\infrastructure;

use app\modules\finance\infrastructure\DatabaseWalletLedgerRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use support\Db;

/** 使用独立 SQLite 连接检验充值、余额消费和重复请求的数据库效果。 */
final class DatabaseWalletLedgerRepositoryTest extends TestCase
{
    private mixed $previousCapsule;
    private mixed $previousResolver;
    private mixed $previousDispatcher;

    protected function setUp(): void
    {
        class_exists(Db::class);
        $this->previousCapsule = (new ReflectionProperty(Capsule::class, 'instance'))->getValue();
        class_exists(\support\Model::class);
        $this->previousResolver = \support\Model::getConnectionResolver();
        $this->previousDispatcher = \support\Model::getEventDispatcher();
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $connection = $capsule->getConnection();
        foreach ([
            'CREATE TABLE wallet_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, member_id INTEGER NOT NULL, currency TEXT NOT NULL, fund_type TEXT NOT NULL, available_minor INTEGER NOT NULL, frozen_minor INTEGER NOT NULL, version INTEGER NOT NULL, created_at TEXT, updated_at TEXT, UNIQUE(member_id,currency,fund_type))',
            'CREATE TABLE recharges (id INTEGER PRIMARY KEY, member_id INTEGER, amount_minor INTEGER, currency TEXT, channel TEXT, status TEXT, updated_at TEXT)',
            'CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, payment_no TEXT, member_id INTEGER, purpose TEXT, purpose_id INTEGER, channel TEXT, channel_trade_no TEXT, amount_minor INTEGER, currency TEXT, status TEXT, paid_at TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE wallet_funding_lots (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, recharge_id INTEGER, credited_minor INTEGER, remaining_minor INTEGER, created_at TEXT)',
            'CREATE TABLE wallet_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_no TEXT, account_id INTEGER, member_id INTEGER, entry_type TEXT, source_type TEXT, source_id INTEGER, amount_minor INTEGER, available_after_minor INTEGER, created_at TEXT)',
            'CREATE TABLE orders (id INTEGER PRIMARY KEY, order_no TEXT, member_id INTEGER, currency TEXT, status TEXT, payment_status TEXT, payable_minor INTEGER, paid_minor INTEGER, paid_at TEXT, updated_at TEXT)',
            'CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER, quantity INTEGER)',
            'CREATE TABLE order_item_units (id INTEGER PRIMARY KEY, order_item_id INTEGER, unit_index INTEGER, allocated_minor INTEGER)',
            'CREATE TABLE wallet_consumptions (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, funding_lot_id INTEGER, consumed_minor INTEGER, restored_minor INTEGER, created_at TEXT)',
            'CREATE TABLE finance_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, event_key TEXT, event_type TEXT, aggregate_id INTEGER, payload TEXT, status TEXT, attempts INTEGER, created_at TEXT)',
        ] as $sql) {
            $connection->statement($sql);
        }
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Capsule::class, 'instance'))->setValue(null, $this->previousCapsule);
        \support\Model::setConnectionResolver($this->previousResolver);
        \support\Model::setEventDispatcher($this->previousDispatcher);
    }

    public function testRechargeAndWalletPaymentAreIdempotentAndTraceFundingLot(): void
    {
        Db::table('recharges')->insert(['id' => 3, 'member_id' => 7, 'amount_minor' => 1500, 'currency' => 'CNY', 'channel' => 'wechat', 'status' => 'paid']);
        Db::table('payment_transactions')->insert(['payment_no' => 'P1', 'member_id' => 7, 'purpose' => 'recharge', 'purpose_id' => 3,
            'channel' => 'wechat', 'amount_minor' => 1500, 'currency' => 'CNY', 'status' => 'paid']);
        Db::table('orders')->insert(['id' => 9, 'order_no' => 'O9', 'member_id' => 7, 'currency' => 'CNY',
            'status' => 'pending_payment', 'payment_status' => 'unpaid', 'payable_minor' => 1200, 'paid_minor' => 0]);
        Db::table('order_items')->insert(['id' => 11, 'order_id' => 9, 'quantity' => 2]);
        Db::table('order_item_units')->insert([['id' => 21, 'order_item_id' => 11, 'unit_index' => 1, 'allocated_minor' => 600],
            ['id' => 22, 'order_item_id' => 11, 'unit_index' => 2, 'allocated_minor' => 600]]);

        $transactions = new \app\shared\infrastructure\DatabaseTransactionManager();
        $ledger = new \app\modules\finance\application\WalletLedgerService(new DatabaseWalletLedgerRepository(), $transactions);
        $payments = new \app\modules\order\application\PayOrderByWalletService(
            new \app\modules\order\infrastructure\DatabaseOrderPaymentRepository(), $ledger, $transactions,
            new \app\modules\finance\infrastructure\DatabaseOutboxWriter(),
        );
        self::assertSame(1500, $ledger->creditConfirmedRecharge(7, 3));
        self::assertSame(1500, $ledger->creditConfirmedRecharge(7, 3));
        self::assertSame(300, $payments->pay(7, 9));
        self::assertSame(300, $payments->pay(7, 9));

        self::assertSame(2, Db::table('wallet_entries')->count());
        self::assertSame(1, Db::table('wallet_consumptions')->count());
        self::assertSame(300, (int) Db::table('wallet_funding_lots')->value('remaining_minor'));
        self::assertSame(1, Db::table('finance_outbox')->count());
        self::assertSame('paid', Db::table('orders')->value('payment_status'));
    }

    public function testUnallocatedOrderCannotConsumeWalletBalance(): void
    {
        Db::table('recharges')->insert(['id' => 3, 'member_id' => 7, 'amount_minor' => 1500, 'currency' => 'CNY', 'channel' => 'wechat', 'status' => 'paid']);
        Db::table('payment_transactions')->insert(['payment_no' => 'P1', 'member_id' => 7, 'purpose' => 'recharge', 'purpose_id' => 3,
            'channel' => 'wechat', 'amount_minor' => 1500, 'currency' => 'CNY', 'status' => 'paid']);
        Db::table('orders')->insert(['id' => 9, 'order_no' => 'O9', 'member_id' => 7, 'currency' => 'CNY',
            'status' => 'pending_payment', 'payment_status' => 'unpaid', 'payable_minor' => 1200, 'paid_minor' => 0]);
        Db::table('order_items')->insert(['id' => 11, 'order_id' => 9, 'quantity' => 2]);
        $transactions = new \app\shared\infrastructure\DatabaseTransactionManager();
        $ledger = new \app\modules\finance\application\WalletLedgerService(new DatabaseWalletLedgerRepository(), $transactions);
        $payments = new \app\modules\order\application\PayOrderByWalletService(
            new \app\modules\order\infrastructure\DatabaseOrderPaymentRepository(), $ledger, $transactions,
            new \app\modules\finance\infrastructure\DatabaseOutboxWriter(),
        );
        $ledger->creditConfirmedRecharge(7, 3);

        try {
            $payments->pay(7, 9);
            self::fail('未分摊实例金额的订单不能扣款');
        } catch (\app\exception\BusinessException $exception) {
            self::assertSame(409, $exception->httpStatus());
        }
        self::assertSame(1500, (int) Db::table('wallet_accounts')->value('available_minor'));
        self::assertSame(0, Db::table('wallet_consumptions')->count());
    }
}
