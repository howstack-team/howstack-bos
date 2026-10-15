<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\admin\access\domain\AdminDataScope;
use app\modules\admin\access\infrastructure\DatabaseAdminAccessRepository;
use app\modules\content\infrastructure\DatabaseContentRepository;
use app\modules\finance\infrastructure\DatabaseFinanceRecordRepository;
use app\modules\member\infrastructure\DatabaseMemberCatalogRepository;
use app\modules\member\infrastructure\DatabaseMemberRepository;
use app\modules\order\infrastructure\DatabaseOrderMemberReference;
use app\modules\order\infrastructure\DatabaseOrderRepository;
use app\modules\support\infrastructure\DatabaseSupportRepository;
use app\modules\product\infrastructure\DatabaseProductRepository;
use app\modules\provider\infrastructure\DatabaseProviderBindingWriter;
use app\shared\infrastructure\DatabaseTransactionManager;
use app\shared\validation\TableListQueryValidator;
use PHPUnit\Framework\TestCase;
use support\Db;
use support\validation\ValidationException;
use tests\support\IsolatedBusinessDatabase;

/** 完整数据集排序、组合过滤、稳定跨页与数据范围；仅使用隔离测试数据库。 */
final class AdminTableSortingTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testMemberSortingIsStableAcrossPagesAndNullsStayLast(): void
    {
        $members = new DatabaseMemberRepository(new DatabaseOrderMemberReference());
        $ids = [];
        foreach ([null, '2026-10-01 08:00:00', '2026-10-01 08:00:00', '2026-10-02 08:00:00'] as $index => $login) {
            $ids[] = $members->save(null, [
                'nickname' => '测试排序', 'mobile' => '1390000000' . $index,
                'status' => 1, 'password_hash' => 'hash', 'last_login_at' => $login,
            ]);
        }
        $filters = ['sort_by' => 'last_login_at', 'sort_order' => 'asc', 'nickname' => '排序'];
        $first = $members->paginate('', 1, 1, 2, $filters);
        $second = $members->paginate('', 1, 2, 2, $filters);
        self::assertSame(4, $first['total']);
        self::assertSame([$ids[2], $ids[1]], array_column($first['data'], 'id'));
        self::assertSame([$ids[3], $ids[0]], array_column($second['data'], 'id'));
        $filters['sort_order'] = 'desc';
        self::assertSame([$ids[3], $ids[2], $ids[1], $ids[0]], array_column($members->paginate('', 1, 1, 10, $filters)['data'], 'id'));
    }

    public function testOrderAmountAndEnumFiltersApplyBeforePagination(): void
    {
        $member = $this->member();
        $first = $this->order($member, 'SORT-A');
        $second = $this->order($member, 'SORT-B');
        $third = $this->order($member, 'SORT-C');
        Db::table('orders')->where('id', $first)->update(['payable_minor' => 9000]);
        Db::table('orders')->where('id', $second)->update(['payable_minor' => 100, 'order_type' => 'renewal']);
        Db::table('orders')->where('id', $third)->update(['payable_minor' => 2000]);
        $orders = new DatabaseOrderRepository();
        $filters = ['sort_by' => 'payable_minor', 'sort_order' => 'asc', 'order_type' => 'purchase', 'payment_status' => 'unpaid'];
        self::assertSame($third, $orders->paginate('', null, 1, 1, $filters)['data'][0]['id']);
        self::assertSame($first, $orders->paginate('', null, 2, 1, $filters)['data'][0]['id']);
        self::assertSame(2, $orders->paginate('', null, 1, 1, $filters)['total']);
        self::assertSame([$third, $second, $first], array_column($orders->paginate('', null, 1, 10, ['sort_by' => 'unknown; DROP TABLE orders'])['data'], 'id'));
    }

    public function testCatalogAndContentUseSelectedOrderRatherThanDefaultOrder(): void
    {
        $catalog = new DatabaseMemberCatalogRepository();
        $first = $catalog->saveGroup(null, ['name' => '甲', 'code' => 'a', 'sort_order' => 1, 'status' => 1]);
        $second = $catalog->saveGroup(null, ['name' => '乙', 'code' => 'b', 'sort_order' => 9, 'status' => 1]);
        self::assertSame([$second, $first], array_column($catalog->groups('', 1, 1, 10, ['sort_by' => 'sort_order', 'sort_order' => 'desc'])['data'], 'id'));
        $support = new DatabaseSupportRepository();
        $support->saveCatalog('ticket_quick_replies', null, ['title' => '低', 'content' => '正文', 'sort_order' => 1, 'status' => 1]);
        $support->saveCatalog('ticket_quick_replies', null, ['title' => '高', 'content' => '正文', 'sort_order' => 9, 'status' => 1]);
        self::assertSame('高', $support->catalog('ticket_quick_replies', '', 1, 1, 10, ['sort_by' => 'sort_order', 'sort_order' => 'desc'])['data'][0]['title']);
        $content = new DatabaseContentRepository(new \app\modules\file\infrastructure\DatabaseFileRegistry());
        $root = $content->save(true, null, ['kind' => 'help', 'name' => '父级', 'status' => 1, 'parent_id' => null, 'sort_order' => 0]);
        foreach ([1, 9] as $order) {
            $content->save(true, null, ['kind' => 'help', 'name' => '子级' . $order, 'status' => 1, 'parent_id' => $root, 'sort_order' => $order]);
        }
        $tree = $content->paginate('help', true, '', null, 1, 10, ['sort_by' => 'sort_order', 'sort_order' => 'desc']);
        self::assertSame(1, $tree['total']);
        self::assertSame(['子级9', '子级1'], array_column($tree['data'][0]['children'], 'name'));
    }

    public function testFinancialSortingKeepsTotalsAndAdministratorScope(): void
    {
        $member = $this->member();
        $this->recharge($member, 9000);
        $this->row('recharges', [
            'recharge_no' => 'SORT-RECHARGE', 'member_id' => $member, 'amount_minor' => 100,
            'currency' => 'CNY', 'channel' => 'wechat', 'status' => 'paid',
            'created_at' => '2026-10-06 08:00:00', 'updated_at' => '2026-10-06 08:00:00',
        ]);
        $finance = new DatabaseFinanceRecordRepository();
        $result = $finance->paginate('recharges', 1, 1, 'paid', ['sort_by' => 'amount_minor', 'sort_order' => 'asc']);
        self::assertSame(2, $result['total']);
        self::assertSame(100, $result['data'][0]['amount_minor']);
        $admins = new DatabaseAdminAccessRepository();
        $this->row('admin_users', [
            'username' => 'outside-scope', 'nickname' => '范围外', 'password_hash' => 'hash', 'status' => 1,
            'last_login_at' => '2026-10-06 08:00:00', 'created_at' => '2026-10-06 08:00:00', 'updated_at' => '2026-10-06 08:00:00',
        ]);
        $list = $admins->administrators(new AdminDataScope(false, true, []), 1, '', 1, 10, ['sort_by' => 'last_login_at', 'sort_order' => 'desc', 'status' => '1']);
        self::assertSame(1, $list['total']);
        self::assertSame(1, $list['data'][0]['id']);
        self::assertArrayNotHasKey('password_hash', $list['data'][0]);
    }

    public function testOfferPublicationAndStatusFiltersShareSortedTotals(): void
    {
        $offers = new DatabaseProductRepository(new DatabaseTransactionManager(), new DatabaseProviderBindingWriter());
        $ids = [];
        foreach (['方案甲', '方案乙', '方案丙'] as $index => $name) {
            $offer = $offers->save(null, [
                'name' => $name, 'code' => 'sorted-offer-' . $index, 'description' => '', 'draft' => [],
            ]);
            $ids[] = $offer['id'];
            Db::table('offers')->where('id', $offer['id'])->update([
                'created_at' => $index === 0 ? '2026-10-01 08:00:00' : '2026-10-02 08:00:00',
            ]);
        }
        $filters = ['sort_by' => 'created_at', 'sort_order' => 'asc', 'status' => '0', 'publication' => 'draft'];
        self::assertSame([$ids[0], $ids[2], $ids[1]], array_column($offers->paginate('', 1, 10, $filters)['data'], 'id'));
        $page = $offers->paginate('', 2, 1, $filters);
        self::assertSame(3, $page['total']);
        self::assertSame($ids[2], $page['data'][0]['id']);
        self::assertSame('2026-10-02 08:00:00', $page['data'][0]['created_at']);
        $filters['publication'] = 'published';
        self::assertSame(0, $offers->paginate('', 1, 10, $filters)['total']);
        $filters['publication'] = 'draft';
        $filters['status'] = '1';
        self::assertSame(0, $offers->paginate('', 1, 10, $filters)['total']);
    }

    public function testSortValidatorRejectsSqlNamesAndDirections(): void
    {
        $validated = TableListQueryValidator::validate(
            ['sort_by' => 'created_at', 'status' => '0'], [], ['created_at'], ['status' => ['0', '1']],
        );
        self::assertSame('0', $validated['status']);
        self::assertSame('created_at', $validated['sort_by']);
        self::assertSame('asc', $validated['sort_order']);
        foreach ([['sort_by' => 'created_at desc; --'], ['sort_by' => ['created_at']], ['sort_order' => 'invalid']] as $input) {
            try {
                TableListQueryValidator::validate($input, [], ['created_at']);
                self::fail('必须拒绝未声明的排序参数');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
}
