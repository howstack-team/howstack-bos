<?php

declare(strict_types=1);

namespace app\modules\member\application;

use app\exception\BusinessException;
use app\modules\channel\application\ChannelExecutionConfiguration;
use app\modules\message\contract\MessageTransportInterface;
use app\modules\message\domain\MessageScene;
use app\modules\message_configuration\contract\MessageConfigurationRepositoryInterface;
use app\modules\message_configuration\domain\{MessageGroups, MessageTypeCatalog};
use app\modules\system_settings\application\GetSystemSettingsService;
use app\shared\http\ApiErrorCode;
use Throwable;

/** 验证码独立发送，复用后台消息模板和通道，不写普通通知日志。 */
final readonly class VerificationMessageSender
{
    public function __construct(private MessageConfigurationRepositoryInterface $configurations,
        private ChannelExecutionConfiguration $channels, private MessageTransportInterface $transport,
        private GetSystemSettingsService $settings)
    {
    }

    /** @return array<string, mixed>|null 验证模板与当前启用通道匹配后才声明可用 */
    public function configuration(string $scene, string $method): ?array
    {
        $channel = $method === 'email' ? 'email' : 'sms';
        $type = MessageTypeCatalog::get('customer', $scene);
        $stored = $this->configurations->get('customer', $scene);
        foreach (MessageGroups::fromStored('customer', $type, $stored['configurations']) as $group) {
            $config = $group['configurations'][$channel] ?? [];
            if ($group['kind'] !== 'customer' || !$group['enabled'] || !($config['enabled'] ?? false)
                || empty($config['channel_id']) || !str_contains(json_encode($config, JSON_THROW_ON_ERROR), '{{code}}')) continue;
            try {
                $this->channels->resolve($channel === 'email' ? 'mail' : 'sms', (int) $config['channel_id'], (int) ($config['channel_revision'] ?? 0), true);
                return $config;
            } catch (BusinessException) {
                continue;
            }
        }
        return null;
    }

    public function send(string $scene, string $method, string $target, string $id, string $code, array $config): bool
    {
        $variables = ['code' => $code, 'expires_in' => '5', 'site_name' => $this->settings->base()['site_title'], 'customer_name' => '会员'];
        $payload = ['address' => $target, 'title' => MessageScene::render($config['title'] ?? '验证码', $variables),
            'body' => MessageScene::render($config['body'], $variables), 'template_id' => $config['template_id'] ?? '',
            'parameters' => [], 'channel_id' => $config['channel_id'], 'channel_revision' => $config['channel_revision'] ?? 0];
        foreach (json_decode($config['variables'] ?? '{}', true, 8, JSON_THROW_ON_ERROR) as $key => $template) {
            $payload['parameters'][$key] = MessageScene::render($template, $variables);
        }
        try {
            $result = $this->transport->send($method === 'email' ? 'email' : 'sms', $scene . ':' . $id, $payload);
            return in_array($result['status'], ['accepted', 'sent', 'delivered'], true);
        } catch (Throwable) {
            return false;
        }
    }
}
