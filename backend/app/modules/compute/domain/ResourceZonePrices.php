<?php

declare(strict_types=1);

namespace app\modules\compute\domain;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 资源默认价与关联可用区独立价；缺项继承，显式零价不回退。 */
final class ResourceZonePrices
{
    /** @param mixed $input 可用区ID到周期单价的映射 @param list<int> $zoneIds 关联范围
     * @return array<int, array<string, int>> 单价，单位为整数分
     */
    public function normalize(mixed $input, array $zoneIds): array
    {
        if (!is_array($input) || count($input) > 500) {
            $this->fail('zone_prices', '可用区价格须为不超过500项的映射');
        }
        $result = [];
        foreach ($input as $id => $prices) {
            if (!preg_match('/^[1-9][0-9]*$/D', (string) $id) || !in_array((int) $id, $zoneIds, true)) {
                $this->fail('zone_prices', '只能为已关联的可用区设置价格');
            }
            if (!is_array($prices)) {
                $this->fail('zone_prices.' . $id, '周期价格须为映射');
            }
            foreach ($prices as $cycle => $price) {
                if (!in_array($cycle, ComputeResources::CYCLES, true) || !is_int($price) || $price < 0 || $price > 100000000) {
                    $this->fail('zone_prices.' . $id . '.' . $cycle, '价格须为0至100000000的整数分，并使用支持的周期');
                }
            }
            if ($prices !== []) {
                $result[(int) $id] = $prices;
            }
        }
        ksort($result);
        return $result;
    }

    /** @param array<string, mixed> $resource 已封存资源 @return array<string, int> 当前可用区有效周期价格 */
    public function resolve(array $resource, int $zoneId): array
    {
        return array_replace($resource['prices'], $resource['zone_prices'][$zoneId] ?? []);
    }

    private function fail(string $field, string $message): never
    {
        throw new BusinessException($message, ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => [$field => [$message]]]);
    }
}
