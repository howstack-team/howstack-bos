<?php

declare(strict_types=1);

namespace app\modules\message_configuration\validation;

use app\exception\BusinessException;
use app\modules\message_configuration\domain\MessageTypeCatalog;

/** 批量只接受渠道引用和启用状态，不允许覆盖模板或机器人凭据。 */
final class BatchMessageConfigurationValidator
{
    /** @return array<string, mixed> 固定受众下、去重并按编码排序的批量操作 */
    public function values(string $audience, array $input): array
    {
        MessageTypeCatalog::all($audience);
        $items = $input['items'] ?? null;
        $channel = $input['channel'] ?? null;
        if (array_diff(array_keys($input), ['items', 'channel', 'channel_id', 'enabled']) !== []
            || !is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > 30
            || !is_string($channel) || !isset(MessageTypeCatalog::CHANNELS[$audience][$channel])) {
            throw new BusinessException('批量配置参数无效');
        }
        if (!array_key_exists('enabled', $input) && !array_key_exists('channel_id', $input)) {
            throw new BusinessException('请选择发送通道或调整启用状态');
        }
        if (array_key_exists('enabled', $input) && !is_bool($input['enabled'])) {
            throw new BusinessException('启用状态必须为布尔值');
        }
        if (array_key_exists('channel_id', $input)
            && (!in_array($channel, ['email', 'sms'], true) || !is_int($input['channel_id'])
                || $input['channel_id'] < 1 || $input['channel_id'] > 2147483647)) {
            throw new BusinessException('发送通道无效');
        }
        $codes = [];
        foreach ($items as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['code', 'revision']) !== []
                || !is_string($item['code'] ?? null) || !is_int($item['revision'] ?? null)
                || $item['revision'] < 0 || $item['revision'] >= 2147483647 || isset($codes[$item['code']])) {
                throw new BusinessException('消息选择或修订号无效');
            }
            $type = MessageTypeCatalog::get($audience, $item['code']);
            if (!in_array($channel, $type['channels'], true)) {
                throw new BusinessException($type['name'] . '不支持所选渠道');
            }
            $codes[$item['code']] = true;
        }
        usort($items, static fn (array $left, array $right): int => strcmp($left['code'], $right['code']));
        return array_replace($input, ['items' => $items]);
    }
}
