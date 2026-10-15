<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\access\contract\AdminAccessRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\compute\application\ManageComputeResourcesService;
use app\modules\compute\domain\{ComputeResources, ComputeTypeHandler};
use app\modules\compute\infrastructure\DatabaseComputeResourceRepository;
use app\modules\compute\validation\ComputeResourceValidator;
use app\modules\product\application\ManageCloudProductsService;
use app\modules\product\infrastructure\{DatabaseProductRepository, ProductTypeRegistry, DatabaseCloudDeliveryRuleRepository};
use app\modules\product\validation\CloudOfferValidator;
use app\modules\provider\application\ResolveProviderBindingService;
use app\modules\provider\contract\ProviderBindingWriterInterface;
use app\modules\provider\infrastructure\{DatabaseProviderRepository, DatabaseProviderBindingWriter, LocalProviderAdapterRegistry};
use app\shared\infrastructure\{DatabaseTransactionManager, SodiumSensitiveDataCipher};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use support\Db;
use support\validation\ValidationException;
use tests\support\IsolatedBusinessDatabase;

/** 真实迁移、模型、事务、权限及配置联动；不调用外部生产系统。 */
final class CloudProductAdministrationTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testDraftWhitelistAndEmptyResourcesRemainEditable(): void
    {
        $v = (new CloudOfferValidator())->validate(['name' => '云服务器', 'code' => 'cloud-1', 'password' => 'discard',
            'draft' => ['cycles' => ['year'], 'unknown' => 'discard', 'resource_ids' => []]]);
        self::assertSame(['year'], $v['draft']['cycles']);
        self::assertSame([], $v['draft']['resource_ids']['regions']);
        self::assertSame(0, $v['draft']['system_disk_included_gib']);
        self::assertArrayNotHasKey('unknown', $v['draft']);
        self::assertArrayNotHasKey('password', $v);
        $repo = new DatabaseProductRepository(new DatabaseTransactionManager(), new DatabaseProviderBindingWriter());
        $row = $repo->save(null, $v);
        self::assertSame(1, $row['revision']);
        self::assertSame(0, $row['status']);
        self::assertSame(['year'], $row['draft']['cycles']);
    }

    public function testPriceIncludesDisksNetworkSetupAndQuantity(): void
    {
        [$service, $actor, $offer, $selection] = $this->fixture();
        $price = $service->preview($actor, $offer['id'], $selection, true);
        self::assertSame(11000, $price['unit_amount_minor']);
        self::assertSame(22000, $price['amount_minor']);
        self::assertSame('CNY', $price['currency']);
        self::assertCount(6, $price['unit_breakdown']);
    }

    /** @return array<string, array{int, int, int}> 完整容量、计费容量、每台应付金额分。 */
    public static function includedDiskCases(): array
    {
        return ['未用完赠送容量' => [20, 0, 9000], '恰好用完赠送容量' => [40, 0, 9000], '超出赠送容量' => [60, 20, 11000]];
    }

    #[DataProvider('includedDiskCases')]
    public function testSystemDiskAllowanceAppliesPerInstanceAndPreservesDelivery(int $size, int $billable, int $unitAmount): void
    {
        [$service, $actor, $offer, $selection] = $this->fixture();
        $input = (new CloudOfferValidator())->validate($offer);
        $input['draft']['system_disk_included_gib'] = 40;
        $service->save($actor, $offer['id'], $input);
        $selection['system_disk']['size_gib'] = $size;
        // 请求中的伪造赠送政策不能覆盖服务端销售方案。
        $selection['system_disk_included_gib'] = 65536;
        $price = $service->preview($actor, $offer['id'], $selection, true);
        $parts = array_column($price['unit_breakdown'], null, 'key');
        self::assertSame($billable, $parts['system_disk']['quantity']);
        self::assertSame($billable * 100, $parts['system_disk']['amount_minor']);
        self::assertSame(3000, $parts['data_disks.0']['amount_minor']);
        self::assertSame($unitAmount, $price['unit_amount_minor']);
        self::assertSame($unitAmount * 2, $price['amount_minor']);
        self::assertSame($size, $price['configuration']['system_disk']['size_gib']);
        self::assertArrayNotHasKey('system_disk_included_gib', $price['configuration']);
        self::assertSame(40, $service->preview($actor, $offer['id'], $selection, false)['system_disk_included_gib']);
        $delivery = $service->deliveryPreview($actor, $offer['id'], $selection);
        self::assertSame($size, $delivery['configuration']['system_disk']['size_gib']);
        self::assertSame(30, $delivery['configuration']['data_disks'][0]['size_gib']);
    }

    public function testSystemDiskAllowanceIsSealedWithSalesVersion(): void
    {
        [$service, $actor, $offer, $selection] = $this->fixture();
        $input = (new CloudOfferValidator())->validate($offer);
        $input['draft']['system_disk_included_gib'] = 40;
        $saved = $service->save($actor, $offer['id'], $input);
        $published = $service->publish($actor, $offer['id'], $saved['revision']);
        $version = $service->versions($actor, $offer['id'])[0];
        self::assertSame('1.3.0', $version['type_version']);
        self::assertSame(40, $version['snapshot']['system_disk_included_gib']);
        $input = (new CloudOfferValidator())->validate($published);
        $input['draft']['system_disk_included_gib'] = 0;
        $saved = $service->save($actor, $offer['id'], $input);
        $service->publish($actor, $offer['id'], $saved['revision']);
        self::assertSame(22000, $service->preview($actor, $offer['id'], $selection, true)['amount_minor']);
        self::assertSame(18000, $service->preview($actor, $offer['id'], $selection, true, $version['id'])['amount_minor']);
        $historical = array_values(array_filter($service->versions($actor, $offer['id']), static fn (array $row): bool => $row['id'] === $version['id']))[0];
        self::assertSame($version['snapshot_hash'], $historical['snapshot_hash']);
        self::assertSame($version['snapshot'], $historical['snapshot']);
    }

    public function testLegacyVersionWithoutAllowanceKeepsOriginalPrice(): void
    {
        [$service, $actor, $offer, $selection, $resources] = $this->fixture();
        $all = [];
        foreach (ComputeResources::KINDS as $kind) $all[$kind] = $resources->list($kind);
        $snapshot = (new ComputeTypeHandler())->snapshot($offer['draft'], $all);
        unset($snapshot['system_disk_included_gib']);
        $repo = new DatabaseProductRepository(new DatabaseTransactionManager(), new DatabaseProviderBindingWriter());
        $published = $repo->publish($offer['id'], $offer['revision'], '1.2.0', $snapshot, [], $actor->id);
        $input = (new CloudOfferValidator())->validate($published);
        $input['draft']['system_disk_included_gib'] = 40;
        $service->save($actor, $offer['id'], $input);
        self::assertSame(22000, $service->preview($actor, $offer['id'], $selection, true, $published['published_version_id'])['amount_minor']);
        self::assertSame(0, $service->preview($actor, $offer['id'], $selection, false, $published['published_version_id'])['system_disk_included_gib']);
        self::assertSame(18000, $service->preview($actor, $offer['id'], $selection, true)['amount_minor']);
        self::assertArrayNotHasKey('system_disk_included_gib', $service->versions($actor, $offer['id'])[0]['snapshot']);
    }

    public function testSystemDiskAllowanceAppliesToAllSupportedPrepaidCycles(): void
    {
        [$service, $actor, $offer, $selection, $resources] = $this->fixture();
        $resources->save('flavors', 1, ['prices' => array_fill_keys(ComputeResources::CYCLES, 5000)]);
        $resources->save('disk_types', 1, ['prices' => array_fill_keys(ComputeResources::CYCLES, 100)]);
        $input = (new CloudOfferValidator())->validate($offer);
        $input['draft']['cycles'] = ComputeResources::CYCLES;
        $input['draft']['system_disk_included_gib'] = 40;
        $input['draft']['network']['bandwidth_prices'] = array_fill_keys(ComputeResources::CYCLES, 100);
        $input['draft']['network']['ip_prices'] = array_fill_keys(ComputeResources::CYCLES, 200);
        $service->save($actor, $offer['id'], $input);
        foreach (ComputeResources::CYCLES as $cycle) {
            $selection['billing_cycle'] = $cycle;
            self::assertSame(18000, $service->preview($actor, $offer['id'], $selection, true)['amount_minor'], $cycle);
        }
    }

    /** @return array<string, array{mixed}> 非法赠送容量。 */
    public static function invalidAllowanceCases(): array
    {
        return ['负数' => [-1], '小数' => [0.5], '超出上限' => [65537], '空值' => [null]];
    }

    #[DataProvider('invalidAllowanceCases')]
    public function testInvalidSystemDiskAllowanceCannotBeSaved(mixed $capacity): void
    {
        $this->expectException(ValidationException::class);
        (new CloudOfferValidator())->validate(['name' => '测试', 'code' => 'test', 'draft' => ['system_disk_included_gib' => $capacity]]);
    }

    /** @return array<string, array{int}> 不符合容量、步长或镜像最低容量的系统盘。 */
    public static function invalidFreeDiskCases(): array
    {
        return ['镜像最低容量' => [20], '容量步长' => [41], '存储最大容量' => [210]];
    }

    #[DataProvider('invalidFreeDiskCases')]
    public function testFreeCapacityDoesNotBypassPhysicalDiskConstraints(int $size): void
    {
        [$service, $actor, $offer, $selection, $resources] = $this->fixture();
        $resources->save('images', 1, ['min_disk_gib' => 40]);
        $input = (new CloudOfferValidator())->validate($offer);
        $input['draft']['system_disk_included_gib'] = 65536;
        $service->save($actor, $offer['id'], $input);
        $selection['system_disk']['size_gib'] = $size;
        $this->expectException(BusinessException::class);
        $service->preview($actor, $offer['id'], $selection, true);
    }

    public function testMultipleOffersShareFixedProductAndKeepIndependentDescriptions(): void
    {
        [$service, $actor, $offer] = $this->fixture();
        $second = $service->save($actor, null, (new CloudOfferValidator())->validate(['name' => '企业销售', 'code' => 'enterprise', 'description' => '企业方案描述', 'draft' => ['cycles' => ['month']]]));
        self::assertSame($offer['product_id'], $second['product_id']);
        self::assertSame(1, Db::table('products')->count());
        self::assertSame('云服务器', Db::table('products')->value('name'));
        self::assertSame('企业方案描述', $second['description']);
        self::assertSame('', $service->detail($actor, $offer['id'])['description']);
        self::assertFalse(Db::getSchemaBuilder()->hasColumn('products', 'product_type'));
    }

    public function testNetworkPolicyLimitsPublicationAndHistoryRemainsStable(): void
    {
        [$service, $actor, $offer, $selection, $resources] = $this->fixture();
        $policy = $resources->save('network_policies', null, (new ComputeResourceValidator())->validate('network_policies', [
            'code' => 'standard-network', 'name' => '标准网络', 'status' => 1, 'bandwidth_min' => 1, 'bandwidth_max' => 100,
            'bandwidth_step' => 1, 'ip_min' => 1, 'ip_max' => 1,
        ]));
        $input = (new CloudOfferValidator())->validate($offer);
        $input['draft']['network_policy_id'] = $policy['id'];
        $saved = $service->save($actor, $offer['id'], $input);
        $published = $service->publish($actor, $offer['id'], $saved['revision']);
        $resources->save('network_policies', $policy['id'], ['bandwidth_max' => 10]);
        self::assertSame(22000, $service->preview($actor, $offer['id'], $selection, true, $published['published_version_id'])['amount_minor']);
        $this->expectException(BusinessException::class);
        $service->publish($actor, $offer['id'], $published['revision']);
    }

    public function testDeliveryRuleIsSealedAndDisabledRuleRejectsNewPublication(): void
    {
        [$service, $actor, $offer, $selection, , $connections] = $this->fixture();
        $transactions = new DatabaseTransactionManager();
        $rules = new DatabaseCloudDeliveryRuleRepository(new DatabaseProductRepository($transactions, new DatabaseProviderBindingWriter()), $transactions);
        $rule = $rules->save(null, ['name' => '标准交付', 'description' => '', 'connection_id' => $connections->list()[0]['id'], 'line_ids' => [1], 'priority' => 0, 'status' => 1, 'revision' => 0]);
        $input = (new CloudOfferValidator())->validate($offer);
        $input['draft']['delivery_rule_id'] = $rule['id'];
        $input['draft']['provider_connection_id'] = 0;
        $saved = $service->save($actor, $offer['id'], $input);
        $published = $service->publish($actor, $offer['id'], $saved['revision']);
        self::assertSame($rule['revision'], $service->versions($actor, $offer['id'])[0]['snapshot']['delivery_rule']['revision']);
        $rules->save($rule['id'], array_replace($rule, ['status' => 0]));
        self::assertSame(22000, $service->preview($actor, $offer['id'], $selection, true, $published['published_version_id'])['amount_minor']);
        $this->expectException(BusinessException::class);
        $service->publish($actor, $offer['id'], $published['revision']);
    }

    public function testRuleScopeAndStaleWriteAreRejected(): void
    {
        [$service, $actor, $offer, , , $connections] = $this->fixture();
        $transactions = new DatabaseTransactionManager();
        $rules = new DatabaseCloudDeliveryRuleRepository(new DatabaseProductRepository($transactions, new DatabaseProviderBindingWriter()), $transactions);
        $rule = $rules->save(null, ['name' => '其他线路', 'description' => '', 'connection_id' => $connections->list()[0]['id'], 'line_ids' => [999], 'priority' => 0, 'status' => 1, 'revision' => 0]);
        $rules->save($rule['id'], $rule);
        try { $rules->save($rule['id'], $rule); self::fail('过期规则不应写入'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        $input = (new CloudOfferValidator())->validate($offer);
        $input['draft']['delivery_rule_id'] = $rule['id'];
        $saved = $service->save($actor, $offer['id'], $input);
        try { $service->publish($actor, $offer['id'], $saved['revision']); self::fail('不应发布规则范围外的线路'); }
        catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        self::assertSame(0, Db::table('offer_versions')->count());
    }

    public function testOverviewAndRulePermissionsDoNotExposeUnrelatedConfiguration(): void
    {
        $transactions = new DatabaseTransactionManager();
        $products = new DatabaseProductRepository($transactions, new DatabaseProviderBindingWriter());
        $rules = new DatabaseCloudDeliveryRuleRepository($products, $transactions);
        $accessRepo = $this->createStub(AdminAccessRepositoryInterface::class);
        $accessRepo->method('grantsForAdmin')->willReturn([['permissions' => ['admin.cloud-servers.view']]]);
        $access = new AdminAccessControlService($accessRepo);
        $actor = new AdminAccount(1, 'viewer', '查看用户', 'hidden', 1, null);
        $overview = new \app\modules\product\application\GetCloudServerWorkspaceService($access, $products, new DatabaseComputeResourceRepository(), $rules);
        self::assertSame([], $overview->get($actor)['resources']);
        self::assertNull($overview->get($actor)['offers']);
        self::assertNull($overview->get($actor)['delivery_rules']);
        $connections = $this->createStub(\app\modules\provider\contract\ProviderRepositoryInterface::class);
        $service = new \app\modules\product\application\ManageCloudDeliveryRulesService($access, $rules, new DatabaseComputeResourceRepository(), $connections);
        $this->expectException(BusinessException::class);
        $service->save($actor, null, ['name' => '越权']);
    }

    public function testRegionChangeInvalidatesZoneAndAllDependentSelections(): void
    {
        [$service, $actor, $offer, $selection] = $this->fixture();
        $selection['region_id'] = 99999;
        $result = $service->preview($actor, $offer['id'], $selection, false);
        self::assertArrayHasKey('region_id', $result['invalid_fields']);
        self::assertArrayHasKey('zone_id', $result['invalid_fields']);
        self::assertSame([], $result['options']['flavors']);
        self::assertArrayNotHasKey('zone_id', $result['configuration']);
    }

    public function testBadDiskCapacityCannotBePriced(): void
    {
        [$service, $actor, $offer, $selection] = $this->fixture();
        $selection['system_disk']['size_gib'] = 21;
        $this->expectException(BusinessException::class);
        $service->preview($actor, $offer['id'], $selection, true);
    }

    public function testDiskQuantityLimitCannotBeBypassed(): void
    {
        [$service, $actor, $offer, $selection] = $this->fixture();
        $selection['data_disks'] = array_fill(0, 3, $selection['data_disks'][0]);
        $this->expectException(BusinessException::class);
        $service->preview($actor, $offer['id'], $selection, true);
    }

    public function testPublishIsIdempotentAndHistoricalPreviewDoesNotReadChangedResources(): void
    {
        [$service, $actor, $offer, $selection, $resources] = $this->fixture();
        $published = $service->publish($actor, $offer['id'], $offer['revision']);
        self::assertSame(0, $published['status']);
        $again = $service->publish($actor, $offer['id'], $published['revision']);
        self::assertSame($published['published_version_id'], $again['published_version_id']);
        self::assertSame(1, Db::table('offer_versions')->count());
        $flavor = $resources->list('flavors')[0];
        $resources->save('flavors', $flavor['id'], ['prices' => ['month' => 99999], 'status' => 0]);
        $historical = $service->preview($actor, $offer['id'], $selection, true, $published['published_version_id']);
        self::assertSame(22000, $historical['amount_minor']);
        $this->expectException(BusinessException::class);
        $service->preview($actor, $offer['id'], $selection, true);
    }

    public function testStaleDraftWriteDoesNotModifyProduct(): void
    {
        [$service, $actor, $offer] = $this->fixture();
        $data = (new CloudOfferValidator())->validate($offer);
        $data['name'] = '第一次修改';
        $service->save($actor, $offer['id'], $data);
        try { $data['name'] = '过期修改'; $service->save($actor, $offer['id'], $data); self::fail('应拒绝过期版本'); }
        catch (BusinessException $e) { self::assertSame(409, $e->httpStatus()); }
        self::assertSame('第一次修改', $service->detail($actor, $offer['id'])['name']);
    }

    public function testMissingMappingPreventsPublishWithoutPartialRecords(): void
    {
        [$service, $actor, $offer] = $this->fixture();
        Db::table('provider_resource_mappings')->where('resource_kind', 'images')->delete();
        try { $service->publish($actor, $offer['id'], $offer['revision']); self::fail('应拒绝缺失映射'); }
        catch (BusinessException) { self::assertSame(0, Db::table('offer_versions')->count()); }
        self::assertSame(0, Db::table('provider_bindings')->count());
        self::assertSame($offer['revision'], $service->detail($actor, $offer['id'])['revision']);
    }

    public function testUnknownHistoricalContractIsNotSilentlyInterpreted(): void
    {
        [$service, $actor, $offer, $selection] = $this->fixture();
        $published = $service->publish($actor, $offer['id'], $offer['revision']);
        Db::table('offer_versions')->where('id', $published['published_version_id'])->update(['type_version' => '99.0.0']);
        $this->expectException(BusinessException::class);
        $service->preview($actor, $offer['id'], $selection, true, $published['published_version_id']);
    }

    public function testBindingWriteFailureRollsBackVersionAndOffer(): void
    {
        [, , $offer] = $this->fixture();
        $writer = $this->createMock(ProviderBindingWriterInterface::class);
        $writer->expects(self::once())->method('write')->willThrowException(new \RuntimeException('测试绑定写入失败'));
        $products = new DatabaseProductRepository(new DatabaseTransactionManager(), $writer);
        try {
            $products->publish($offer['id'], $offer['revision'], '1.0.0', ['resources' => []], [], 1);
            self::fail('绑定失败必须回滚');
        } catch (\RuntimeException $error) {
            self::assertSame('测试绑定写入失败', $error->getMessage());
        }
        self::assertSame(0, Db::table('offer_versions')->count());
        self::assertSame(0, Db::table('provider_bindings')->count());
        self::assertNull($products->find($offer['id'])['published_version_id']);
        self::assertSame($offer['revision'], $products->find($offer['id'])['revision']);
    }

    public function testLargeResourceSnapshotPublishesWithoutTruncation(): void
    {
        [$service, $actor, $offer, , $resources] = $this->fixture();
        $input = array_intersect_key($offer, array_flip(['name', 'code', 'description', 'revision', 'draft']));
        $flavor = $resources->list('flavors')[0];
        for ($index = 1; $index <= 20; $index++) {
            $row = $resources->save('flavors', null, array_replace($flavor, [
                'code' => 'large-spec-' . $index, 'name' => '大目录规格 ' . $index,
                'description' => str_repeat('规格描述', 250),
            ]));
            $input['draft']['resource_ids']['flavors'][] = $row['id'];
        }
        $saved = $service->save($actor, $offer['id'], (new CloudOfferValidator())->validate($input));
        $published = $service->publish($actor, $saved['id'], $saved['revision']);
        $snapshot = $service->versions($actor, $published['id'])[0]['snapshot'];
        self::assertGreaterThan(65535, strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)));
        self::assertCount(21, $snapshot['resources']['flavors']);
        self::assertSame(str_repeat('规格描述', 250), $snapshot['resources']['flavors'][1]['description']);
    }

    public function testConnectionSecretsAreEncryptedAndChangesInvalidateMappings(): void
    {
        [, , , , , $connections] = $this->fixture();
        $row = $connections->list()[0];
        self::assertArrayNotHasKey('password', $row);
        self::assertTrue($row['has_password']);
        self::assertStringNotContainsString('fixture-secret', (string) Db::table('provider_connections')->value('credentials_encrypted'));
        $new = $connections->save($row['id'], array_replace($row, ['configuration' => array_replace($connections->credentials($row['id']), ['password' => 'changed-secret']), 'status' => 0]));
        self::assertNull($new['tested_at']);
        self::assertSame([], $connections->mappings($row['id']));
        self::assertSame([], $connections->catalogEntries($row['id']));
    }

    public function testUnconfirmedExternalIdentifierCannotBeMapped(): void
    {
        [, , , , $resources, $connections] = $this->fixture();
        $this->expectException(BusinessException::class);
        $connections->map($connections->list()[0]['id'], 'images', $resources->list('images')[0]['id'], 'not-in-catalog', 'images');
    }

    public function testViewOnlyAndNoPermissionActorsCannotWrite(): void
    {
        $repo = $this->createStub(AdminAccessRepositoryInterface::class);
        $repo->method('grantsForAdmin')->willReturn([['permissions' => ['admin.compute-resources.view']]]);
        $service = new ManageComputeResourcesService(new AdminAccessControlService($repo), new DatabaseComputeResourceRepository());
        $actor = new AdminAccount(1, 'viewer', '查看用户', 'hidden', 1, null);
        self::assertCount(10, $service->all($actor));
        try { $service->save($actor, 'regions', null, ['code' => 'deny', 'name' => '禁止', 'status' => 1]); self::fail('应拒绝写入'); }
        catch (BusinessException $e) { self::assertSame(403, $e->httpStatus()); }
        self::assertSame(0, Db::table('compute_regions')->count());
    }

    public function testUnknownResourceCannotBeSelectedForPublication(): void
    {
        [$service, $actor, $offer] = $this->fixture();
        $data = (new CloudOfferValidator())->validate($offer);
        $data['draft']['resource_ids']['images'] = [999999];
        $saved = $service->save($actor, $offer['id'], $data);
        $this->expectException(BusinessException::class);
        $service->publish($actor, $saved['id'], $saved['revision']);
    }

    public function testGraduatedAndVolumeBandwidthPricingAndHistoricalPrice(): void
    {
        [$service, $actor, $offer, $selection] = $this->fixture();
        $input = (new CloudOfferValidator())->validate($offer);
        $input['draft']['network']['bandwidth_billing_mode'] = 'graduated';
        $input['draft']['network']['bandwidth_tiers'] = [['up_to_mbps' => 5, 'prices' => ['month' => 100]], ['up_to_mbps' => null, 'prices' => ['month' => 80]]];
        $saved = $service->save($actor, $offer['id'], $input);
        $selection['network']['bandwidth_mbps'] = 10;
        self::assertSame(22800, $service->preview($actor, $offer['id'], $selection, true)['amount_minor']);
        $published = $service->publish($actor, $offer['id'], $saved['revision']);
        $input['revision'] = $published['revision'];
        $input['draft']['network']['bandwidth_billing_mode'] = 'volume';
        $service->save($actor, $offer['id'], $input);
        self::assertSame(22600, $service->preview($actor, $offer['id'], $selection, true)['amount_minor']);
        self::assertSame(22800, $service->preview($actor, $offer['id'], $selection, true, $published['published_version_id'])['amount_minor']);
        $selection['network']['bandwidth_mbps'] = 5;
        self::assertSame(22000, $service->preview($actor, $offer['id'], $selection, true)['amount_minor']);
    }

    public function testIncompleteAndOverlappingBandwidthTiersRejectPublication(): void
    {
        [$service, $actor, $offer] = $this->fixture();
        foreach ([[['up_to_mbps' => 5, 'prices' => ['month' => 100]]], [['up_to_mbps' => 5, 'prices' => ['month' => 100]], ['up_to_mbps' => 5, 'prices' => ['month' => 80]]], [['up_to_mbps' => null, 'prices' => ['year' => 100]]]] as $tiers) {
            $input = (new CloudOfferValidator())->validate($offer);
            $input['draft']['network']['bandwidth_billing_mode'] = 'graduated';
            $input['draft']['network']['bandwidth_tiers'] = $tiers;
            $offer = $service->save($actor, $offer['id'], $input);
            try { $service->publish($actor, $offer['id'], $offer['revision']); self::fail('不完整价格不能发布'); }
            catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        }
        self::assertSame(0, Db::table('offer_versions')->count());
    }

    public function testImageMinimumCpuAndMemoryAreAppliedToOptionsAndPrice(): void
    {
        [$service, $actor, $offer, $selection, $resources] = $this->fixture();
        $image = $resources->save('images', null, (new ComputeResourceValidator())->validate('images', [
            'code' => 'large-image', 'name' => '高配置镜像', 'status' => 1, 'zone_ids' => [1], 'type_id' => 1, 'architecture' => 'x86_64',
            'min_cpu' => 4, 'min_memory_mib' => 4096, 'min_disk_gib' => 40, 'login_methods' => ['password'],
        ]));
        $input = (new CloudOfferValidator())->validate($offer); $input['draft']['resource_ids']['images'][] = $image['id'];
        $service->save($actor, $offer['id'], $input);
        $options = $service->preview($actor, $offer['id'], $selection, false);
        self::assertSame([1], array_column($options['options']['images'], 'id'));
        $selection['image_id'] = $image['id'];
        try { $service->preview($actor, $offer['id'], $selection, true); self::fail('不能购买不满足最低要求的镜像'); }
        catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        $resources->save('flavors', 1, ['cpu' => 4]);
        self::assertSame([1], array_column($service->preview($actor, $offer['id'], $selection, false)['options']['images'], 'id'));
        $resources->save('flavors', 1, ['memory_mib' => 4096]);
        self::assertSame([1, $image['id']], array_column($service->preview($actor, $offer['id'], $selection, false)['options']['images'], 'id'));
        $selection['system_disk']['size_gib'] = 40;
        self::assertSame(26000, $service->preview($actor, $offer['id'], $selection, true)['amount_minor']);
    }

    public function testHardwareParametersAreTypedAndSensitiveInputsAreDiscarded(): void
    {
        [, , , , $resources] = $this->fixture();
        $validator = new ComputeResourceValidator();
        $group = $resources->save('flavor_groups', 1, $validator->validate('flavor_groups', ['code' => 'flavor_groups', 'name' => 'AMD', 'status' => 1, 'cpu_model' => 'AMD EPYC', 'base_clock_mhz' => '3100', 'boost_clock_mhz' => '3800', 'password' => 'discard']));
        self::assertSame(3100, $group['base_clock_mhz']); self::assertSame(3800, $group['boost_clock_mhz']);
        self::assertArrayNotHasKey('password', $group);
        $disk = $resources->list('disk_types')[0];
        $disk['read_mbps'] = 1000; $disk['write_mbps'] = 800; $disk['read_iops'] = 10000; $disk['write_iops'] = null;
        $saved = $resources->save('disk_types', 1, $validator->validate('disk_types', $disk));
        self::assertSame(1000, $saved['read_mbps']); self::assertNull($saved['write_iops']);
        self::assertSame([1], $saved['zone_ids']);
    }

    public function testSharedResourcesUseDifferentSupplierMappingsPerZoneAndSealHistory(): void
    {
        [$service, $actor, $offer, $selection, $resources, $connections] = $this->fixture();
        $zone = $resources->save('zones', null, (new ComputeResourceValidator())->validate('zones', ['code' => 'zone-b', 'name' => '第二可用区', 'status' => 1, 'region_id' => 1]));
        foreach (['lines', 'flavors', 'images', 'disk_types'] as $kind) $resources->save($kind, 1, ['zone_ids' => [1, $zone['id']]]);
        $conn = $connections->list()[0];
        foreach (['zones' => 'areas', 'flavor_groups' => 'nodes', 'images' => 'images', 'disk_types' => 'stores', 'lines' => 'ip_groups'] as $kind => $catalog) {
            $connections->cacheCatalog($conn['id'], $catalog, $conn['revision'], [['external_id' => '2', 'name' => '第二资源', 'metadata' => ['area' => 2]]]);
            $connections->map($conn['id'], $kind, $kind === 'zones' ? $zone['id'] : 1, '2', $catalog, $kind === 'zones' ? 0 : $zone['id']);
        }
        $input = (new CloudOfferValidator())->validate($offer); $input['draft']['resource_ids']['zones'][] = $zone['id'];
        $saved = $service->save($actor, $offer['id'], $input);
        $selection['zone_id'] = $zone['id'];
        self::assertSame(22000, $service->preview($actor, $offer['id'], $selection, true)['amount_minor']);
        $preview = $service->deliveryPreview($actor, $offer['id'], $selection);
        self::assertSame('2', $preview['configuration']['flavor_group']['external_id']);
        self::assertSame('2', $preview['configuration']['image']['external_id']);
        self::assertSame(2, $preview['configuration']['flavor']['cpu']);
        self::assertSame(2048, $preview['configuration']['flavor']['memory_mib']);
        self::assertSame(30, $preview['configuration']['data_disks'][0]['size_gib']);
        self::assertSame(5, $preview['configuration']['network']['bandwidth_mbps']);
        self::assertSame(1, $preview['configuration']['network']['ipv4_count']);
        self::assertStringNotContainsString('fixture-secret', json_encode($preview, JSON_THROW_ON_ERROR));
        $published = $service->publish($actor, $offer['id'], $saved['revision']);
        $connections->cacheCatalog($conn['id'], 'nodes', $conn['revision'], [['external_id' => '3', 'name' => '新节点', 'metadata' => ['area' => 2]]]);
        $connections->map($conn['id'], 'flavor_groups', 1, '3', 'nodes', $zone['id']);
        self::assertSame('3', $service->deliveryPreview($actor, $offer['id'], $selection)['configuration']['flavor_group']['external_id']);
        self::assertSame('2', $service->deliveryPreview($actor, $offer['id'], $selection, $published['published_version_id'])['configuration']['flavor_group']['external_id']);
        self::assertSame([1, 2], $resources->find('images', 1)['zone_ids']);
    }

    public function testSharedResourcesCannotReuseWrongZoneSupplierMappings(): void
    {
        [$service, $actor, $offer, $selection, $resources, $connections] = $this->fixture();
        $zone = $resources->save('zones', null, (new ComputeResourceValidator())->validate('zones', ['code' => 'zone-b', 'name' => '第二可用区', 'status' => 1, 'region_id' => 1]));
        foreach (['lines', 'flavors', 'images', 'disk_types'] as $kind) $resources->save($kind, 1, ['zone_ids' => [1, $zone['id']]]);
        $conn = $connections->list()[0];
        $connections->cacheCatalog($conn['id'], 'areas', $conn['revision'], [['external_id' => '2', 'name' => '第二可用区', 'metadata' => []]]);
        $connections->map($conn['id'], 'zones', $zone['id'], '2', 'areas');
        $input = (new CloudOfferValidator())->validate($offer);
        $input['draft']['resource_ids']['zones'][] = $zone['id'];
        $saved = $service->save($actor, $offer['id'], $input);
        $selection['zone_id'] = $zone['id'];
        try { $service->deliveryPreview($actor, $offer['id'], $selection); self::fail('不能复用其他外部可用区的默认对应'); }
        catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        try { $service->publish($actor, $offer['id'], $saved['revision']); self::fail('错误部署关系不能发布'); }
        catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        self::assertSame(0, Db::table('offer_versions')->count());
        self::assertSame(0, Db::table('provider_bindings')->count());
    }

    public function testScopedMappingsRejectUnrelatedZoneAndViewOnlyAccess(): void
    {
        [, $actor, , , $resources, $connections, $adapters] = $this->fixture();
        $zone = $resources->save('zones', null, (new ComputeResourceValidator())->validate('zones', ['code' => 'unrelated', 'name' => '未关联可用区', 'status' => 1, 'region_id' => 1]));
        $conn = $connections->list()[0];
        $accessRepository = $this->createStub(AdminAccessRepositoryInterface::class);
        $accessRepository->method('grantsForAdmin')->willReturn([['permissions' => ['admin.provider-connections.view']]]);
        $service = new \app\modules\provider\application\ManageProviderConnectionsService(new AdminAccessControlService($accessRepository), $connections, $adapters, $resources);
        $count = count($connections->mappings($conn['id']));
        try { $service->map($actor, $conn['id'], 'images', 1, '1', $zone['id']); self::fail('不能为未关联可用区保存对应'); }
        catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        $viewer = new AdminAccount(1, 'viewer', '查看用户', 'hidden', 1, null);
        try { $service->map($viewer, $conn['id'], 'images', 1, '1', 1); self::fail('查看权限不能修改对应'); }
        catch (BusinessException $error) { self::assertSame(403, $error->httpStatus()); }
        self::assertCount($count, $connections->mappings($conn['id']));
    }

    /** @return array<int, mixed> 独立测试库中的完整可配置销售草稿 */
    private function fixture(): array
    {
        $resources = new DatabaseComputeResourceRepository();
        $validator = new ComputeResourceValidator();
        $ids = [];
        foreach (['regions' => [], 'zones' => ['region_id' => 1], 'lines' => ['zone_id' => 1], 'flavor_groups' => [],
            'flavors' => ['zone_id' => 1, 'group_id' => 1, 'cpu' => 2, 'memory_mib' => 2048, 'architecture' => 'x86_64', 'prices' => ['month' => 5000]],
            'image_types' => [], 'images' => ['zone_id' => 1, 'type_id' => 1, 'architecture' => 'x86_64', 'min_disk_gib' => 20, 'login_methods' => ['password']],
            'disk_types' => ['zone_id' => 1, 'min_gib' => 20, 'max_gib' => 200, 'step_gib' => 10, 'max_count' => 2, 'prices' => ['month' => 100]]] as $kind => $fields) {
            $row = $resources->save($kind, null, $validator->validate($kind, ['code' => $kind, 'name' => '测试' . $kind, 'status' => 1] + $fields));
            $ids[$kind] = [$row['id']];
        }
        $transactions = new DatabaseTransactionManager();
        $packages = new \app\modules\plugin\infrastructure\LocalPluginPackageRegistry(dirname(__DIR__, 2) . '/plugins', '0.1.0');
        $installations = new \app\modules\plugin\infrastructure\DatabasePluginInstallationRepository($transactions);
        $record = $installations->all()['magic_cloud'] ?? null;
        if ($record === null) {
            $record = $installations->transition($packages->packages()['magic_cloud'], 'install', 0, null);
            $installations->transition($packages->packages()['magic_cloud'], 'enable', $record['revision'], null);
        }
        $adapters = new LocalProviderAdapterRegistry(dirname(__DIR__, 2) . '/plugins', '0.1.0', new \GuzzleHttp\Client(), $installations, $packages);
        $connections = new DatabaseProviderRepository(new SodiumSensitiveDataCipher(str_repeat('f', 64)), $transactions, $adapters, $installations);
        $conn = $connections->save(null, ['name' => '测试连接', 'adapter_id' => 'magic_cloud', 'configuration' => ['api_url' => 'https://provider.example.test', 'username' => 'fixture', 'password' => 'fixture-secret'], 'status' => 0, 'revision' => 0]);
        $connections->markTested($conn['id'], $conn['revision']);
        $conn = $connections->find($conn['id']);
        $conn = $connections->setStatus($conn['id'], $conn['revision'], 1);
        foreach (['zones' => 'areas', 'flavor_groups' => 'nodes', 'images' => 'images', 'disk_types' => 'stores', 'lines' => 'ip_groups'] as $kind => $remote) {
            $connections->cacheCatalog($conn['id'], $remote, $conn['revision'], [['external_id' => '1', 'name' => '测试', 'metadata' => ['area' => 1]]]);
            $connections->map($conn['id'], $kind, $ids[$kind][0], '1', $remote);
        }
        $adminRepo = $this->createStub(AdminAccessRepositoryInterface::class);
        $service = new ManageCloudProductsService(new AdminAccessControlService($adminRepo), new DatabaseProductRepository($transactions, new DatabaseProviderBindingWriter()),
            new ProductTypeRegistry(new ComputeTypeHandler()), $resources, new ResolveProviderBindingService($connections, $adapters), $connections, $transactions, new DatabaseCloudDeliveryRuleRepository(new DatabaseProductRepository($transactions, new DatabaseProviderBindingWriter()), $transactions), new \app\modules\provider\domain\ProviderProvisioningPreview());
        $actor = new AdminAccount(1, 'fixture-admin', '测试管理员', 'hidden', 1, null, true);
        $input = (new CloudOfferValidator())->validate(['name' => '测试云服务器', 'code' => 'fixture-cloud', 'draft' => ['resource_ids' => $ids, 'provider_connection_id' => $conn['id'],
            'setup_minor' => 300, 'network' => ['bandwidth_prices' => ['month' => 100], 'ip_prices' => ['month' => 200]]]]);
        $offer = $service->save($actor, null, $input);
        $selection = ['region_id' => 1, 'zone_id' => 1, 'line_id' => 1, 'flavor_id' => 1, 'image_id' => 1, 'billing_cycle' => 'month', 'quantity' => 2,
            'system_disk' => ['type_id' => 1, 'size_gib' => 20], 'data_disks' => [['type_id' => 1, 'size_gib' => 30]], 'network' => ['bandwidth_mbps' => 5, 'ipv4_count' => 1]];
        return [$service, $actor, $offer, $selection, $resources, $connections, $adapters];
    }
}
