<?php

declare(strict_types=1);

namespace app\modules\system_settings\domain;

/** 系统统一配置的三种管理员消息机器人。 */
final class NotificationRobotDefinition
{
    public const LABELS = ['dingtalk' => '钉钉', 'wecom' => '企业微信', 'feishu' => '飞书'];
    public const SETTING_KEY = 'notifications.robots_encrypted';

    /** @return list<string> 当前平台允许设置的秘密字段 */
    public static function keys(string $channel): array
    {
        return $channel === 'wecom' ? ['webhook_url'] : ['webhook_url', 'sign_secret'];
    }

    /** @return array<string, array<string, string>> 未配置的机器人 */
    public static function defaults(): array
    {
        $robots = [];
        foreach (array_keys(self::LABELS) as $channel) {
            $robots[$channel] = array_fill_keys(self::keys($channel), '');
        }
        return $robots;
    }

    public static function validWebhook(string $channel, string $url): bool
    {
        $pattern = match ($channel) {
            'dingtalk' => '~^https://oapi\.dingtalk\.com/robot/send\?access_token=[a-zA-Z0-9_-]{1,256}$~D',
            'wecom' => '~^https://qyapi\.weixin\.qq\.com/cgi-bin/webhook/send\?key=[a-zA-Z0-9_-]{1,256}$~D',
            'feishu' => '~^https://open\.feishu\.cn/open-apis/bot/v2/hook/[a-zA-Z0-9_-]{1,256}$~D',
            default => '',
        };
        return $pattern !== '' && preg_match($pattern, $url) === 1;
    }
}
