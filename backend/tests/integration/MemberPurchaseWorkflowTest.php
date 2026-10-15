<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\compute\domain\{ComputeResources, ComputeTypeHandler};
use app\modules\compute\infrastructure\{DatabaseComputeInventoryRepository, DatabaseComputeResourceRepository};
use app\modules\compute\validation\ComputeResourceValidator;
use app\modules\member\application\MemberAuthenticationContext;
use app\modules\member\domain\MemberAccount;
use app\modules\member\middleware\MemberWriteOriginMiddleware;
use app\modules\order\application\{CreatePurchaseOrderService, MemberPurchaseOrderService};
use app\modules\order\controller\MemberPurchaseOrderController;
use app\modules\order\infrastructure\{DatabaseMemberOrderRepository, DatabaseOrderManagementRepository};
use app\modules\product\application\PublicProductPurchaseService;
use app\modules\product\infrastructure\{DatabaseOrderSalesCatalog, ProductTypeRegistry, ProductPurchaseInputRegistry, ProductPurchaseAvailabilityRegistry};
use app\modules\product\infrastructure\model\{OfferModel, OfferVersionModel, ProductModel};
use app\modules\product\validation\CloudOfferValidator;
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
        return new PublicProductPurchaseService(new DatabaseOrderSalesCatalog(), new ProductTypeRegistry(new ComputeTypeHandler()),
            new ProductPurchaseInputRegistry(), new ProductPurchaseAvailabilityRegistry(new DatabaseComputeInventoryRepository()));
    }
    private function service(): MemberPurchaseOrderService
    {
        return new MemberPurchaseOrderService(new CreatePurchaseOrderService(new DatabaseOrderManagementRepository(), new DatabaseOrderSalesCatalog(),
            new ProductTypeRegistry(new ComputeTypeHandler()), new DatabaseTransactionManager(), new DatabaseOutboxWriter(), MessageRuntimeFactory::publisher(),
            new ProductPurchaseInputRegistry(), new ProductPurchaseAvailabilityRegistry(new DatabaseComputeInventoryRepository())), new DatabaseMemberOrderRepository(), new ProductPurchaseInputRegistry());
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
