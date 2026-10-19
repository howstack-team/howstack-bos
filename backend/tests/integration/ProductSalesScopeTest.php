<?php

declare(strict_types=1);

namespace tests\integration;

use app\exception\BusinessException;
use app\modules\product\infrastructure\DatabaseProductRepository;
use app\modules\product\infrastructure\model\ProductModel;
use app\modules\provider\contract\ProviderBindingWriterInterface;
use app\shared\infrastructure\DatabaseTransactionManager;
use PHPUnit\Framework\TestCase;
use tests\support\IsolatedBusinessDatabase;

/** 通用销售存储显式限定产品身份，不能串读或修改另一个类型的配置及版本。 */
final class ProductSalesScopeTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testDifferentProductsHaveIndependentConfigurationAndVersionScope(): void
    {
        ProductModel::query()->create(['name' => '证书测试身份', 'definition_key' => 'certificate', 'description' => '测试']);
        $transactions = new DatabaseTransactionManager();
        $bindings = $this->createStub(ProviderBindingWriterInterface::class);
        $cloud = new DatabaseProductRepository($transactions, $bindings, 'cloud_server');
        $certificate = new DatabaseProductRepository($transactions, $bindings, 'certificate');
        $input = ['name' => '销售配置', 'code' => 'cloud-scope', 'description' => '', 'revision' => 0, 'draft' => []];
        $first = $cloud->saveWorkspaceConfiguration(null, $input, null);
        $second = $certificate->saveWorkspaceConfiguration(null, array_replace($input, ['code' => 'certificate-scope']), null);
        self::assertSame('cloud_server', $first['product_type']);
        self::assertSame('certificate', $second['product_type']);
        self::assertSame($first['id'], $cloud->workspaceConfiguration()['configuration']['id']);
        self::assertSame($second['id'], $certificate->workspaceConfiguration()['configuration']['id']);
        self::assertSame(1, $cloud->overview()['total']);
        self::assertSame(1, $certificate->overview()['total']);
        self::assertNull($cloud->find($second['id']));
        self::assertNull($certificate->find($first['id']));
        foreach ([static fn () => $cloud->versions($second['id']),
            static fn () => $certificate->setStatus($first['id'], $first['revision'], 0),
            static fn () => $cloud->saveWorkspaceConfiguration($second['id'], $second, $first['id'])] as $operation) {
            try {
                $operation();
                self::fail('跨产品读写应拒绝');
            } catch (BusinessException $error) {
                self::assertSame(404, $error->httpStatus());
            }
        }
    }
}
