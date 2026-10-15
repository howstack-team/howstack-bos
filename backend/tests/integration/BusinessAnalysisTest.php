<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\business_analysis\application\GetBusinessAnalysisService;
use app\modules\business_analysis\infrastructure\DatabaseBusinessAnalysisRepository;
use app\modules\business_analysis\validation\AnalysisQueryValidator;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use support\Db;
use tests\support\IsolatedBusinessDatabase;

/** 用真实迁移和隔离数据库验证边界、去重、退款及金额口径。 */
final class BusinessAnalysisTest extends TestCase
{
    use IsolatedBusinessDatabase;

    private int $sequence = 0;

    public function testPaymentAndRefundTimeAreIndependentAndOrdersAreNotMultiplied(): void
    {
        $member = $this->analysisMember('2026-10-01 00:00:00');
        $oldMember = $this->analysisMember('2026-09-30 23:59:59');
        $this->analysisOrder($member, '2026-10-01 00:00:00', '2026-10-01 00:00:00', 10001,
            ['status' => 'refunded', 'refunded_minor' => 10001, 'payment_status' => 'refunded']);
        $this->analysisOrder($member, '2026-10-02 23:59:59', '2026-10-02 23:59:59', 20000);
        $this->analysisOrder($oldMember, '2026-09-29 12:00:00', '2026-10-02 11:00:00', 30000, ['order_type' => 'renewal']);
        $oldOrder = $this->analysisOrder($oldMember, '2026-09-30 12:00:00', '2026-09-30 12:00:00', 5000);
        $this->analysisOrder($member, '2026-10-01 14:00:00', null, 0,
            ['status' => 'cancelled', 'payment_status' => 'unpaid', 'order_type' => 'trial']);
        $this->analysisOrder($member, '2026-10-01 15:00:00', '2026-10-01 15:00:00', 99000, ['currency' => 'USD']);
        $this->analysisOrder($member, '2026-10-03 00:00:00', '2026-10-03 00:00:00', 99000);
        $this->refund($member, '2026-10-02 23:59:59', 4000);
        $this->refund($member, '2026-10-02 12:00:00', 9000, 'approved');
        $this->refund($member, '2026-10-03 00:00:00', 9000);
        $this->refund($member, '2026-10-01 12:00:00', 9000, 'completed', 'USD');
        $this->refund($member, '2026-10-01 12:00:00', 9000, 'completed', 'CNY', 'recharge');
        // 即使一个订单有多产品项，也不能在销售指标中重复累计。
        foreach ([2, 3] as $line) {
            $this->row('order_items', ['order_id' => $oldOrder, 'line_no' => $line,
                'product_type' => 'cloud_server', 'product_type_version' => '1', 'sales_version' => '1',
                'product_name' => '实例', 'quantity' => 3, 'unit_price_minor' => 1000, 'total_minor' => 3000,
                'created_at' => '2026-09-30 12:00:00']);
        }
        // 充值是现金入账，不能被当作订单销售额。
        $this->row('payment_transactions', ['payment_no' => 'PAY-RECHARGE', 'member_id' => $member,
            'purpose' => 'recharge', 'purpose_id' => 999, 'channel' => 'offline', 'channel_trade_no' => 'REC-TEST',
            'amount_minor' => 999999, 'currency' => 'CNY', 'status' => 'paid',
            'paid_at' => '2026-10-01 10:00:00', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00']);

        $result = $this->analysis('2026-10-01', '2026-10-02');
        self::assertSame(60001, $result['summary']['paid_amount_minor']);
        self::assertSame(4000, $result['summary']['refund_amount_minor']);
        self::assertSame(56001, $result['summary']['net_paid_amount_minor']);
        self::assertSame(3, $result['summary']['created_orders']);
        self::assertSame(3, $result['summary']['paid_orders']);
        self::assertSame(2, $result['summary']['paid_members']);
        self::assertSame(1, $result['summary']['new_members']);
        self::assertSame(20000, $result['summary']['average_paid_order_minor']);
        self::assertSame(5000, $result['previous_summary']['paid_amount_minor']);
        self::assertSame(1, $result['previous_summary']['paid_members']);
        self::assertSame(10001, $result['trend'][0]['paid_amount_minor']);
        self::assertSame(50000, $result['trend'][1]['paid_amount_minor']);
        self::assertSame(['purchase', 'renewal', 'trial'], array_column($result['order_types'], 'order_type'));
        self::assertSame(3, array_sum(array_column($result['order_statuses'], 'count')));
        self::assertStringNotContainsString('mobile', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testDistinctPayingMembersAcrossDaysAndIntegerRounding(): void
    {
        $member = $this->analysisMember('2026-09-01 00:00:00');
        $this->analysisOrder($member, '2026-10-01 12:00:00', '2026-10-01 12:00:00', 100);
        $this->analysisOrder($member, '2026-10-02 12:00:00', '2026-10-02 12:00:00', 101);
        $result = $this->analysis('2026-10-01', '2026-10-02');
        self::assertSame(1, $result['summary']['paid_members']);
        self::assertSame(101, $result['summary']['average_paid_order_minor']);
    }

    public function testOldOrderRefundCanMakeCurrentNetNegative(): void
    {
        $member = $this->analysisMember('2026-09-01 00:00:00');
        $this->analysisOrder($member, '2026-09-01 12:00:00', '2026-09-01 12:00:00', 1000);
        $this->refund($member, '2026-10-02 12:00:00', 1000);
        $result = $this->analysis('2026-10-01', '2026-10-02');
        self::assertSame(-1000, $result['summary']['net_paid_amount_minor']);
        self::assertSame(-1000, $result['trend'][1]['net_paid_amount_minor']);
        self::assertSame(0, $result['summary']['average_paid_order_minor']);
    }

    public function testEmptyDaysAndPartialMonthsAreFilledWithoutExpandingRange(): void
    {
        $member = $this->analysisMember('2026-09-30 23:59:59');
        $this->analysisOrder($member, '2026-10-01 00:00:00', '2026-10-01 00:00:00', 100);
        $monthly = $this->analysis('2026-09-30', '2026-10-02', 'month');
        self::assertSame(['2026-09', '2026-10'], array_column($monthly['trend'], 'date'));
        self::assertSame(0, $monthly['trend'][0]['paid_amount_minor']);
        self::assertSame(100, $monthly['trend'][1]['paid_amount_minor']);
        $daily = $this->analysis('2026-10-03', '2026-10-05');
        self::assertSame(['2026-10-03', '2026-10-04', '2026-10-05'], array_column($daily['trend'], 'date'));
        self::assertSame([], $daily['order_types']);
        self::assertSame([], $daily['order_statuses']);
        self::assertSame(0, array_sum($daily['summary']));
    }

    public function testLargeAmountsRemainIntegersAndQueryCountIsBounded(): void
    {
        $member = $this->analysisMember('2026-10-01 00:00:00');
        $this->analysisOrder($member, '2026-10-01 12:00:00', '2026-10-01 12:00:00', 8000000001);
        Db::connection()->enableQueryLog();
        Db::connection()->flushQueryLog();
        try {
            $result = $this->analysis('2026-01-01', '2026-10-09');
            self::assertCount(5, Db::connection()->getQueryLog());
        } finally {
            Db::connection()->disableQueryLog();
        }
        self::assertSame(8000000001, $result['summary']['paid_amount_minor']);
        self::assertSame(8000000001, $result['summary']['average_paid_order_minor']);
        $indexes = Db::connection()->getSchemaBuilder()->getIndexes('orders');
        self::assertContains('idx_analysis_order_paid', array_column($indexes, 'name'));
        $indexes = Db::connection()->getSchemaBuilder()->getIndexes('refund_requests');
        self::assertContains('idx_analysis_refund_completed', array_column($indexes, 'name'));
    }

    /** @return array<string, mixed> 真实服务聚合结果 */
    private function analysis(string $start, string $end, string $granularity = 'day'): array
    {
        $service = new GetBusinessAnalysisService(new AdminAccessControlService(
            $this->createStub(AdminAccessRepositoryInterface::class),
        ), new DatabaseBusinessAnalysisRepository());
        $period = AnalysisQueryValidator::validate(['start_date' => $start, 'end_date' => $end, 'granularity' => $granularity],
            new DateTimeImmutable('2026-10-09 12:00:00', new DateTimeZone('Asia/Shanghai')));

        return $service->get(new AdminAccount(1, 'founder', '创始人', 'hash', 1, null, true), $period);
    }

    private function analysisMember(string $createdAt): int
    {
        $id = $this->member('188000' . sprintf('%05d', ++$this->sequence));
        Db::table('members')->where('id', $id)->update(['created_at' => $createdAt]);

        return $id;
    }

    /** @param array<string, int|string|null> $overrides 专项边界 */
    private function analysisOrder(int $member, string $created, ?string $paid, int $amount, array $overrides = []): int
    {
        $id = $this->order($member, 'AN-ORDER-' . ++$this->sequence);
        Db::table('orders')->where('id', $id)->update($overrides + [
            'created_at' => $created, 'paid_at' => $paid, 'paid_minor' => $amount,
            'payable_minor' => $amount, 'payment_status' => 'paid', 'status' => 'completed',
        ]);

        return $id;
    }

    private function refund(int $member, string $completed, int $amount, string $status = 'completed', string $currency = 'CNY', string $source = 'order_unit'): void
    {
        $this->row('refund_requests', ['refund_no' => 'AN-REFUND-' . ++$this->sequence,
            'member_id' => $member, 'source_type' => $source, 'source_id' => 1, 'amount_minor' => $amount,
            'currency' => $currency, 'destination' => 'wallet', 'status' => $status, 'completed_at' => $completed,
            'created_at' => $completed, 'updated_at' => $completed]);
    }
}
