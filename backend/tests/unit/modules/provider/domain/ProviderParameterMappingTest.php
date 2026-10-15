<?php

declare(strict_types=1);

namespace tests\unit\modules\provider\domain;

use app\exception\BusinessException;
use app\modules\provider\domain\ProviderParameterMapping;
use PHPUnit\Framework\TestCase;

/** 供应映射仅处理受控标准字段，明确失败而非截断、执行或泄露。 */
final class ProviderParameterMappingTest extends TestCase
{
    public function testUnitsEnumsAndOptionalValues(): void
    {
        $result = (new ProviderParameterMapping())->build([
            ['source' => 'flavor.cpu', 'target' => 'server.cpu'],
            ['source' => 'flavor.memory_mib', 'target' => 'server.memory_gib', 'transform' => 'divide', 'factor' => 1024],
            ['source' => 'duration.unit', 'target' => 'period', 'transform' => 'lookup', 'lookup' => ['month' => 'monthly']],
            ['source' => 'protection.external_id', 'target' => 'protection', 'required' => false],
        ], ['flavor' => ['cpu' => 2, 'memory_mib' => 2048], 'duration' => ['unit' => 'month'], 'password' => 'not-exposed']);
        self::assertSame(['server' => ['cpu' => 2, 'memory_gib' => 2], 'period' => 'monthly'], $result);
        self::assertStringNotContainsString('not-exposed', json_encode($result));
    }

    public function testSecretsAndOverlappingTargetsAreRejected(): void
    {
        foreach ([
            [['source' => 'password', 'target' => 'password']],
            [['source' => 'flavor.cpu', 'target' => 'server'], ['source' => 'flavor.memory_mib', 'target' => 'server.memory']],
            [['source' => 'flavor.cpu', 'target' => 'server.cpu', 'transform' => 'eval']],
            [['source' => 'duration.unit', 'target' => 'period', 'lookup' => '{broken json']],
        ] as $rules) {
            try { (new ProviderParameterMapping())->normalize($rules); self::fail('不安全或无效配置须拒绝'); }
            catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        }
    }

    public function testLossyDivisionAndMissingRequiredSourceAreRejected(): void
    {
        foreach ([['flavor' => ['memory_mib' => 1025]], []] as $configuration) {
            try { (new ProviderParameterMapping())->build([['source' => 'flavor.memory_mib', 'target' => 'memory', 'transform' => 'divide', 'factor' => 1024]], $configuration); self::fail('缺失或有损转换须拒绝'); }
            catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        }
    }
}
