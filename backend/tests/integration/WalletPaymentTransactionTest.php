<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\finance\application\WalletLedgerService;
use app\modules\finance\infrastructure\DatabaseWalletLedgerRepository;
use app\modules\finance\infrastructure\DatabaseOutboxWriter;
use app\modules\order\application\PayOrderByWalletService;
use app\modules\order\infrastructure\DatabaseOrderPaymentRepository;
use app\shared\infrastructure\DatabaseTransactionManager;
use app\shared\contract\OutboxWriterInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 验证公开付款用例事务中订单、余额、来源、支付及 Outbox 的一致性。 */
final class WalletPaymentTransactionTest extends TestCase
{
    use IsolatedBusinessDatabase;

    private function wallet(): WalletLedgerService
    {
        return new WalletLedgerService(new DatabaseWalletLedgerRepository(), new DatabaseTransactionManager());
    }

    private function payments(?OutboxWriterInterface $outbox = null): PayOrderByWalletService
    {
        return new PayOrderByWalletService(new DatabaseOrderPaymentRepository(), $this->wallet(),
            new DatabaseTransactionManager(), $outbox ?? new DatabaseOutboxWriter());
    }

    public function testRechargeAndOrderAreIdempotentWithSeparatePaymentSources(): void
    {
        $member = $this->member();
        $order = $this->order($member);
        $recharge = $this->recharge($member);
        self::assertSame(1500, $this->wallet()->creditConfirmedRecharge($member, $recharge));
        self::assertSame(1500, $this->wallet()->creditConfirmedRecharge($member, $recharge));
        self::assertSame(300, $this->payments()->pay($member, $order));
        self::assertSame(300, $this->payments()->pay($member, $order));
        self::assertSame(2, Db::table('payment_transactions')->count());
        self::assertSame(2, Db::table('wallet_entries')->count());
        self::assertSame(1, Db::table('wallet_consumptions')->count());
        self::assertSame(1, Db::table('finance_outbox')->count());
        self::assertSame(300, (int) Db::table('wallet_funding_lots')->value('remaining_minor'));
    }

    public function testFailureAfterOutboxWriteRollsBackEveryParticipantAndRetryWorks(): void
    {
        $member = $this->member();
        $order = $this->order($member);
        $this->wallet()->creditConfirmedRecharge($member, $this->recharge($member));
        $outbox = new class implements OutboxWriterInterface {
            public function append(string $key, string $type, int $aggregateId, array $payload): void
            {
                (new DatabaseOutboxWriter())->append($key, $type, $aggregateId, $payload);
                throw new RuntimeException('故障注入：全部写入后提交前失败');
            }
        };
        try {
            $this->payments($outbox)->pay($member, $order);
            self::fail('故障应该传播');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('故障注入', $error->getMessage());
        }
        $this->assertUnpaid($order, 1500);
        self::assertSame(300, $this->payments()->pay($member, $order));
    }

    public function testPaymentInsertFailureRollsBackSourceAndLedgerWrites(): void
    {
        $member = $this->member();
        $order = $this->order($member);
        $this->wallet()->creditConfirmedRecharge($member, $this->recharge($member));
        $number = 'WP' . substr(hash('sha256', 'ORDER-TEST'), 0, 32);
        Db::table('payment_transactions')->where('purpose', 'recharge')->update(['payment_no' => $number]);
        try {
            $this->payments()->pay($member, $order);
            self::fail('支付编号冲突应该导致整体回滚');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertUnpaid($order, 1500);
        }
    }

    public function testOwnershipInsufficientBalanceAndIncompleteAllocationCannotChangeFunds(): void
    {
        $member = $this->member();
        $other = $this->member('13800138002');
        $order = $this->order($member);
        $this->wallet()->creditConfirmedRecharge($member, $this->recharge($member, 1000));
        foreach ([$other, $member] as $actor) {
            try {
                $this->payments()->pay($actor, $order);
                self::fail('无归属或余额不足应该拒绝');
            } catch (BusinessException $error) {
                self::assertSame(409, $error->httpStatus());
            }
            $this->assertUnpaid($order, 1000);
        }
        Db::table('order_item_units')->where('unit_index', 2)->delete();
        try {
            $this->payments()->pay($member, $order);
            self::fail('实例未分摊完整不能付款');
        } catch (BusinessException $error) {
            self::assertStringContainsString('分摊', $error->getMessage());
        }
        $this->assertUnpaid($order, 1000);
    }

    public function testRechargePaymentMismatchCannotCreditAndPaidOrderMismatchCannotDebitAgain(): void
    {
        $member = $this->member();
        $recharge = $this->recharge($member);
        Db::table('payment_transactions')->update(['amount_minor' => 1499]);
        try {
            $this->wallet()->creditConfirmedRecharge($member, $recharge);
            self::fail('金额不一致不能入账');
        } catch (BusinessException) {
            self::assertSame(0, Db::table('wallet_accounts')->count());
            self::assertSame(0, Db::table('wallet_entries')->count());
        }
        Db::table('payment_transactions')->update(['amount_minor' => 1500]);
        $this->wallet()->creditConfirmedRecharge($member, $recharge);
        $order = $this->order($member);
        $this->payments()->pay($member, $order);
        Db::table('payment_transactions')->where('purpose', 'order')->update(['amount_minor' => 1199]);
        try {
            $this->payments()->pay($member, $order);
            self::fail('重复请求必须核实支付金额');
        } catch (BusinessException) {
            self::assertSame(300, $this->wallet()->balance($member)['available_minor']);
            self::assertSame(2, Db::table('wallet_entries')->count());
        }
    }

    private function assertUnpaid(int $order, int $balance): void
    {
        self::assertSame('unpaid', Db::table('orders')->where('id', $order)->value('payment_status'));
        self::assertSame(0, (int) Db::table('orders')->where('id', $order)->value('paid_minor'));
        self::assertSame($balance, (int) Db::table('wallet_accounts')->value('available_minor'));
        self::assertSame($balance, (int) Db::table('wallet_funding_lots')->value('remaining_minor'));
        self::assertSame(1, Db::table('wallet_entries')->count());
        self::assertSame(1, Db::table('payment_transactions')->count());
        self::assertSame(0, Db::table('wallet_consumptions')->count());
        self::assertSame(0, Db::table('finance_outbox')->count());
        self::assertSame(0, Db::connection()->transactionLevel());
    }
}
