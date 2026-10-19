<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\compute\domain\ComputeResources;
use app\modules\compute\domain\ComputeTypeHandler;
use app\modules\compute\infrastructure\DatabaseComputeInventoryRepository;
use app\modules\compute\infrastructure\DatabaseComputeResourceRepository;
use app\modules\compute\validation\ComputeResourceValidator;
use app\modules\member\application\MemberAuthenticationContext;
use app\modules\member\domain\MemberAccount;
use app\modules\member\middleware\MemberWriteOriginMiddleware;
use app\modules\order\application\CreatePurchaseOrderService;
use app\modules\order\application\MemberPurchaseOrderService;
use app\modules\order\controller\MemberPurchaseOrderController;
use app\modules\order\infrastructure\DatabaseMemberOrderRepository;
use app\modules\order\infrastructure\DatabaseOrderManagementRepository;
use app\modules\product\application\PublicProductPurchaseService;
use app\modules\product\infrastructure\DatabaseOrderSalesCatalog;
use app\modules\product\infrastructure\ProductTypeRegistry;
use app\modules\product\infrastructure\model\OfferModel;
use app\modules\product\infrastructure\model\OfferVersionModel;
use app\modules\product\infrastructure\model\ProductModel;
use app\modules\compute\validation\CloudOfferValidator;
use app\shared\http\ApiResponse;
use app\shared\infrastructure\DatabaseTransactionManager;
use app\modules\finance\infrastructure\DatabaseOutboxWriter;
use PHPUnit\Framework\TestCase;
use support\{Db, Request};
use tests\support\{IsolatedBusinessDatabase, MessageRuntimeFactory};

/** 独立迁移数据库验证匿名选配、本人下单、幂等和事务，不写开发客户数据库。 */
final class MemberPurchaseWorkflowTest extends TestCase
{
    use IsolatedBusinessDatabase;

    private function publicService(): PublicProductPurchaseService
    {
        return new \app\modules\product\application\PublicProductPurchaseService(new DatabaseOrderSalesCatalog(), new \app\modules\product\infrastructure\ProductTypeRegistry([new ComputeTypeHandler()]),
            \tests\support\ProductRuntimeFactory::purchases(), \tests\support\ProductRuntimeFactory::purchases(), \tests\support\ProductRuntimeFactory::purchases());
    }
    private function service(): MemberPurchaseOrderService
    {
        return new \app\modules\order\application\MemberPurchaseOrderService(new \app\modules\order\application\CreatePurchaseOrderService(new DatabaseOrderManagementRepository(), new DatabaseOrderSalesCatalog(),
            new \app\modules\product\infrastructure\ProductTypeRegistry([new ComputeTypeHandler()]), new DatabaseTransactionManager(), new DatabaseOutboxWriter(), MessageRuntimeFactory::publisher(),
            \tests\support\ProductRuntimeFactory::purchases(), \tests\support\ProductRuntimeFactory::purchases(), new \tests\support\RecordingOrderProvisioning(), \tests\support\ProductRuntimeFactory::purchases()), new DatabaseMemberOrderRepository());
    }
    private function fixture(): array
    {
        $repo = new DatabaseComputeResourceRepository(); $validator = new ComputeResourceValidator(); $ids = [];
        foreach (['regions' => [], 'zones' => ['region_id' => 1],
            'lines' => ['zone_id' => 1, 'network_configuration' => ['egress' => ['min' => 0, 'default' => 5, 'included' => 5], 'ingress' => ['min' => 0, 'default' => 0], 'ip' => ['min' => 1, 'max' => 4, 'default' => 1, 'included' => 1]]],
            'flavor_groups' => [], 'flavors' => ['zone_id' => 1, 'group_id' => 1, 'cpu' => 2, 'memory_mib' => 2048, 'architecture' => 'x86_64', 'prices' => ['month' => 100]],
            'image_types' => [], 'images' => ['zone_id' => 1, 'type_id' => 1, 'architecture' => 'x86_64', 'min_disk_gib' => 20, 'login_methods' => ['password']],
            'disk_types' => ['zone_id' => 1, 'min_gib' => 20, 'max_gib' => 200, 'step_gib' => 10, 'max_count' => 2, 'prices' => ['month' => 1]]] as $kind => $fields) {
            $row = $repo->save($kind, null, $validator->validate($kind, ['code' => $kind, 'name' => $kind, 'status' => 1] + $fields)); $ids[$kind] = [$row['id']];
        }
        $resources = []; foreach (ComputeResources::KINDS as $kind) $resources[$kind] = $repo->list($kind);
        $draft = (new CloudOfferValidator())->validate(['name' => '购买测试', 'code' => 'purchase-test', 'draft' => ['schema_version' => '2.0.0', 'resource_ids' => $ids,
            'storage_policy' => ['default_type_id' => 1, 'default_size_gib' => 20], 'durations' => [['months' => 12, 'label' => '年付', 'discount_kind' => 'rate', 'rate_bps' => 8000]]]])['draft'];
        $snapshot = (new ComputeTypeHandler())->snapshot($draft, $resources) + ['product_name' => '云服务器', 'provider_credentials' => 'must-not-leak'];
        $snapshot['resources']['flavors'][0]['provider_secret'] = 'must-not-leak';
        $offer = OfferModel::query()->create(['product_id' => ProductModel::query()->where('definition_key', 'cloud_server')->value('id'), 'name' => '购买测试', 'code' => 'purchase-test', 'draft' => $draft, 'status' => 1, 'revision' => 1]);
        $version = OfferVersionModel::query()->create(['offer_id' => $offer->id, 'version' => 1, 'type_version' => '2.0.0', 'snapshot' => $snapshot, 'snapshot_hash' => hash('sha256', 'fixture'), 'published_by' => 1]);
        $offer->published_version_id = $version->id; $offer->save();
        (new DatabaseComputeInventoryRepository())->configure(1, 1, 2, 0);
        $selection = ['region_id' => 1, 'zone_id' => 1, 'line_id' => 1, 'flavor_id' => 1, 'image_id' => 1, 'duration_months' => 12, 'quantity' => 2,
            'system_disk' => ['type_id' => 1, 'size_gib' => 20], 'data_disks' => [], 'network' => ['egress_mbps' => 5, 'ingress_mbps' => 0, 'ipv4_count' => 1]];
        $price = $this->publicService()->quote($offer->id, $version->id, $selection);
        return ['offer_id' => $offer->id, 'version_id' => $version->id, 'selection' => $selection, 'expected_amount_minor' => $price['amount_minor'], 'request_key' => bin2hex(random_bytes(16))];
    }

    public function testZoneSpecificPublicQuoteAndOrderSnapshotsStayConsistentAfterRepricing(): void
    {
        $input = $this->fixture();
        $repo = new DatabaseComputeResourceRepository();
        $validator = new ComputeResourceValidator();
        $zone = $repo->save('zones', null, $validator->validate('zones', [
            'code' => 'second-price-zone', 'name' => '第二定价区', 'status' => 1, 'region_id' => 1,
        ]));
        foreach (['lines', 'flavors', 'images', 'disk_types'] as $kind) {
            $row = $repo->find($kind, 1);
            $row['zone_ids'] = [1, $zone['id']];
            if (in_array($kind, ['flavors', 'disk_types'], true)) {
                $row['zone_prices'] = [$zone['id'] => ['month' => $kind === 'flavors' ? 300 : 3]];
            }
            $repo->save($kind, 1, $validator->validate($kind, $row));
        }
        (new DatabaseComputeInventoryRepository())->configure($zone['id'], 1, 2, 0);
        $offer = OfferModel::query()->findOrFail($input['offer_id']);
        $draft = $offer->draft;
        $draft['resource_ids']['zones'][] = $zone['id'];
        $resources = [];
        foreach (ComputeResources::KINDS as $kind) {
            $resources[$kind] = $repo->list($kind);
        }
        $snapshot = (new ComputeTypeHandler())->snapshot($draft, $resources);
        $version = OfferVersionModel::query()->create([
            'offer_id' => $offer->id, 'version' => 2, 'type_version' => '2.0.0', 'snapshot' => $snapshot,
            'snapshot_hash' => hash('sha256', json_encode($snapshot)), 'published_by' => 1,
        ]);
        $offer->published_version_id = $version->id;
        $offer->save();
        $input['version_id'] = $version->id;
        $public = $this->publicService();
        $first = $public->quote($offer->id, $version->id, $input['selection']);
        $input['selection']['zone_id'] = $zone['id'];
        $second = $public->quote($offer->id, $version->id, $input['selection']);
        self::assertSame(2304, $first['amount_minor']);
        self::assertSame(6912, $second['amount_minor']);
        $options = $public->options($offer->id, $version->id, $input['selection']);
        self::assertArrayNotHasKey('zone_prices', $options['options']['flavors'][0]);
        self::assertArrayNotHasKey('prices', $options['options']['disk_types'][0]);
        $member = $this->member();
        $service = $this->service();
        $input['expected_amount_minor'] = $first['amount_minor'];
        try {
            $service->create($member, $input);
            self::fail('不能用第一区价格购买第二区实例');
        } catch (BusinessException $error) {
            self::assertSame(409, $error->httpStatus());
        }
        self::assertSame(0, Db::table('orders')->count());
        $input['expected_amount_minor'] = $second['amount_minor'];
        $id = $service->create($member, $input)['id'];
        $stored = Db::table('order_items')->where('order_id', $id)->value('configuration_snapshot');
        $checkout = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($zone['id'], $checkout['configuration']['zone_id']);
        self::assertSame(6912, $checkout['price']['amount_minor']);
        $parts = array_column($checkout['price']['unit_breakdown'], null, 'key');
        self::assertSame(300, $parts['compute']['unit_price_minor']);
        self::assertSame(3, $parts['system_disk']['unit_price_minor']);
        $repo->save('flavors', 1, ['zone_prices' => [$zone['id'] => ['month' => 900]]]);
        self::assertSame(6912, $public->quote($offer->id, $version->id, $input['selection'])['amount_minor']);
        $resources['flavors'] = $repo->list('flavors');
        $nextSnapshot = (new ComputeTypeHandler())->snapshot($draft, $resources);
        $next = OfferVersionModel::query()->create([
            'offer_id' => $offer->id, 'version' => 3, 'type_version' => '2.0.0', 'snapshot' => $nextSnapshot,
            'snapshot_hash' => hash('sha256', json_encode($nextSnapshot)), 'published_by' => 1,
        ]);
        $offer->published_version_id = $next->id;
        $offer->save();
        self::assertSame(18432, $public->quote($offer->id, $next->id, $input['selection'])['amount_minor']);
        self::assertSame(6912, $service->detail($member, $id)['payable_minor']);
        self::assertSame($stored, Db::table('order_items')->where('order_id', $id)->value('configuration_snapshot'));
        self::assertSame($id, $service->create($member, $input)['id'], '重试返回原订单，不按新价重建');
    }

    public function testAnonymousCatalogOptionsAndQuoteNeverExposeInternalSnapshots(): void
    {
        $input = $this->fixture(); $service = $this->publicService();
        self::assertCount(1, $service->products());
        $options = $service->options($input['offer_id'], $input['version_id'], ['region_id' => 1, 'zone_id' => 1, 'login_password' => 'private']);
        self::assertSame(1, $options['options']['flavors'][0]['id']);
        self::assertStringNotContainsString('must-not-leak', json_encode($options));
        self::assertArrayNotHasKey('login_password', $options['configuration']);
        self::assertSame($input['expected_amount_minor'], $service->quote($input['offer_id'], $input['version_id'], $input['selection'])['amount_minor']);
        self::assertSame(0, Db::table('orders')->count());
        self::assertSame(0, Db::table('compute_inventory_reservations')->count());
        OfferModel::query()->update(['status' => 0]); self::assertSame([], $service->products());
    }
    public function testOwnOrderCreationIsIdempotentAndAllocatesEachInstance(): void
    {
        $input = $this->fixture(); $member = $this->member(); $service = $this->service();
        $id = $service->create($member, $input)['id']; self::assertSame($id, $service->create($member, $input)['id']);
        self::assertSame(1, Db::table('orders')->count()); self::assertSame(2, Db::table('order_item_units')->count());
        self::assertSame($input['expected_amount_minor'], (int) Db::table('order_item_units')->sum('allocated_minor'));
        self::assertSame('pending_payment', $service->detail($member, $id)['status']);
        self::assertSame(0, Db::table('compute_inventory_reservations')->count());
        self::assertSame(1, $service->page($member, 1, 20)['total']);
        // 已成功创建的相同请求在停售后仍返回原订单，避免网络重试生成第二张订单。
        OfferModel::query()->update(['status' => 0]); self::assertSame($id, $service->create($member, $input)['id']);
        $this->expectException(BusinessException::class); $service->create($member, array_replace($input, ['expected_amount_minor' => 1]));
    }
    public function testMemberControllerIgnoresForgedOwnerAndOtherMemberCannotRead(): void
    {
        $input = $this->fixture(); $member = $this->member(); $other = $this->member('13800138002');
        $context = new MemberAuthenticationContext(); $request = $this->request($input + ['member_id' => $other, 'status' => 'paid']);
        $context->set($request, new MemberAccount($member, '13800138001', '会员', 'hash', 1, '728461931'));
        $controller = new MemberPurchaseOrderController($this->service(), $context, new ApiResponse());
        $id = json_decode($controller->create($request)->rawBody(), true)['data']['id'];
        self::assertSame($member, (int) Db::table('orders')->where('id', $id)->value('member_id'));
        $detail = $this->service()->detail($member, $id);
        foreach (['member_id', 'remark', 'configuration_snapshot', 'delivery_binding'] as $field) self::assertArrayNotHasKey($field, $detail);
        self::assertSame(0, $this->service()->page($other, 1, 20)['total']);
        try { $this->service()->detail($other, $id); self::fail('不能读取其他会员订单'); }
        catch (BusinessException $error) { self::assertSame(404, $error->httpStatus()); }
    }
    public function testPriceConflictAndInsufficientStockRollbackAllWrites(): void
    {
        $input = $this->fixture(); $member = $this->member(); $service = $this->service();
        try { $service->create($member, array_replace($input, ['expected_amount_minor' => 1])); self::fail('价格变化必须拒绝'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        self::assertSame(0, Db::table('orders')->count()); self::assertSame(0, Db::table('order_requests')->count());
        (new DatabaseComputeInventoryRepository())->configure(1, 1, 0, 1);
        try { $service->create($member, $input); self::fail('库存不足必须拒绝'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        self::assertSame(0, Db::table('orders')->count()); self::assertSame(0, Db::table('order_requests')->count());
    }
    public function testAnonymousCreateAndCrossOriginWriteAreRejected(): void
    {
        $input = $this->fixture(); $controller = new MemberPurchaseOrderController($this->service(), new MemberAuthenticationContext(), new ApiResponse());
        try { $controller->create($this->request($input)); self::fail('未登录不能下单'); }
        catch (BusinessException $error) { self::assertSame(401, $error->httpStatus()); }
        $request = $this->request($input, 'https://other.test');
        try { (new MemberWriteOriginMiddleware())->process($request, static fn () => response('bad')); self::fail('跨站下单必须拒绝'); }
        catch (BusinessException $error) { self::assertSame(403, $error->httpStatus()); }
        self::assertSame(0, Db::table('orders')->count());
    }
    private function request(array $input, string $origin = 'http://example.test'): Request
    {
        $body = json_encode($input, JSON_THROW_ON_ERROR);
        return new Request("POST /api/console/v1/orders HTTP/1.1\r\nHost: example.test\r\nOrigin: {$origin}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
    }
}
