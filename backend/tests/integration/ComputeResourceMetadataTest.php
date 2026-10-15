<?php

declare(strict_types=1);

namespace tests\integration;

use app\modules\compute\infrastructure\DatabaseComputeResourceRepository;
use app\modules\compute\validation\ComputeResourceValidator;
use PHPUnit\Framework\TestCase;
use support\Db;
use support\validation\ValidationException;
use tests\support\IsolatedBusinessDatabase;

/** 全新安装后验证字段校验、真实持久化和旧请求兼容。 */
final class ComputeResourceMetadataTest extends TestCase
{
    use IsolatedBusinessDatabase;

    public function testMetadataPersistsAndCanBeClearedWithoutChangingRelationships(): void
    {
        $validator = new ComputeResourceValidator();
        $repository = new DatabaseComputeResourceRepository();
        $base = ['code' => 'region', 'name' => '地域', 'status' => 1];
        self::assertSame('', $repository->save('regions', null, $validator->validate('regions', ['code' => 'empty', 'name' => '未配置国家', 'status' => 1]))['country_code']);
        $region = $repository->save('regions', null, $validator->validate('regions', $base + ['country_code' => ' cn ']));
        self::assertSame('CN', $repository->find('regions', $region['id'])['country_code']);
        $zone = $repository->save('zones', null, $validator->validate('zones', ['code' => 'zone', 'name' => '可用区', 'status' => 1, 'region_id' => $region['id']]));
        $lineInput = ['code' => 'line', 'name' => '线路', 'status' => 1, 'zone_ids' => [$zone['id']]];
        self::assertSame('', $repository->save('lines', null, $validator->validate('lines', array_replace($lineInput, ['code' => 'empty', 'sort' => 10])))['test_ip']);
        $line = $repository->save('lines', null, $validator->validate('lines', $lineInput + ['test_ip' => ' 2001:db8::1 ']));
        self::assertSame('2001:db8::1', $repository->list('lines')[0]['test_ip']);
        self::assertSame('CN', Db::table('compute_regions')->where('id', $region['id'])->value('country_code'));
        self::assertSame('2001:db8::1', Db::table('compute_lines')->where('id', $line['id'])->value('test_ip'));
        $repository->save('regions', $region['id'], $validator->validate('regions', $base));
        $repository->save('lines', $line['id'], $validator->validate('lines', $lineInput));
        self::assertSame('CN', $repository->find('regions', $region['id'])['country_code']);
        self::assertSame('2001:db8::1', $repository->find('lines', $line['id'])['test_ip']);
        foreach (['', null, '  '] as $empty) {
            self::assertSame('', $repository->save('regions', $region['id'], $validator->validate('regions', $base + ['country_code' => $empty]))['country_code']);
            $saved = $repository->save('lines', $line['id'], $validator->validate('lines', $lineInput + ['test_ip' => $empty]));
            self::assertSame('', $saved['test_ip']);
            self::assertSame([$zone['id']], $saved['zone_ids']);
        }
        self::assertSame('192.0.2.1', $repository->save('lines', $line['id'], $validator->validate('lines', $lineInput + ['test_ip' => '192.0.2.1']))['test_ip']);
    }

    public function testInvalidValuesAreRejectedAndMetadataCannotLeakToOtherKinds(): void
    {
        $validator = new ComputeResourceValidator();
        $base = ['code' => 'test', 'name' => '测试', 'status' => 1];
        foreach (['C', 'CHN', '12', '中', ['CN']] as $invalid) {
            try {
                $validator->validate('regions', $base + ['country_code' => $invalid]);
                self::fail('无效国家代码应被拒绝');
            } catch (ValidationException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        foreach (['999.1.1.1', '2001:db8:::1', 'https://192.0.2.1', 'example.com', '192.0.2.1:80', '192.0.2.1/24', ['192.0.2.1']] as $invalid) {
            try {
                $validator->validate('lines', $base + ['zone_ids' => [1], 'test_ip' => $invalid]);
                self::fail('无效测试 IP 应被拒绝');
            } catch (ValidationException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        $data = $validator->validate('zones', $base + ['region_id' => 1, 'country_code' => 'CN', 'test_ip' => '192.0.2.1']);
        self::assertArrayNotHasKey('country_code', $data);
        self::assertArrayNotHasKey('test_ip', $data);
        if (Db::connection()->getDriverName() === 'mysql') {
            foreach (['compute_regions' => 'country_code', 'compute_lines' => 'test_ip'] as $table => $field) {
                $column = Db::selectOne('SELECT COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [Db::connection()->getDatabaseName(), 'orm_' . $table, $field]);
                self::assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $column->COLUMN_COMMENT);
            }
        }
    }
}
