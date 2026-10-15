<?php

declare(strict_types=1);

namespace app\modules\compute\domain;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 线路专属固定带宽规则；整数分、Mbps，IP 赠送按每实例计算。 */
final class LineNetworkConfiguration
{
    /** @param array<string, mixed> $input 受控配置 @return array<string, mixed> 白名单 */
    public function normalize(array $input): array
    {
        if (($input['billing_mode'] ?? 'fixed') !== 'fixed') $this->fail('当前版本仅支持固定带宽');
        $result = ['billing_mode' => 'fixed'];
        foreach (['egress', 'ingress'] as $direction) {
            $raw = $input[$direction] ?? [];
            if (!is_array($raw)) $this->fail('请填写上下行带宽配置');
            $band = [];
            foreach (['min' => 0, 'max' => 100, 'step' => 1, 'default' => 1, 'included' => 0, 'price_minor' => 0] as $key => $default) {
                $band[$key] = $this->integer($raw[$key] ?? $default, $key === 'step' ? 1 : 0, $key === 'price_minor' ? 100000000 : 1000000);
            }
            if ($band['max'] < $band['min'] || $band['included'] > $band['max'] || $band['default'] < $band['min'] || $band['default'] > $band['max'] || ($band['default'] - $band['min']) % $band['step'] !== 0) $this->fail('带宽默认值须在范围内且符合步长，赠送值不能超过最大值');
            $band['pricing_mode'] = $raw['pricing_mode'] ?? 'flat';
            if (!in_array($band['pricing_mode'], ['flat', 'graduated', 'volume'], true)) $this->fail('带宽价格算法无效');
            $tiers = $raw['tiers'] ?? [];
            if (!is_array($tiers) || count($tiers) > 40) $this->fail('最多设置40个价格阶梯');
            $band['tiers'] = [];
            foreach ($tiers as $tier) {
                if (!is_array($tier)) $this->fail('带宽阶梯格式无效');
                $band['tiers'][] = ['up_to_mbps' => isset($tier['up_to_mbps']) ? $this->integer($tier['up_to_mbps'], 1, 1000000) : null, 'price_minor' => $this->integer($tier['price_minor'] ?? 0, 0, 100000000)];
            }
            (new BandwidthPricing())->validate($this->pricing($band), ['month']);
            $result[$direction] = $band;
        }
        $raw = $input['ip'] ?? [];
        if (!is_array($raw)) $this->fail('IP配置格式无效');
        $ip = ['mode' => $raw['mode'] ?? 'range'];
        if (!in_array($ip['mode'], ['range', 'options'], true)) $this->fail('IP数量配置方式无效');
        foreach (['min' => 1, 'max' => 1, 'step' => 1, 'default' => 1, 'included' => 1, 'price_minor' => 0] as $key => $default) $ip[$key] = $this->integer($raw[$key] ?? $default, $key === 'step' ? 1 : 0, $key === 'price_minor' ? 100000000 : 64);
        $options = $raw['options'] ?? [1];
        if (!is_array($options) || count($options) > 65) $this->fail('IP可选数量格式无效');
        $ip['options'] = array_values(array_unique(array_map(fn (mixed $v): int => $this->integer($v, 0, 64), $options)));
        sort($ip['options']);
        if ($ip['max'] < $ip['min'] || $ip['included'] > ($ip['mode'] === 'range' ? $ip['max'] : max($ip['options'] ?: [0]))) $this->fail('IP范围或赠送数量无效');
        $this->validateIp($ip, $ip['default']);
        $result['ip'] = $ip;
        return $result;
    }

    /** @param array<string, mixed> $band 带宽 @return array<string, mixed> 旧计价器适配 */
    private function pricing(array $band): array
    {
        return ['bandwidth_max' => $band['max'], 'bandwidth_billing_mode' => $band['pricing_mode'], 'bandwidth_prices' => ['month' => $band['price_minor']],
            'bandwidth_tiers' => array_map(static fn (array $t): array => ['up_to_mbps' => $t['up_to_mbps'], 'prices' => ['month' => $t['price_minor']]], $band['tiers'])];
    }

    /** @param array<string, mixed> $band 已验证配置 @param int $value 实际带宽 @return int 月费分；整档以实际带宽选档。 */
    public function bandwidthAmount(array $band, int $value): int
    {
        if ($value < $band['min'] || $value > $band['max'] || ($value - $band['min']) % $band['step'] !== 0) $this->fail('带宽超出范围或不符合步长');
        $pricing = $this->pricing($band);
        $calculator = new BandwidthPricing();
        if ($band['pricing_mode'] === 'volume') {
            foreach ($band['tiers'] as $tier) if ($tier['up_to_mbps'] === null || $value <= $tier['up_to_mbps']) return max(0, $value - $band['included']) * $tier['price_minor'];
        }
        return array_sum(array_column($calculator->calculate($pricing, $value, 'month'), 'amount_minor'))
            - array_sum(array_column($calculator->calculate($pricing, min($value, $band['included']), 'month'), 'amount_minor'));
    }

    /** @param array<string, mixed> $ip 已验证IP配置 @param int $value 数量 @return void */
    public function validateIp(array $ip, int $value): void
    {
        if ($ip['mode'] === 'options' ? !in_array($value, $ip['options'], true) : ($value < $ip['min'] || $value > $ip['max'] || ($value - $ip['min']) % $ip['step'] !== 0)) $this->fail('IP数量不属于允许的选项或范围');
    }

    /** @param mixed $value 输入 @param int $min 下界 @param int $max 上界 @return int 已检查的整数 */
    private function integer(mixed $value, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) $this->fail('网络数量和价格须为范围内的整数');
        return $value;
    }

    /** @param string $message 校验原因 @return never */
    private function fail(string $message): never
    {
        throw new BusinessException($message, ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => ['network_configuration' => [$message]]]);
    }
}
