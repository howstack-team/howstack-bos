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

/** v2真实迁移、历史隔离、周期整数计价、线路赠送及库存状态；不调用生产供应商。 */
final class PrepaidCloudConfigurationTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testPrepaidPeriodAndPerInstanceBreakdown(): void
    {
        [$service, $actor, $offer, $selection] = $this->fixture();
        $result = $service->preview($actor, $offer['id'], $selection, true);
        self::assertSame(106300, $result['unit_amount_minor']);
        self::assertSame(318900, $result['amount_minor']);
        self::assertSame(63600, $result['discount_minor']);
        self::assertSame(12, $result['configuration']['duration_months']);
        $parts = array_column($result['unit_breakdown'], null, 'key');
        self::assertSame(4000, $parts['network.egress']['amount_minor']);
        self::assertSame(2000, $parts['network.ip']['amount_minor']);
        self::assertSame(300, $parts['setup']['amount_minor']);
        self::assertSame(0, $parts['setup']['discount_minor']);
        self::assertSame(20000, $parts['system_disk']['amount_minor']);
    }

    public function testDiscountRateAndBilledMonthsHaveDifferentServiceAndChargePeriods(): void
    {
        [$service, $actor, $offer, $selection] = $this->fixture();
        $input = (new CloudOfferValidator())->validate($offer);
        $input['draft']['durations'] = [['months' => 12, 'label' => '年付8折', 'discount_kind' => 'rate', 'rate_bps' => 8000]];
        $service->save($actor, $offer['id'], $input);
        self::assertSame(102060, $service->preview($actor, $offer['id'], $selection, true)['unit_amount_minor']);
    }

    public function testPublishedVersionSealsLinePolicyAndCredentialsStayOut(): void
    {
        [$service, $actor, $offer, $selection, $resources] = $this->fixture();
        $published = $service->publish($actor, $offer['id'], $offer['revision']);
        self::assertSame('2.0.0', $service->versions($actor, $offer['id'])[0]['type_version']);
        $line = $resources->find('lines', 1);
        $line['network_configuration']['egress']['included'] = 0;
        $resources->save('lines', 1, $line);
        self::assertGreaterThan(318900, $service->preview($actor, $offer['id'], $selection, true)['amount_minor']);
        $selection['system_disk']['password'] = 'must-not-leak';
        $selection['network']['token'] = 'must-not-leak';
        $selection['password'] = 'must-not-leak';
        $selection['data_disks'][0]['secret'] = 'must-not-leak';
        $price = $service->preview($actor, $offer['id'], $selection, true, $published['published_version_id']);
        self::assertSame(318900, $price['amount_minor']);
        self::assertStringNotContainsString('must-not-leak', json_encode($price));
        $delivery = $service->deliveryPreview($actor, $offer['id'], $selection, $published['published_version_id']);
        self::assertSame(['unit' => 'month', 'count' => 12], $delivery['configuration']['duration']);
        self::assertSame(10, $delivery['configuration']['network']['egress_mbps']);
        self::assertStringNotContainsString('must-not-leak', json_encode($delivery));
    }

    public function testGlobalDiskLimitRejectsMixedTypesAndUnavailableAdvancedModes(): void
    {
        [$service, $actor, $offer, $selection] = $this->fixture();
        foreach (['disks', 'usage', 'traffic', 'key', 'group'] as $case) {
            $bad = $selection;
            if ($case === 'disks') $bad['data_disks'][] = $bad['data_disks'][0];
            if ($case === 'usage') $bad['billing_mode'] = 'usage';
            if ($case === 'traffic') $bad['network']['billing_mode'] = 'traffic';
            if ($case === 'key') $bad['login_method'] = 'ssh_key';
            if ($case === 'group') $bad['flavor_group_id'] = 999;
            try { $service->preview($actor, $offer['id'], $bad, true); self::fail($case . '应拒绝'); }
            catch (BusinessException $e) { self::assertSame(422, $e->httpStatus()); }
        }
    }

    public function testIpOptionsStepAndVolumeGiftUseActualTier(): void
    {
        $calculator = new \app\modules\compute\domain\LineNetworkConfiguration();
        $policy = $calculator->normalize(['egress' => ['min' => 0, 'max' => 20, 'step' => 5, 'default' => 5, 'included' => 5, 'pricing_mode' => 'volume', 'tiers' => [['up_to_mbps' => 5, 'price_minor' => 100], ['up_to_mbps' => null, 'price_minor' => 80]]],
            'ip' => ['mode' => 'options', 'options' => [1, 2, 4], 'included' => 1, 'default' => 1]]);
        self::assertSame(400, $calculator->bandwidthAmount($policy['egress'], 10));
        self::assertSame(0, $calculator->bandwidthAmount($policy['egress'], 5));
        try { $calculator->bandwidthAmount($policy['egress'], 6); self::fail('步长无效'); } catch (BusinessException $e) { self::assertSame(422, $e->httpStatus()); }
        try { $calculator->validateIp($policy['ip'], 3); self::fail('离散值无效'); } catch (BusinessException $e) { self::assertSame(422, $e->httpStatus()); }
    }

    public function testInventoryReservationIdempotencyExpiryAndConfirmedRelease(): void
    {
        $inventory = new \app\modules\compute\infrastructure\DatabaseComputeInventoryRepository();
        $pool = $inventory->configure(1, 1, 3, 0);
        $expires = new \DateTimeImmutable('+10 minutes');
        $inventory->reserve(1, 1, 'ORDER:one', 2, $expires);
        $inventory->reserve(1, 1, 'ORDER:one', 2, $expires);
        self::assertSame(2, $inventory->all()[0]['reserved']);
        try { $inventory->reserve(1, 1, 'ORDER:two', 2, $expires); self::fail('不能超卖'); } catch (BusinessException $e) { self::assertSame(409, $e->httpStatus()); }
        try { $inventory->configure(1, 1, 1, $pool['revision']); self::fail('不能缩小到已用量以下'); } catch (BusinessException $e) { self::assertSame(409, $e->httpStatus()); }
        $inventory->transition('ORDER:one', 'occupied');
        $inventory->transition('ORDER:one', 'occupied');
        self::assertSame(0, $inventory->all()[0]['reserved']);
        self::assertSame(2, $inventory->all()[0]['occupied']);
        try { $inventory->transition('ORDER:one', 'released'); self::fail('未知供应状态不能恢复库存'); } catch (BusinessException $e) { self::assertSame(409, $e->httpStatus()); }
        $inventory->reserve(1, 1, 'ORDER:three', 1, $expires);
        self::assertSame(1, $inventory->releaseExpired(new \DateTimeImmutable('+11 minutes')));
        self::assertSame(2, $inventory->all()[0]['occupied']);
        $inventory->transition('ORDER:one', 'released', true);
        $inventory->transition('ORDER:one', 'released', true);
        self::assertSame(3, $inventory->all()[0]['available']);
    }

    public function testTrialScopeCannotBeBypassedByClientSelection(): void
    {
        [$service, $actor, $offer, $selection, $resources] = $this->fixture();
        $draft = $offer['draft']; $draft['trial_policy']['enabled'] = true;
        $draft['trial_scope']['max_system_disk_gib'] = 40;
        $all = []; foreach (ComputeResources::KINDS as $kind) $all[$kind] = $resources->list($kind);
        $handler = new \app\modules\compute\domain\PrepaidComputeTypeHandler();
        $snapshot = $handler->snapshot($draft, $all);
        $selection['quantity'] = 1;
        try { $handler->validateTrial($snapshot, $selection); self::fail('试用容量限制'); } catch (BusinessException $e) { self::assertSame(422, $e->httpStatus()); }
        $selection['system_disk']['size_gib'] = 40;
        $handler->validateTrial($snapshot, $selection);
        self::assertSame(40, $selection['system_disk']['size_gib']);
    }
    public function testDefaultStorageAndPasswordCompatibilityAreCheckedBeforePublishing(): void
    {
        [$service, $actor, $offer, $selection, $resources] = $this->fixture();
        $input = (new CloudOfferValidator())->validate($offer);
        $input['draft']['storage_policy']['default_size_gib'] = 25;
        $saved = $service->save($actor, $offer['id'], $input);
        try { $service->publish($actor, $offer['id'], $saved['revision']); self::fail('默认容量不符合步长'); }
        catch (BusinessException $e) { self::assertSame(422, $e->httpStatus()); }
        self::assertCount(0, $service->versions($actor, $offer['id']));
        $input = (new CloudOfferValidator())->validate($saved);
        $input['draft']['storage_policy']['default_size_gib'] = 20;
        $saved = $service->save($actor, $offer['id'], $input);
        $image = $resources->find('images', 1); $image['login_methods'] = ['ssh_key'];
        $resources->save('images', 1, $image);
        try { $service->publish($actor, $offer['id'], $saved['revision']); self::fail('当前版本不支持仅密钥镜像'); }
        catch (BusinessException $e) { self::assertSame(422, $e->httpStatus()); }
        self::assertCount(0, $service->versions($actor, $offer['id']));
    }

    public function testLargeNetworkAmountFailsValidationBeforeDiscountOverflow(): void
    {
        [$service, $actor, $offer, $selection, $resources] = $this->fixture();
        $line = $resources->find('lines', 1);
        $line['network_configuration']['egress'] = ['min' => 0, 'max' => 1000000, 'step' => 1, 'default' => 0, 'included' => 0, 'price_minor' => 100000000, 'pricing_mode' => 'flat', 'tiers' => []];
        $resources->save('lines', 1, $line);
        $selection['network']['egress_mbps'] = 1000000;
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('原价金额超过支持范围');
        $service->preview($actor, $offer['id'], $selection, true);
    }

    public function testInventoryWriteRequiresUpdatePermissionAndValidDeployment(): void
    {
        [, $founder, , , $resources] = $this->fixture();
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $access->method('grantsForAdmin')->willReturn([['permissions' => ['admin.compute-resources.view']]]);
        $inventory = new \app\modules\compute\infrastructure\DatabaseComputeInventoryRepository();
        $service = new \app\modules\compute\application\ManageComputeInventoryService(new AdminAccessControlService($access), $inventory, $resources);
        $viewer = new AdminAccount(2, 'viewer', '只读管理员', 'hidden', 1, null, false);
        self::assertSame([], $service->all($viewer));
        try { $service->configure($viewer, ['zone_id' => 1, 'flavor_id' => 1, 'capacity' => 3, 'revision' => 0]); self::fail('只读角色不能改库存'); }
        catch (BusinessException $e) { self::assertSame(403, $e->httpStatus()); }
        try { $service->configure($founder, ['zone_id' => 999, 'flavor_id' => 1, 'capacity' => 3, 'revision' => 0]); self::fail('不存在的部署关系不能配置'); }
        catch (BusinessException $e) { self::assertSame(422, $e->httpStatus()); }
        self::assertSame([], $inventory->all());
    }

    public function testProviderParameterRulesAreSealedInBothVersionAndBinding(): void
    {
        [$service, $actor, $offer, $selection] = $this->fixture();
        $transactions = new DatabaseTransactionManager();
        $rules = new DatabaseCloudDeliveryRuleRepository(new DatabaseProductRepository($transactions, new DatabaseProviderBindingWriter()), $transactions);
        $validator = new \app\modules\product\validation\CloudDeliveryRuleValidator();
        $rule = $rules->save(null, $validator->validate(['name' => '标准参数', 'connection_id' => $offer['draft']['provider_connection_id'], 'status' => 1,
            'parameter_mapping' => [['source' => 'flavor.memory_mib', 'target' => 'instance.memory_gib', 'transform' => 'divide', 'factor' => 1024], ['source' => 'network.egress_mbps', 'target' => 'network.upload']]]));
        $input = (new CloudOfferValidator())->validate($offer);
        $input['draft']['delivery_rule_id'] = $rule['id'];
        $saved = $service->save($actor, $offer['id'], $input);
        $published = $service->publish($actor, $offer['id'], $saved['revision']);
        $versionId = $published['published_version_id'];
        $sealed = json_decode(Db::table('provider_bindings')->where('offer_version_id', $versionId)->value('parameter_mapping'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($rule['parameter_mapping'], $sealed);
        $expected = ['instance' => ['memory_gib' => 2], 'network' => ['upload' => 10]];
        self::assertSame($expected, $service->deliveryPreview($actor, $offer['id'], $selection, $versionId)['provider_parameters']);
        $rule['parameter_mapping'][0]['target'] = 'instance.new_memory';
        $rules->save($rule['id'], $validator->validate($rule));
        self::assertSame($expected, $service->deliveryPreview($actor, $offer['id'], $selection, $versionId)['provider_parameters']);
        self::assertArrayHasKey('new_memory', $service->deliveryPreview($actor, $offer['id'], $selection)['provider_parameters']['instance']);
    }

    public function testHttpPreviewKeepsV2FieldsAndDiscardsNestedCredentials(): void
    {
        [$service, $actor, $offer, $selection] = $this->fixture();
        $context = new \app\modules\admin\auth\application\AdminAuthenticationContext();
        $controller = new \app\modules\product\controller\AdminCloudProductController($service, new CloudOfferValidator(), $context, new \app\shared\http\ApiResponse());
        $selection['duration_months'] = '12';
        $selection['network']['billing_mode'] = 'fixed';
        $selection['network']['egress_mbps'] = '10';
        $selection['system_disk']['password'] = 'secret-not-in-response';
        $selection['password'] = 'secret-not-in-response';
        $body = json_encode(['mode' => 'price', 'selection' => $selection], JSON_THROW_ON_ERROR);
        $request = new \support\Request("POST /api/admin/v1/cloud-products/1/preview HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
        $context->set($request, $actor);
        $response = $controller->preview($request, $offer['id']);
        $data = json_decode($response->rawBody(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame(318900, $data['amount_minor']);
        self::assertSame(12, $data['configuration']['duration_months']);
        self::assertSame(10, $data['configuration']['network']['egress_mbps']);
        self::assertStringNotContainsString('secret-not-in-response', $response->rawBody());
        $selection['network']['billing_mode'] = 'traffic';
        $body = json_encode(['mode' => 'price', 'selection' => $selection], JSON_THROW_ON_ERROR);
        $request = new \support\Request("POST /api/admin/v1/cloud-products/1/preview HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
        $context->set($request, $actor);
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('当前版本仅支持');
        $controller->preview($request, $offer['id']);
    }

    public function testImageMetadataAndIconPathsArePersistedWithoutGuessing(): void
    {
        $validator = new ComputeResourceValidator();
        $resources = new DatabaseComputeResourceRepository();
        $base = ['name' => '图标测试', 'code' => 'icon-test', 'status' => 1];
        foreach (['', '/assets/ubuntu.svg', 'https://images.example.test/windows.svg'] as $icon) {
            $row = $resources->save('image_types', null, $validator->validate('image_types', $base + ['icon_url' => $icon]));
            self::assertSame($icon, $resources->find('image_types', $row['id'])['icon_url']);
            $base['code'] .= 'x';
        }
        $region = $resources->save('regions', null, $validator->validate('regions', ['name' => '地域', 'code' => 'region', 'status' => 1]));
        $zone = $resources->save('zones', null, $validator->validate('zones', ['name' => '可用区', 'code' => 'zone', 'status' => 1, 'region_id' => $region['id']]));
        $data = $validator->validate('images', ['name' => 'Windows', 'code' => 'windows', 'status' => 1, 'zone_id' => $zone['id'], 'type_id' => 1, 'architecture' => 'x86_64', 'min_disk_gib' => 40, 'login_methods' => ['password'], 'default_username' => 'Administrator', 'default_port' => 3389]);
        $image = $resources->save('images', null, $data);
        self::assertSame('Administrator', $resources->find('images', $image['id'])['default_username']);
        self::assertSame(3389, $resources->find('images', $image['id'])['default_port']);
        foreach (['//external.example.test/image.png', 'http://images.example.test/icon.png', '/assets/invalid icon.svg', '/'.chr(92).'external.example.test'] as $icon) {
            try { $validator->validate('image_types', $base + ['icon_url' => $icon]); self::fail('非法图标地址应拒绝'); }
            catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        }
    }

    public function testMysqlConcurrentReservationsCannotOversell(): void
    {
        if (getenv('HOWSTACK_MYSQL_TEST') !== '1' || !function_exists('pcntl_fork')) self::markTestSkipped('并发库存验证需要独立MySQL测试库及pcntl');
        $inventory = new \app\modules\compute\infrastructure\DatabaseComputeInventoryRepository();
        $inventory->configure(1, 1, 3, 0);
        $children = [];
        for ($i = 0; $i < 12; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) self::fail('无法创建并发测试进程');
            if ($pid === 0) {
                Db::connection()->disconnect();
                try { $inventory->reserve(1, 1, 'parallel:' . $i, 1, new \DateTimeImmutable('+10 minutes')); exit(0); }
                catch (BusinessException $error) { exit($error->httpStatus() === 409 ? 10 : 20); }
                catch (\Throwable) { exit(30); }
            }
            $children[] = $pid;
        }
        $success = 0;
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $code = pcntl_wexitstatus($status);
            self::assertContains($code, [0, 10]);
            if ($code === 0) $success++;
        }
        Db::connection()->disconnect();
        self::assertSame(3, $success);
        self::assertSame(3, $inventory->all()[0]['reserved']);
        self::assertSame(0, $inventory->all()[0]['available']);
        // fork子进程关闭继承的管理连接，清理前建立独立父进程PDO。
        $connection = Db::connection()->getConfig();
        $this->mysqlServer = new \PDO('mysql:host=' . $connection['host'] . ';port=' . $connection['port'], $connection['username'], $connection['password']);
    }

    public function testZoneProvidersAreIndependentAndHistoricalBindingsStaySealed(): void
    {
        [$service, $actor, $offer, $selection, $resources, $connections] = $this->fixture();
        $zone = $resources->save('zones', null, (new ComputeResourceValidator())->validate('zones', ['code' => 'zone-two', 'name' => '第二区', 'status' => 1, 'region_id' => 1]));
        foreach (['lines', 'flavors', 'images', 'disk_types'] as $kind) {
            $row = $resources->find($kind, 1);
            $row['zone_ids'] = [1, $zone['id']];
            $resources->save($kind, 1, $row);
        }
        $second = $connections->save(null, ['name' => '第二提供者', 'adapter_id' => 'magic_cloud', 'configuration' => ['api_url' => 'https://second.example.test', 'username' => 'fixture', 'password' => 'fixture-secret'], 'status' => 0, 'revision' => 0]);
        $connections->markTested($second['id'], $second['revision']);
        $second = $connections->find($second['id']);
        $second = $connections->setStatus($second['id'], $second['revision'], 1);
        foreach (['zones' => 'areas', 'flavor_groups' => 'nodes', 'images' => 'images', 'disk_types' => 'stores', 'lines' => 'ip_groups'] as $kind => $remote) {
            $connections->cacheCatalog($second['id'], $remote, $second['revision'], [['external_id' => '2', 'name' => '第二提供者资源', 'metadata' => ['area' => 2]]]);
            $connections->map($second['id'], $kind, $kind === 'zones' ? $zone['id'] : 1, '2', $remote);
        }
        $input = (new CloudOfferValidator())->validate($offer);
        $input['draft']['resource_ids']['zones'][] = $zone['id'];
        $input['draft']['zone_provider_bindings'] = [
            ['zone_id' => 1, 'connection_id' => $offer['draft']['provider_connection_id'], 'parameter_mapping' => []],
            ['zone_id' => $zone['id'], 'connection_id' => $second['id'], 'parameter_mapping' => [['source' => 'flavor.cpu', 'target' => 'instance.cpu', 'transform' => 'copy', 'factor' => 1, 'lookup' => [], 'required' => true]]],
        ];
        $saved = $service->save($actor, $offer['id'], (new CloudOfferValidator())->validate($input));
        $published = $service->publishAndEnable($actor, $offer['id'], $saved['revision']);
        self::assertSame(1, $published['status']);
        $versionId = $published['published_version_id'];
        self::assertFalse($published['has_unpublished_changes']);
        $selection['zone_id'] = $zone['id'];
        $historical = $service->deliveryPreview($actor, $offer['id'], $selection, $versionId);
        self::assertSame($second['id'], $historical['connection_id']);
        self::assertSame('2', $historical['configuration']['zone']['external_id']);
        self::assertSame(['instance' => ['cpu' => 2]], $historical['provider_parameters']);
        $selection['zone_id'] = 1;
        self::assertSame($offer['draft']['provider_connection_id'], $service->deliveryPreview($actor, $offer['id'], $selection, $versionId)['connection_id']);
        $input = (new CloudOfferValidator())->validate($published);
        $input['draft']['zone_provider_bindings'][1]['parameter_mapping'][0]['target'] = 'instance.changed';
        $changed = $service->save($actor, $offer['id'], $input);
        self::assertTrue($changed['has_unpublished_changes']);
        $selection['zone_id'] = $zone['id'];
        self::assertSame(['instance' => ['cpu' => 2]], $service->deliveryPreview($actor, $offer['id'], $selection, $versionId)['provider_parameters']);
        self::assertSame(['instance' => ['changed' => 2]], $service->deliveryPreview($actor, $offer['id'], $selection)['provider_parameters']);
        self::assertCount(2, json_decode(Db::table('provider_bindings')->where('offer_version_id', $versionId)->value('zone_bindings'), true));
    }

    public function testZoneBindingWhitelistAndDuplicateZoneRejection(): void
    {
        $validator = new CloudOfferValidator();
        $input = ['name' => '配置', 'code' => 'zone-binding', 'draft' => ['schema_version' => '2.0.0', 'zone_provider_bindings' => [['zone_id' => 1, 'connection_id' => 2, 'password' => 'discard']]]];
        $saved = $validator->validate($input);
        self::assertArrayNotHasKey('password', $saved['draft']['zone_provider_bindings'][0]);
        $input['draft']['zone_provider_bindings'][] = $input['draft']['zone_provider_bindings'][0];
        $this->expectException(ValidationException::class);
        $validator->validate($input);
    }

    public function testIncompleteZoneProviderCannotPublishOrEnable(): void
    {
        [$service, $actor, $offer] = $this->fixture();
        $input = (new CloudOfferValidator())->validate($offer);
        $input['draft']['zone_provider_bindings'] = [
            ['zone_id' => 1, 'connection_id' => 0, 'parameter_mapping' => []],
        ];
        $saved = $service->save($actor, $offer['id'], $input);
        try {
            $service->publishAndEnable($actor, $offer['id'], $saved['revision']);
            self::fail('未完成的可用区提供者配置不得发布');
        } catch (BusinessException $error) {
            self::assertSame(422, $error->httpStatus());
        }
        self::assertSame(0, Db::table('offer_versions')->count());
        self::assertSame(0, Db::table('offers')->where('id', $offer['id'])->value('status'));
    }

    public function testPublishAndEnableRequiresBothPermissionsBeforeWriting(): void
    {
        [, , $offer, , $resources, $connections, $adapters] = $this->fixture();
        $access = $this->createStub(AdminAccessRepositoryInterface::class);
        $access->method('grantsForAdmin')->willReturn([['permissions' => ['admin.cloud-products.publish']]]);
        $transactions = new DatabaseTransactionManager();
        $products = new DatabaseProductRepository($transactions, new DatabaseProviderBindingWriter());
        $service = new ManageCloudProductsService(
            new AdminAccessControlService($access),
            $products,
            new ProductTypeRegistry(new ComputeTypeHandler()),
            $resources,
            new ResolveProviderBindingService($connections, $adapters),
            $connections,
            $transactions,
            new DatabaseCloudDeliveryRuleRepository($products, $transactions),
            new \app\modules\provider\domain\ProviderProvisioningPreview(),
        );
        $actor = new AdminAccount(2, 'publisher', '发布用户', 'hidden', 1, null);
        try {
            $service->publishAndEnable($actor, $offer['id'], $offer['revision']);
            self::fail('仅发布权限不能启用配置');
        } catch (BusinessException $error) {
            self::assertSame(403, $error->httpStatus());
        }
        self::assertSame(0, Db::table('offer_versions')->count());
        self::assertSame($offer['revision'], $products->find($offer['id'])['revision']);
    }

    public function testZoneDurationsDriveOptionsPricesAndImmutablePublishedVersions(): void
    {
        [$service, $actor, $offer, $selection, $resources, $connections] = $this->fixture();
        $zone = $resources->save('zones', null, (new ComputeResourceValidator())->validate('zones', ['code' => 'zone-period-two', 'name' => '第二周期区', 'status' => 1, 'region_id' => 1]));
        foreach (['lines', 'flavors', 'images', 'disk_types'] as $kind) {
            $row = $resources->find($kind, 1); $row['zone_ids'] = [1, $zone['id']];
            $resources->save($kind, 1, $row);
        }
        $connectionId = $offer['draft']['provider_connection_id'];
        $connections->map($connectionId, 'zones', $zone['id'], '1', 'areas');
        $input = $offer;
        $input['draft']['resource_ids']['zones'][] = $zone['id'];
        $input['draft']['zone_durations'] = [
            ['zone_id' => 1, 'durations' => $offer['draft']['durations']],
            ['zone_id' => $zone['id'], 'durations' => [
                ['months' => 3, 'label' => '季度', 'discount_kind' => 'rate', 'rate_bps' => 10000],
                ['months' => 12, 'label' => '年付八折', 'discount_kind' => 'rate', 'rate_bps' => 8000],
            ]],
        ];
        $saved = $service->save($actor, $offer['id'], (new CloudOfferValidator())->validate($input));
        self::assertArrayNotHasKey('durations', $saved['draft']);
        self::assertSame([12], array_column($service->preview($actor, $offer['id'], ['region_id' => 1, 'zone_id' => 1], false)['durations'], 'months'));
        self::assertSame([3, 12], array_column($service->preview($actor, $offer['id'], ['region_id' => 1, 'zone_id' => $zone['id']], false)['durations'], 'months'));
        self::assertSame([], $service->preview($actor, $offer['id'], [], false)['durations']);
        self::assertSame([], $service->preview($actor, $offer['id'], ['region_id' => 1, 'zone_id' => 999], false)['durations']);
        self::assertSame(318900, $service->preview($actor, $offer['id'], $selection, true)['amount_minor']);
        $selection['zone_id'] = $zone['id'];
        self::assertSame(306180, $service->preview($actor, $offer['id'], $selection, true)['amount_minor']);
        $published = $service->publish($actor, $offer['id'], $saved['revision']);
        $versionId = $published['published_version_id'];
        $snapshot = $service->versions($actor, $offer['id'])[0]['snapshot'];
        self::assertArrayNotHasKey('durations', $snapshot);
        self::assertCount(2, $snapshot['zone_durations']);
        $input = $published;
        $input['draft']['zone_durations'][1]['durations'][1]['rate_bps'] = 9000;
        $service->save($actor, $offer['id'], (new CloudOfferValidator())->validate($input));
        self::assertSame(344340, $service->preview($actor, $offer['id'], $selection, true)['amount_minor']);
        self::assertSame(306180, $service->preview($actor, $offer['id'], $selection, true, $versionId)['amount_minor']);
        $selection['zone_id'] = 1; $selection['duration_months'] = 3;
        $options = $service->preview($actor, $offer['id'], $selection, false);
        self::assertArrayHasKey('duration_months', $options['invalid_fields']);
        self::assertArrayNotHasKey('duration_months', $options['configuration']);
        try { $service->preview($actor, $offer['id'], $selection, true); self::fail('不能套用其他可用区周期'); }
        catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        try { $service->deliveryPreview($actor, $offer['id'], $selection, $versionId); self::fail('交付也必须校验可用区周期'); }
        catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
    }

    public function testExplicitMissingZoneDurationsCannotFallBackToLegacyGlobalPeriods(): void
    {
        [$service, $actor, $offer] = $this->fixture();
        $input = $offer; $input['draft']['zone_durations'] = [];
        $saved = $service->save($actor, $offer['id'], (new CloudOfferValidator())->validate($input));
        self::assertSame([], $saved['draft']['zone_durations']);
        self::assertArrayNotHasKey('durations', $saved['draft']);
        try { $service->publish($actor, $offer['id'], $saved['revision']); self::fail('周期缺失不能发布'); }
        catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        self::assertCount(0, $service->versions($actor, $offer['id']));
    }

    public function testHistoricalGlobalPeriodSnapshotKeepsItsOriginalPricing(): void
    {
        [$service, $actor, $offer, $selection] = $this->fixture();
        $published = $service->publish($actor, $offer['id'], $offer['revision']);
        $snapshot = $service->versions($actor, $offer['id'])[0]['snapshot'];
        unset($snapshot['zone_durations']); $snapshot['durations'] = $offer['draft']['durations'];
        $handler = new \app\modules\compute\domain\PrepaidComputeTypeHandler();
        self::assertSame(318900, $handler->price($snapshot, $selection)['amount_minor']);
        self::assertSame([], $handler->resolve($snapshot, [])['durations']);
        self::assertSame($offer['draft']['durations'], $handler->resolve($snapshot, $selection)['durations']);
    }

    public function testZoneDurationWhitelistAndInvalidConfigurations(): void
    {
        $period = ['months' => 12, 'label' => '年付', 'discount_kind' => 'rate', 'rate_bps' => 8000, 'password' => 'discard'];
        $binding = ['zone_id' => 1, 'durations' => [$period], 'unknown' => 'discard'];
        $base = ['name' => '周期配置', 'code' => 'periods', 'draft' => ['schema_version' => '2.0.0', 'resource_ids' => ['zones' => [1, 2]], 'zone_durations' => [$binding]]];
        $validator = new CloudOfferValidator();
        $clean = $validator->validate($base)['draft']['zone_durations'][0];
        self::assertArrayNotHasKey('unknown', $clean); self::assertArrayNotHasKey('password', $clean['durations'][0]);
        foreach ([[$binding, $binding], [['zone_id' => 3, 'durations' => [$period]]], [['zone_id' => 1, 'durations' => [$period, $period]]], [['zone_id' => 1, 'durations' => []]], [['zone_id' => 1, 'durations' => [array_replace($period, ['rate_bps' => 0])]]], [['zone_id' => 1, 'durations' => [array_replace($period, ['discount_kind' => 'billed_months', 'billed_months' => 13])]]], null] as $invalid) {
            $input = $base; $input['draft']['zone_durations'] = $invalid;
            try { $validator->validate($input); self::fail('无效可用区周期必须拒绝'); }
            catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        }
    }

    public function testSectionPagesCheckBothPageAndDataPermissionsWithoutWriting(): void
    {
        [, $founder, $offer, , $resources, $connections, $adapters] = $this->fixture();
        $repo = $this->createStub(AdminAccessRepositoryInterface::class);
        $repo->method('grantsForAdmin')->willReturnMap([
            [2, [['permissions' => ['admin.cloud-configuration.deployment.view', 'admin.compute-resources.view']]]],
            [3, [['permissions' => ['admin.compute-resources.view', 'admin.cloud-products.view']]]],
            [4, [['permissions' => ['admin.cloud-configuration.purchase.view']]]],
        ]);
        $access = new AdminAccessControlService($repo);
        $transactions = new DatabaseTransactionManager();
        $products = new DatabaseProductRepository($transactions, new DatabaseProviderBindingWriter());
        $configuration = new \app\modules\product\application\ManageCloudWorkspaceConfigurationService($access, $products);
        $catalog = new ManageCloudProductsService($access, $products, new ProductTypeRegistry(new ComputeTypeHandler()), $resources,
            new ResolveProviderBindingService($connections, $adapters), $connections, $transactions,
            new DatabaseCloudDeliveryRuleRepository($products, $transactions), new \app\modules\provider\domain\ProviderProvisioningPreview());
        $sections = new \app\modules\product\application\GetCloudConfigurationSectionService($access, $configuration, $catalog,
            new ManageComputeResourcesService($access, $resources),
            new \app\modules\compute\application\ManageComputeInventoryService($access, new \app\modules\compute\infrastructure\DatabaseComputeInventoryRepository(), $resources));
        foreach (['deployment', 'specification', 'network', 'storage', 'purchase'] as $section) {
            self::assertSame($offer['id'], $sections->get($founder, $section)['configuration']['id']);
        }
        $viewer = new AdminAccount(2, 'viewer', '查看用户', 'hidden', 1, null);
        $data = $sections->get($viewer, 'deployment');
        self::assertCount(1, $data['references']['resources']['zones']);
        self::assertNull($data['configuration']);
        foreach ([[$viewer, 'network'], [new AdminAccount(3, 'old', '无页面权限', 'hidden', 1, null), 'deployment'], [new AdminAccount(4, 'page-only', '无数据权限', 'hidden', 1, null), 'purchase']] as [$actor, $section]) {
            try { $sections->get($actor, $section); self::fail('必须拒绝无权限访问'); }
            catch (BusinessException $error) { self::assertSame(403, $error->httpStatus()); }
        }
        try { $configuration->save($viewer, $offer['id'], $offer, null); self::fail('仅查看不能保存'); }
        catch (BusinessException $error) { self::assertSame(403, $error->httpStatus()); }
        try { $sections->get($founder, 'unknown'); self::fail('未知页面不能读取'); }
        catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        self::assertSame($offer['revision'], $products->find($offer['id'])['revision']);
        self::assertSame(0, Db::table('offer_versions')->count());
    }

    public function testCommunitySaveAppliesWithoutPublishPermissionAndKeepsHistoricalVersions(): void
    {
        [, , $offer, $selection, $resources, $connections, $adapters] = $this->fixture();
        $repository = $this->createStub(AdminAccessRepositoryInterface::class);
        $repository->method('grantsForAdmin')->willReturnMap([
            [2, [['permissions' => ['admin.cloud-products.update', 'admin.cloud-products.view', 'admin.cloud-products.preview']]]],
            [3, [['permissions' => ['admin.cloud-products.view']]]],
        ]);
        $access = new AdminAccessControlService($repository);
        $transactions = new DatabaseTransactionManager();
        $products = new DatabaseProductRepository($transactions, new DatabaseProviderBindingWriter());
        $configuration = new \app\modules\product\application\ManageCloudWorkspaceConfigurationService($access, $products);
        $catalog = new ManageCloudProductsService($access, $products, new ProductTypeRegistry(new ComputeTypeHandler()), $resources,
            new ResolveProviderBindingService($connections, $adapters), $connections, $transactions,
            new DatabaseCloudDeliveryRuleRepository($products, $transactions), new \app\modules\provider\domain\ProviderProvisioningPreview());
        $service = new \app\modules\product\application\SaveCloudWorkspaceConfigurationService($configuration, $catalog, $transactions);
        $actor = new AdminAccount(2, 'editor', '配置编辑', 'hidden', 1, null);
        $first = $service->save($actor, $offer['id'], (new CloudOfferValidator())->validate($offer), null);
        self::assertTrue($first['configuration_ready']);
        self::assertSame(1, $first['status']);
        $version = $first['published_version_id'];
        $firstPrice = $catalog->preview($actor, $offer['id'], $selection, true, $version)['amount_minor'];
        $input = $first; $input['draft']['setup_minor'] = 1000;
        $second = $service->save($actor, $offer['id'], (new CloudOfferValidator())->validate($input), $offer['id']);
        self::assertTrue($second['configuration_ready']);
        self::assertNotSame($version, $second['published_version_id']);
        self::assertSame($firstPrice, $catalog->preview($actor, $offer['id'], $selection, true, $version)['amount_minor']);
        $disabled = $catalog->status($actor, $offer['id'], $second['revision'], 0);
        $third = $service->save($actor, $offer['id'], (new CloudOfferValidator())->validate($disabled), $offer['id']);
        self::assertSame(0, $third['status'], '保存不能意外重新启用已停用配置');
        $invalid = $third; $invalid['draft']['resource_ids']['images'] = [];
        $partial = $service->save($actor, $offer['id'], (new CloudOfferValidator())->validate($invalid), $offer['id']);
        self::assertFalse($partial['configuration_ready']);
        self::assertNotEmpty($partial['configuration_notice']);
        self::assertSame([], $products->find($offer['id'])['draft']['resource_ids']['images']);
        self::assertSame($third['published_version_id'], $partial['published_version_id']);
        $viewer = new AdminAccount(3, 'viewer', '查看用户', 'hidden', 1, null);
        try { $service->save($viewer, $offer['id'], (new CloudOfferValidator())->validate($partial), $offer['id']); self::fail('只读不能保存'); }
        catch (BusinessException $error) { self::assertSame(403, $error->httpStatus()); }
        try { $service->save($actor, $offer['id'], (new CloudOfferValidator())->validate($second), $offer['id']); self::fail('并发版本冲突必须拒绝'); }
        catch (BusinessException $error) { self::assertSame(409, $error->httpStatus()); }
        self::assertSame($partial['revision'], $products->find($offer['id'])['revision']);
    }

    private function fixture(): array
    {
        $resources = new DatabaseComputeResourceRepository();
        $validator = new ComputeResourceValidator();
        $ids = [];
        foreach (['regions' => [], 'zones' => ['region_id' => 1], 'lines' => ['zone_id' => 1, 'network_configuration' => ['egress' => ['min' => 0, 'default' => 5, 'included' => 5, 'pricing_mode' => 'graduated', 'tiers' => [['up_to_mbps' => 5, 'price_minor' => 100], ['up_to_mbps' => null, 'price_minor' => 80]]], 'ingress' => ['min' => 0, 'default' => 0], 'ip' => ['min' => 1, 'max' => 4, 'default' => 1, 'included' => 1, 'price_minor' => 200]]], 'flavor_groups' => [],
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
        $input = (new CloudOfferValidator())->validate(['name' => '测试云服务器', 'code' => 'fixture-cloud', 'draft' => ['schema_version' => '2.0.0', 'storage_policy' => ['default_type_id' => 1, 'default_size_gib' => 20, 'max_data_disks' => 1], 'system_disk_included_gib' => 40, 'durations' => [['months' => 12, 'label' => '年付', 'discount_kind' => 'billed_months', 'billed_months' => 10]], 'resource_ids' => $ids, 'provider_connection_id' => $conn['id'],
            'setup_minor' => 300, 'network' => ['bandwidth_prices' => ['month' => 100], 'ip_prices' => ['month' => 200]]]]);
        $offer = $service->save($actor, null, $input);
        $selection = ['region_id' => 1, 'zone_id' => 1, 'line_id' => 1, 'flavor_id' => 1, 'image_id' => 1, 'duration_months' => 12, 'quantity' => 3,
            'system_disk' => ['type_id' => 1, 'size_gib' => 60], 'data_disks' => [['type_id' => 1, 'size_gib' => 30]], 'network' => ['egress_mbps' => 10, 'ingress_mbps' => 0, 'ipv4_count' => 2]];
        return [$service, $actor, $offer, $selection, $resources, $connections, $adapters];
    }
}
