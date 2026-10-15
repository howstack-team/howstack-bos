<?php

declare(strict_types=1);

namespace app\modules\provider\domain;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 标准字段到供应参数的声明式映射；仅白名单源，不执行用户表达式。 */
final class ProviderParameterMapping
{
    public const array SOURCES = ['region.external_id', 'zone.external_id', 'line.external_id', 'flavor_group.external_id', 'flavor.external_id', 'flavor.cpu', 'flavor.memory_mib', 'flavor.architecture',
        'image.external_id', 'image.default_username', 'image.default_port', 'system_disk.type.external_id', 'system_disk.size_gib', 'data_disks',
        'network.egress_mbps', 'network.ingress_mbps', 'network.ipv4_count', 'protection.external_id', 'quantity', 'duration.count', 'duration.unit', 'login_method'];

    /** @param list<array<string, mixed>> $rules 管理员配置 @return list<array<string, mixed>> 明确源、目标、转换及必填 */
    public function normalize(array $rules): array
    {
        if (!array_is_list($rules) || count($rules) > 64) $this->fail('最多配置64项供应参数对应');
        $result = []; $targets = [];
        foreach ($rules as $rule) {
            if (!is_array($rule) || !in_array($rule['source'] ?? '', self::SOURCES, true)) $this->fail('供应参数源字段不在标准契约中');
            $target = $rule['target'] ?? '';
            if (!is_string($target) || strlen($target) > 128 || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/', $target)) $this->fail('供应参数名须为字段路径');
            foreach ($targets as $previous) if ($target === $previous || str_starts_with($target, $previous . '.') || str_starts_with($previous, $target . '.')) $this->fail('供应参数路径不能重复或相互覆盖');
            $targets[] = $target;
            $transform = $rule['transform'] ?? 'copy';
            if (!in_array($transform, ['copy', 'string', 'multiply', 'divide', 'lookup'], true)) $this->fail('参数转换方式无效');
            $factor = $rule['factor'] ?? 1;
            if (!is_int($factor) || $factor < 1 || $factor > 1000000) $this->fail('单位转换系数须为1至1000000的整数');
            $lookup = $rule['lookup'] ?? [];
            if (!is_array($lookup) || count($lookup) > 200) $this->fail('枚举对应数量无效');
            foreach ($lookup as $key => $value) if ((!is_string($value) && !is_int($value)) || strlen((string) $key) > 128 || strlen((string) $value) > 128) $this->fail('枚举对应须为短文本或整数');
            $required = $rule['required'] ?? true;
            if (!is_bool($required)) $this->fail('必填标记须为布尔值');
            $result[] = ['source' => $rule['source'], 'target' => $target, 'transform' => $transform, 'factor' => $factor, 'lookup' => $lookup, 'required' => $required];
        }
        return $result;
    }

    /** @param list<array<string, mixed>> $rules 封存规则 @param array<string, mixed> $configuration 无凭据的标准交付 @return array<string, mixed> 供应参数；复杂数组协议由插件处理 */
    public function build(array $rules, array $configuration): array
    {
        $payload = [];
        foreach ($this->normalize($rules) as $rule) {
            $value = $configuration;
            foreach (explode('.', $rule['source']) as $segment) $value = is_array($value) ? ($value[$segment] ?? null) : null;
            if ($value === null) {
                if ($rule['required']) $this->fail('必填供应参数缺少来源：' . $rule['source']);
                continue;
            }
            if (in_array($rule['transform'], ['multiply', 'divide'], true)) {
                if (!is_int($value) || $value < 0 || ($rule['transform'] === 'divide' && $value % $rule['factor'] !== 0) || ($rule['transform'] === 'multiply' && $value > intdiv(PHP_INT_MAX, $rule['factor']))) $this->fail('单位转换不能无损转换为整数：' . $rule['source']);
                $value = $rule['transform'] === 'multiply' ? $value * $rule['factor'] : intdiv($value, $rule['factor']);
            } elseif ($rule['transform'] === 'string') {
                if (!is_scalar($value)) $this->fail('数组参数不能转换为文本');
                $value = (string) $value;
            } elseif ($rule['transform'] === 'lookup') {
                if (!is_scalar($value) || !array_key_exists((string) $value, $rule['lookup'])) $this->fail('枚举参数没有对应值：' . $rule['source']);
                $value = $rule['lookup'][(string) $value];
            }
            $cursor = &$payload; $segments = explode('.', $rule['target']); $leaf = array_pop($segments);
            foreach ($segments as $segment) { $cursor[$segment] ??= []; $cursor = &$cursor[$segment]; }
            $cursor[$leaf] = $value; unset($cursor);
        }
        return $payload;
    }

    /** @param string $message 参数校验原因 @return never */
    private function fail(string $message): never
    {
        throw new BusinessException($message, ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => ['parameter_mapping' => [$message]]]);
    }
}
