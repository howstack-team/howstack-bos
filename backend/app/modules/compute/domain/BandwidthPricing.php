<?php

declare(strict_types=1);

namespace app\modules\compute\domain;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 带宽价格使用整数分；区间从 0 开始，边界为包含上限。 */
final class BandwidthPricing
{
    /** 验证可售带宽的完整价格覆盖。
     * @param array<string, mixed> $network 网络销售配置
     * @param list<string> $cycles 可售周期
     * @return void
     * @throws BusinessException 阶梯边界或价格不完整
     */
    public function validate(array $network, array $cycles): void
    {
        $mode = $network['bandwidth_billing_mode'] ?? 'flat';
        if ($mode === 'flat') return;
        if (!in_array($mode, ['graduated', 'volume'], true)) $this->fail('不支持的带宽计费方式');
        $tiers = $network['bandwidth_tiers'] ?? [];
        if ($tiers === [] || count($tiers) > 40) $this->fail('请设置 1 至 40 个带宽价格区间');
        $previous = 0;
        foreach ($tiers as $index => $tier) {
            $end = $tier['up_to_mbps'];
            if (($end === null && $index !== array_key_last($tiers)) || ($end !== null && (!is_int($end) || $end <= $previous))) $this->fail('带宽区间上限须递增，无上限仅能用于最后一段');
            foreach ($cycles as $cycle) if (!isset($tier['prices'][$cycle]) || !is_int($tier['prices'][$cycle]) || $tier['prices'][$cycle] < 0) $this->fail('每个区间须填写全部可售周期的单价');
            $previous = $end ?? $network['bandwidth_max'];
        }
        if ($previous < $network['bandwidth_max']) $this->fail('带宽价格区间没有覆盖最大可售带宽');
    }

    /** 返回各段费用，旧版本自动采用固定单价。
     * @param array<string, mixed> $network 网络销售配置
     * @param int $bandwidth 购买带宽 Mbps
     * @param string $cycle 计费周期
     * @return list<array{key: string, label: string, quantity: int, unit_price_minor: int, amount_minor: int}> 明细
     */
    public function calculate(array $network, int $bandwidth, string $cycle): array
    {
        $mode = $network['bandwidth_billing_mode'] ?? 'flat';
        if ($mode === 'flat') return [$this->part('bandwidth', '带宽', $bandwidth, $network['bandwidth_prices'][$cycle])];
        $this->validate($network, [$cycle]);
        $parts = [];
        $start = 0;
        foreach ($network['bandwidth_tiers'] as $index => $tier) {
            $end = $tier['up_to_mbps'] ?? $network['bandwidth_max'];
            if ($mode === 'volume') {
                if ($bandwidth <= $end) return [$this->part('bandwidth', '带宽（整段单价）', $bandwidth, $tier['prices'][$cycle])];
            } else {
                $quantity = max(0, min($bandwidth, $end) - $start);
                if ($quantity > 0) $parts[] = $this->part('bandwidth.' . $index, '带宽 ' . ($start + 1) . '–' . $end . ' Mbps', $quantity, $tier['prices'][$cycle]);
                if ($bandwidth <= $end) break;
            }
            $start = $end;
        }
        return $parts;
    }

    /** @param string $key 项目标识 @param string $label 文案 @param int $quantity Mbps @param int $price 单价分 @return array{key: string, label: string, quantity: int, unit_price_minor: int, amount_minor: int} 明细 */
    private function part(string $key, string $label, int $quantity, int $price): array
    {
        return ['key' => $key, 'label' => $label, 'quantity' => $quantity, 'unit_price_minor' => $price, 'amount_minor' => $quantity * $price];
    }

    /** @param string $message 校验原因 @return never @throws BusinessException 价格配置无效 */
    private function fail(string $message): never
    {
        throw new BusinessException($message, ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => ['network.bandwidth_tiers' => [$message]]]);
    }
}
