<?php

declare(strict_types=1);

namespace app\modules\system_settings\validation;

use app\exception\BusinessException;
use app\modules\system_settings\domain\NotificationRobotDefinition;
use app\shared\http\ApiErrorCode;

/** 固定平台与字段；空输入保留原秘密，显式清除和替换分别处理。 */
final class NotificationRobotSettingsValidator
{
    /** @return array{revision: int, robots: array<string, array<string, string>>} 校验后的完整配置 */
    public function values(array $input, array $previous): array
    {
        $channels = array_keys(NotificationRobotDefinition::LABELS);
        if (array_diff(array_keys($input), ['revision', 'robots', 'clear_secrets']) !== []
            || !is_int($input['revision'] ?? null) || $input['revision'] < 0 || $input['revision'] >= 2147483647
            || !is_array($input['robots'] ?? null)
            || array_diff(array_keys($input['robots']), $channels) !== []
            || array_diff($channels, array_keys($input['robots'])) !== []
            || !is_array($input['clear_secrets'] ?? [])
            || array_diff(array_keys($input['clear_secrets'] ?? []), $channels) !== []) {
            $this->fail('机器人设置参数无效');
        }
        $robots = [];
        foreach ($channels as $channel) {
            $keys = NotificationRobotDefinition::keys($channel);
            $values = $input['robots'][$channel];
            $clear = $input['clear_secrets'][$channel] ?? [];
            if (!is_array($values) || array_diff(array_keys($values), $keys) !== []
                || !is_array($clear) || !array_is_list($clear)
                || array_filter($clear, static fn (mixed $key): bool => !is_string($key)) !== []
                || array_diff($clear, $keys) !== []) {
                $this->fail('机器人设置字段无效');
            }
            foreach ($keys as $key) {
                $value = $values[$key] ?? '';
                if (!is_string($value) || mb_strlen($value) > ($key === 'webhook_url' ? 512 : 256)
                    || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    $this->fail('字段内容或长度无效', 'robots.' . $channel . '.' . $key);
                }
                $value = trim($value);
                if (in_array($key, $clear, true)) {
                    $value = '';
                } elseif ($value === '') {
                    $value = $previous[$channel][$key] ?? '';
                }
                if ($key === 'webhook_url' && $value !== '' && !NotificationRobotDefinition::validWebhook($channel, $value)) {
                    $this->fail('请填写当前平台的官方 HTTPS 机器人 Webhook 地址', 'robots.' . $channel . '.' . $key);
                }
                $robots[$channel][$key] = $value;
            }
        }
        return ['revision' => $input['revision'], 'robots' => $robots];
    }

    private function fail(string $message, ?string $field = null): never
    {
        throw new BusinessException($message, ApiErrorCode::VALIDATION_FAILED, 422,
            $field === null ? null : ['errors' => [$field => [$message]]]);
    }
}
