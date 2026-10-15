<?php

declare(strict_types=1);

namespace app\modules\message_configuration\validation;

use app\exception\BusinessException;
use app\modules\message_configuration\domain\MessageTypeCatalog;
use app\shared\http\ApiErrorCode;

/** 渠道白名单、有限模板变量及官方机器人地址规则；不执行模板代码或外部请求。 */
final class MessageConfigurationValidator
{
    /** @return array{revision: int, configurations: array<string, mixed>} 校验消息模板和渠道启用字段 */
    public function values(array $type, array $input): array
    {
        if (array_diff(array_keys($input), ['revision', 'configurations']) !== []
            || !is_int($input['revision'] ?? null) || $input['revision'] < 0 || $input['revision'] >= 2147483647
            || !is_array($input['configurations'] ?? null)
            || array_diff(array_keys($input['configurations']), $type['channels']) !== []
            || array_diff($type['channels'], array_keys($input['configurations'])) !== []) {
            $this->fail('消息配置参数无效');
        }
        $result = [];
        foreach ($type['channels'] as $channel) {
            $defaults = MessageTypeCatalog::defaults($type, $channel);
            $values = $input['configurations'][$channel];
            if (!is_array($values) || array_diff(array_keys($values), array_keys($defaults)) !== []) {
                $this->fail('渠道配置字段无效', $channel);
            }
            $values = array_replace($defaults, $values);
            if (!is_bool($values['enabled'])) {
                $this->fail('启用状态必须为布尔值', $channel);
            }
            foreach ($values as $key => $value) {
                if ($key === 'enabled') {
                    continue;
                }
                if ($key === 'channel_id') {
                    if (!is_int($value) || $value < 0 || $value > 2147483647) {
                        $this->fail('请选择有效发送通道', $channel, $key);
                    }
                    continue;
                }
                $limit = match ($key) {
                    'title', 'template_id' => 128,
                    'variables' => 4096, default => 4000,
                };
                if (!is_string($value) || mb_strlen($value) > $limit
                    || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
                    $this->fail('字段内容或长度无效', $channel, $key);
                }
                $values[$key] = trim($value);
                if (in_array($key, ['title', 'body'], true)) {
                    $this->template($values[$key], $type, $channel, $key);
                }
            }
            if (isset($values['variables'])) {
                try {
                    $mapping = json_decode($values['variables'], false, 8, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $this->fail('变量映射必须是 JSON 对象', $channel, 'variables');
                }
                if (!$mapping instanceof \stdClass || count((array) $mapping) > 30) {
                    $this->fail('变量映射必须是最多30项的 JSON 对象', $channel, 'variables');
                }
                foreach ((array) $mapping as $key => $value) {
                    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.]{0,63}$/D', (string) $key) || !is_string($value) || mb_strlen($value) > 512) {
                        $this->fail('变量映射的键和值无效', $channel, 'variables');
                    }
                    $this->template($value, $type, $channel, 'variables');
                }
            }
            if ($values['enabled'] && ($type['group_enabled'] ?? true)) {
                foreach (array_intersect(['title', 'body'], array_keys($values)) as $key) {
                    if ($values[$key] === '') {
                        $this->fail('启用前请完整填写渠道配置', $channel, $key);
                    }
                }
                if ($channel === 'wechat' && $values['template_id'] === '') {
                    $this->fail('请填写公众号模板 ID', $channel, 'template_id');
                }
            }
            $result[$channel] = $values;
        }
        return ['revision' => $input['revision'], 'configurations' => $result];
    }

    /** 只接受当前事件的占位变量，不接受 Twig/PHP 表达式。 */
    private function template(string $value, array $type, string $channel, string $key): void
    {
        preg_match_all('/\{\{(.*?)\}\}/s', $value, $matches);
        foreach ($matches[1] as $variable) {
            if (!in_array($variable, array_column($type['variables'], 'key'), true)) {
                $this->fail('模板包含当前消息不支持的变量', $channel, $key);
            }
        }
        $plain = preg_replace('/\{\{.*?\}\}/s', '', $value) ?? '';
        if (str_contains($plain, '{{') || str_contains($plain, '}}') || str_contains($value, '{%')) {
            $this->fail('模板只能使用列出的占位变量', $channel, $key);
        }
    }

    /** @return never 带表单字段路径的安全校验失败 */
    private function fail(string $message, ?string $channel = null, ?string $key = null): never
    {
        $data = $channel === null || $key === null ? null : ['errors' => ['configurations.' . $channel . '.' . $key => [$message]]];
        throw new BusinessException($message, ApiErrorCode::VALIDATION_FAILED, 422, $data);
    }
}
