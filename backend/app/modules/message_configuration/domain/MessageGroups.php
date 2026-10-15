<?php

declare(strict_types=1);

namespace app\modules\message_configuration\domain;

/** 接收组是策略边界；旧配置按需转换，不改历史任务和发送幂等标识。 */
final class MessageGroups
{
    public const KINDS = ['customer' => '关联客户', 'admin' => '内部人员', 'robot' => '群机器人'];

    public static function audience(string $kind): string
    {
        return $kind === 'customer' ? 'customer' : 'admin';
    }

    /** @return list<string> 同一场景中当前接收组允许的渠道 */
    public static function channels(array $type, string $kind): array
    {
        $allowed = match ($kind) {
            'customer' => ['email', 'sms', 'message', 'wechat'],
            'admin' => ['email', 'message'],
            'robot' => ['dingtalk', 'wecom', 'feishu'],
            default => [],
        };
        return array_values(array_intersect($type['channels'], $allowed));
    }

    public static function create(array $type, string $kind, string $id, string $name): array
    {
        $configs = [];
        foreach (self::channels($type, $kind) as $channel) {
            $configs[$channel] = MessageTypeCatalog::defaults($type, $channel);
        }
        return ['id' => $id, 'name' => $name, 'kind' => $kind, 'enabled' => true,
            'recipients' => ['mode' => 'founder', 'ids' => []], 'configurations' => $configs];
    }

    /** @return list<array<string, mixed>> 新结构优先；旧的管理员渠道拆成个人接收与机器人接收。 */
    public static function fromStored(string $audience, array $type, array $configurations): array
    {
        if (isset($configurations['_groups']) && is_array($configurations['_groups'])) {
            return $configurations['_groups'];
        }
        $groups = [];
        foreach ($audience === 'customer' ? ['customer'] : ['admin', 'robot'] as $kind) {
            $group = self::create($type, $kind, $kind, self::KINDS[$kind]);
            if ($kind === 'admin') {
                $group['recipients'] = $configurations['_recipients'] ?? $group['recipients'];
            }
            foreach ($group['configurations'] as $channel => $defaults) {
                // 保留服务端记录的通道修订，实际发布仍据此核验。
                $group['configurations'][$channel] = array_replace($defaults, $configurations[$channel] ?? []);
            }
            $groups[] = $group;
        }
        return $groups;
    }

    public static function enabled(array $group): bool
    {
        return $group['enabled'] && array_any($group['configurations'], static fn (array $config): bool => $config['enabled']);
    }
}
