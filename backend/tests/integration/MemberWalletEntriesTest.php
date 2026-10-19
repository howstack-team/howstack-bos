<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\infrastructure\DatabaseAdminAccessRepository;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\finance\application\{ListMemberWalletEntriesService, ManageFinanceService, WalletLedgerService};
use app\modules\finance\controller\MemberWalletEntryController;
use app\modules\finance\infrastructure\{DatabaseMemberWalletEntryRepository, DatabaseFinanceWorkflowRepository, DatabaseWalletLedgerRepository, DatabaseOutboxWriter};
use app\modules\finance\validation\MemberWalletEntriesValidator;
use app\modules\member\application\MemberAuthenticationContext;
use app\modules\member\domain\MemberAccount;
use app\modules\order\application\PayOrderByWalletService;
use app\modules\order\infrastructure\{DatabaseOrderPaymentRepository, DatabaseOrderFinanceRepository};
use app\shared\http\ApiResponse;
use app\shared\infrastructure\DatabaseTransactionManager;
use PHPUnit\Framework\TestCase;
use support\{Db, Request};
use tests\support\{IsolatedBusinessDatabase, MessageRuntimeFactory, ProductRuntimeFactory, RecordingOrderProvisioning};

/** 实际迁移/ORM及账务门面验证；不访问外部支付或供应平台。 */
final class MemberWalletEntriesTest extends TestCase
{
    use IsolatedBusinessDatabase;
    use \tests\support\UndeliveredOrderFixture;

    private function actor(): AdminAccount { return new AdminAccount(1, 'fixture-admin', '测试管理员', 'hash', 1, null, true); }
    private function input(array $values): array { return ['request_key' => bin2hex(random_bytes(16))] + $values; }
    private function finance(): ManageFinanceService
    {
        $ledger = new DatabaseWalletLedgerRepository(); $tx = new DatabaseTransactionManager();
        return new ManageFinanceService(new AdminAccessControlService(new DatabaseAdminAccessRepository()),
            new DatabaseFinanceWorkflowRepository(new \app\modules\service_instance\infrastructure\DatabaseUndeliveredRefundGuard()), $ledger, new WalletLedgerService($ledger, $tx, MessageRuntimeFactory::publisher()),
            new DatabaseOrderFinanceRepository(ProductRuntimeFactory::purchases()), $tx, new DatabaseOutboxWriter(), new \app\modules\service_instance\infrastructure\DatabaseUndeliveredRefundGuard());
    }
    private function listing(int $member, int $page = 1, int $size = 20): array
    {
        return (new ListMemberWalletEntriesService(new DatabaseMemberWalletEntryRepository()))->execute($member, $page, $size);
    }

    public function testActualRechargePaymentRefundAndFrozenSettlementAreReadableWithoutChangingLedger(): void
    {
        $member = $this->member(); $finance = $this->finance(); $actor = $this->actor();
        $recharge = $finance->create($actor, 'recharges', $this->input(['member_id' => $member, 'amount_minor' => 1500, 'external_reference' => 'PRIVATE-BANK-REF']));
        $order = $this->order($member);
        $ledger = new DatabaseWalletLedgerRepository(); $tx = new DatabaseTransactionManager();
        (new PayOrderByWalletService(new DatabaseOrderPaymentRepository(), new WalletLedgerService($ledger, $tx, MessageRuntimeFactory::publisher()),
            $tx, new DatabaseOutboxWriter(), MessageRuntimeFactory::publisher(), ProductRuntimeFactory::purchases(), new RecordingOrderProvisioning()))->pay($member, $order);
        $this->confirmFixtureUndelivered($order);
        $unit = (int) Db::table('order_item_units')->orderBy('id')->value('id');
        $refund = $finance->create($actor, 'refunds', $this->input(['member_id' => $member, 'source_id' => $unit, 'amount_minor' => 600, 'reason' => '私有审核原因']));
        $finance->act($this->reviewer(), 'refunds', $refund['id'], 'approve', $this->input(['review_reason' => '已核实未交付']));
        $finance->act($actor, 'refunds', $refund['id'], 'execute', $this->input([]));
        $withdraw = $finance->create($actor, 'withdrawals', $this->input(['member_id' => $member, 'source_id' => $recharge['id'], 'amount_minor' => 100, 'reason' => '退还未消费资金']));
        $finance->act($this->reviewer(), 'withdrawals', $withdraw['id'], 'reject', $this->input(['review_reason' => '资料不足']));
        $withdraw = $finance->create($actor, 'withdrawals', $this->input(['member_id' => $member, 'source_id' => $recharge['id'], 'amount_minor' => 200, 'reason' => '退还未消费资金']));
        $finance->act($this->reviewer(), 'withdrawals', $withdraw['id'], 'approve', $this->input(['review_reason' => '已核实资金来源']));
        $finance->act($actor, 'withdrawals', $withdraw['id'], 'payout', $this->input(['external_reference' => 'PRIVATE-PAYOUT', 'payout_note' => '已核对外部打款']));
        $before = Db::table('wallet_entries')->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all();
        $result = $this->listing($member);
        self::assertSame(7, $result['total']);
        self::assertSame([0, -200, 100, -100, 600, -1200, 1500], array_column($result['data'], 'amount_minor'));
        self::assertSame(700, $result['data'][0]['available_after_minor']);
        self::assertSame(array_sum(array_column($result['data'], 'amount_minor')), $ledger->balance($member)['available_minor']);
        self::assertSame(0, $ledger->balance($member)['frozen_minor']);
        self::assertSame('withdrawal_payout', $result['data'][0]['entry_type']);
        self::assertSame('ORDER-TEST', $result['data'][5]['business_no']);
        foreach ($result['data'] as $row) {
            self::assertNotNull($row['business_no']);
            self::assertSame(['entry_no','entry_type','amount_minor','available_after_minor','currency','business_type','business_no','created_at'], array_keys($row));
        }
        self::assertStringNotContainsString('PRIVATE-', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame($before, Db::table('wallet_entries')->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all());
    }

    public function testBothLedgerAndSourceOwnershipAreScopedAndEmptyReadNeverCreatesAccount(): void
    {
        $a = $this->member(); $b = $this->member('13800138002');
        self::assertSame(['data' => [], 'total' => 0, 'page' => 1, 'page_size' => 20], $this->listing($a));
        self::assertSame(0, Db::table('wallet_accounts')->count());
        $finance = $this->finance();
        $finance->create($this->actor(), 'recharges', $this->input(['member_id' => $a, 'amount_minor' => 100, 'external_reference' => 'A']));
        $rechargeB = $finance->create($this->actor(), 'recharges', $this->input(['member_id' => $b, 'amount_minor' => 999, 'external_reference' => 'B']));
        $accountA = (int) Db::table('wallet_accounts')->where('member_id', $a)->value('id');
        $accountB = (int) Db::table('wallet_accounts')->where('member_id', $b)->value('id');
        $this->row('wallet_entries', ['entry_no' => 'LEGACY-WRONG-SOURCE', 'account_id' => $accountA, 'member_id' => $a,
            'entry_type' => 'legacy', 'source_type' => 'recharge', 'source_id' => $rechargeB['id'], 'amount_minor' => 0, 'available_after_minor' => 100, 'created_at' => '2026-10-19 00:00:00']);
        $this->row('wallet_entries', ['entry_no' => 'WRONG-ACCOUNT', 'account_id' => $accountB, 'member_id' => $a,
            'entry_type' => 'legacy', 'source_type' => 'legacy', 'source_id' => 999, 'amount_minor' => 0, 'available_after_minor' => 999, 'created_at' => '2026-10-19 00:00:00']);
        $result = $this->listing($a);
        self::assertSame(2, $result['total']);
        self::assertNull($result['data'][0]['business_no']);
        self::assertNotContains('WRONG-ACCOUNT', array_column($result['data'], 'entry_no'));
        self::assertSame(1, $this->listing($b)['total']);
    }

    public function testStablePagingWithSameTimestampsAndBoundedStrictInput(): void
    {
        $member = $this->member();
        $account = $this->row('wallet_accounts', ['member_id' => $member, 'currency' => 'CNY', 'fund_type' => 'recharge_cash', 'available_minor' => 30, 'created_at' => '2026-10-18 12:00:00', 'updated_at' => '2026-10-18 12:00:00']);
        for ($i = 1; $i <= 25; $i++) $this->row('wallet_entries', ['entry_no' => 'WL-TEST-' . $i, 'account_id' => $account, 'member_id' => $member,
            'entry_type' => 'legacy', 'source_type' => 'legacy', 'source_id' => $i, 'amount_minor' => 1, 'available_after_minor' => $i, 'created_at' => '2026-10-18 12:00:00']);
        $one = $this->listing($member); $two = $this->listing($member, 2);
        self::assertCount(20, $one['data']); self::assertCount(5, $two['data']);
        self::assertSame('WL-TEST-25', $one['data'][0]['entry_no']); self::assertSame('WL-TEST-5', $two['data'][0]['entry_no']);
        self::assertSame([], array_intersect(array_column($one['data'], 'entry_no'), array_column($two['data'], 'entry_no')));
        self::assertSame('other', $one['data'][0]['business_type']); self::assertNull($one['data'][0]['business_no']);
        self::assertSame(25, $this->listing($member, 100000)['total']); self::assertSame([], $this->listing($member, 100000)['data']);
        self::assertSame(['page' => 1, 'page_size' => 20], MemberWalletEntriesValidator::page([]));
        foreach ([['member_id' => $member], ['account_id' => $account], ['sort_by' => 'id'], ['page' => 0], ['page' => '1 OR 1=1'], ['page' => 100001], ['page_size' => 1000], ['page_size' => []]] as $input) {
            try { MemberWalletEntriesValidator::page($input); self::fail('非法查询应被拒绝'); }
            catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); self::assertArrayHasKey('errors', $error->data()); }
        }
    }

    public function testControllerUsesRequestIdentityAndReturnsUncachedEnvelope(): void
    {
        $member = $this->member(); $context = new MemberAuthenticationContext();
        $request = new Request("GET /api/console/v1/wallet/entries?page=1&page_size=20 HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $context->set($request, new MemberAccount($member, '13800138001', '本人', 'private-hash', 1, '123456789'));
        $controller = new MemberWalletEntryController(new ListMemberWalletEntriesService(new DatabaseMemberWalletEntryRepository()), $context, new ApiResponse());
        $response = $controller->listing($request); $body = json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $response->getStatusCode()); self::assertSame(0, $body['code']);
        self::assertSame('no-store, private', $response->getHeader('Cache-Control'));
        self::assertSame($body['request_id'], $response->getHeader('X-Request-Id'));
        $this->expectException(BusinessException::class);
        $controller->listing(new Request("GET /api/console/v1/wallet/entries HTTP/1.1\r\nHost: localhost\r\n\r\n"));
    }
}
