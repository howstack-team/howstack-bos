<?php

declare(strict_types=1);

namespace tests\unit\modules\product;

use app\exception\BusinessException;
use app\modules\product\contract\{OrderSalesCatalogInterface, ProductPurchaseHandlerInterface, ProductTypeHandlerInterface};
use app\modules\product\infrastructure\{ProductPurchaseRegistry, ProductTypeRegistry};
use app\modules\product\application\PublicProductPurchaseService;
use app\modules\order\application\{CreatePurchaseOrderService, MemberPurchaseOrderService};
use app\modules\order\contract\{OrderManagementRepositoryInterface, MemberOrderRepositoryInterface, OrderProvisioningInterface};
use app\modules\message\contract\MessagePublisherInterface;
use app\shared\contract\{TransactionManagerInterface, OutboxWriterInterface};
use PHPUnit\Framework\TestCase;

/** 第二种产品只在测试组合根注册，验证核心层不依赖云服务器字段或常量。 */
final class ProductDecouplingTest extends TestCase
{
    private function fixture(): array
    {
        $handler = new class implements ProductPurchaseHandlerInterface {
            public array $events = [];
            public function type(): string
            {
                return 'certificate';
            }
            public function supportsPublic(array $snapshot): bool
            {
                return ($snapshot['contract'] ?? '') === 'certificate.v1';
            }
            public function clean(array $input): array
            {
                $this->events[] = 'clean';
                return array_intersect_key($input, ['subject' => true]);
            }
            public function publicProduct(array $choice): array
            {
                return $choice + ['purchase_url' => '/console/purchase/certificate'];
            }
            public function publicOptions(array $resolved): array
            {
                return ['configuration' => $resolved['configuration']];
            }
            public function check(array $configuration): void
            {
                $this->events[] = 'check';
            }
            public function occupy(array $configuration, array $unitIds): void
            {
                $this->events[] = 'occupy';
            }
            public function release(int $unitId): void
            {
                $this->events[] = 'release';
            }
        };
        $type = new class implements ProductTypeHandlerInterface {
            public function type(): string
            {
                return 'certificate';
            }
            public function version(): string
            {
                return 'certificate.v1';
            }
            public function supportsVersion(string $version): bool
            {
                return $version === $this->version();
            }
            public function snapshot(array $draft, array $resources): array
            {
                return $draft;
            }
            public function resolve(array $snapshot, array $selection): array
            {
                return ['configuration' => $selection, 'private' => 'must-not-leak'];
            }
            public function provisioningConfiguration(array $snapshot, array $selection): array
            {
                return $selection;
            }
            public function price(array $snapshot, array $selection): array
            {
                return [
                    'configuration' => $selection, 'currency' => 'CNY', 'quantity' => 1,
                    'unit_amount_minor' => 500, 'amount_minor' => 500,
                    'unit_breakdown' => [['key' => 'certificate', 'label' => '证书', 'quantity' => 1,
                        'unit_price_minor' => 500, 'amount_minor' => 500, 'facts' => ['private' => true]]],
                ];
            }
        };
        $sale = ['offer_id' => 5, 'version_id' => 7, 'product_id' => 4, 'product_type' => 'certificate',
            'type_version' => 'certificate.v1', 'snapshot' => ['contract' => 'certificate.v1']];
        $catalog = $this->createStub(OrderSalesCatalogInterface::class);
        $catalog->method('choices')->willReturn([['id' => 5, 'version_id' => 7, 'product_type' => 'certificate', 'name' => '证书']]);
        $catalog->method('published')->willReturn($sale);
        return [new ProductTypeRegistry([$type]), new ProductPurchaseRegistry([$handler]), $catalog, $handler, $type];
    }

    public function testAnotherProductUsesGenericPublicCatalogOptionsAndQuote(): void
    {
        [$types, $purchases, $catalog, $handler] = $this->fixture();
        $service = new PublicProductPurchaseService($catalog, $types, $purchases, $purchases, $purchases);
        self::assertSame('/console/purchase/certificate', $service->products()[0]['purchase_url']);
        $input = ['subject' => 'example.test', 'password' => 'must-not-leak', 'zone_id' => 123];
        self::assertSame(['configuration' => ['subject' => 'example.test']], $service->options(5, 7, $input));
        $quote = $service->quote(5, 7, $input);
        self::assertSame(500, $quote['amount_minor']);
        self::assertSame(['subject' => 'example.test'], $quote['configuration']);
        self::assertArrayNotHasKey('facts', $quote['unit_breakdown'][0]);
        self::assertSame(['clean', 'clean', 'check'], $handler->events);
    }

    public function testMemberOrderDoesNotValidateCloudSelectionOrRepeatTypeValidation(): void
    {
        [$types, $purchases, $catalog, $handler] = $this->fixture();
        $transactions = $this->createStub(TransactionManagerInterface::class);
        $transactions->method('run')->willReturnCallback(static fn (callable $operation) => $operation());
        $orders = $this->createMock(OrderManagementRepositoryInterface::class);
        $orders->method('begin')->willReturn(null);
        $orders->method('member')->willReturn(['id' => 2, 'nickname' => '会员', 'mobile' => '', 'status' => 1]);
        $orders->method('lock')->willReturn(['order_no' => 'CERT-TEST']);
        $orders->expects(self::once())->method('create')->willReturnCallback(static function ($member, $sale, $price, $provisioning): int {
            self::assertSame('certificate', $sale['product_type']);
            self::assertSame(['subject' => 'example.test'], $price['configuration']);
            self::assertSame(['subject' => 'example.test'], $provisioning);
            return 55;
        });
        $creator = new CreatePurchaseOrderService($orders, $catalog, $types, $transactions,
            $this->createStub(OutboxWriterInterface::class), $this->createStub(MessagePublisherInterface::class),
            $purchases, $purchases, $this->createStub(OrderProvisioningInterface::class), $purchases);
        $memberOrders = new MemberPurchaseOrderService($creator, $this->createStub(MemberOrderRepositoryInterface::class));
        self::assertSame(['id' => 55], $memberOrders->create(2, [
            'offer_id' => 5, 'version_id' => 7, 'selection' => ['subject' => 'example.test', 'private' => 'discard'],
            'request_key' => str_repeat('a', 32), 'expected_amount_minor' => 500,
        ]));
        self::assertSame(['clean', 'check'], $handler->events);
    }

    public function testUnknownTypeCannotSilentlySkipInventoryOrPublicAdmission(): void
    {
        [, $purchases] = $this->fixture();
        self::assertFalse($purchases->supportsPublic('missing', []));
        foreach (['check', 'occupy', 'release'] as $method) {
            try {
                match ($method) {
                    'check' => $purchases->check('missing', []),
                    'occupy' => $purchases->occupy('missing', [], []),
                    'release' => $purchases->release('missing', 1),
                };
                self::fail('未知产品不能跳过检查');
            } catch (BusinessException $error) {
                self::assertSame(409, $error->httpStatus());
            }
        }
    }

    public function testDuplicateRegistrationsAreRejected(): void
    {
        [, , , $handler, $type] = $this->fixture();
        foreach ([static fn () => new ProductPurchaseRegistry([$handler, $handler]),
            static fn () => new ProductTypeRegistry([$type, $type])] as $create) {
            try {
                $create();
                self::fail('重复注册必须失败');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('重复', $error->getMessage());
            }
        }
    }

    public function testProductCoreAndOrderApplicationDoNotImportCompute(): void
    {
        foreach (['product', 'order/application'] as $directory) {
            $path = dirname(__DIR__, 4) . '/app/modules/' . $directory;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                self::assertStringNotContainsString('app\\modules\\compute', $source, $file->getPathname());
                self::assertStringNotContainsString("'cloud_server'", $source, $file->getPathname());
            }
        }
    }
}
