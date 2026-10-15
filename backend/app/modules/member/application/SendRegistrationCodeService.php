<?php

declare(strict_types=1);

namespace app\modules\member\application;

use app\exception\BusinessException;
use app\modules\member\contract\{MemberRegistrationRepositoryInterface, RegistrationRateLimiterInterface};
use app\modules\message\contract\MessageTransportInterface;
use app\modules\message\domain\MessageScene;
use app\modules\message_configuration\contract\MessageConfigurationRepositoryInterface;
use app\modules\message_configuration\domain\{MessageTypeCatalog, MessageGroups};
use app\modules\system_settings\application\GetSystemSettingsService;
use app\shared\http\ApiErrorCode;

/** 验证码独立即时发送，不进入普通通知队列、模板日志或重试消息。 */
final readonly class SendRegistrationCodeService
{
    public function __construct(
        private GetSystemSettingsService $settings,
        private MemberRegistrationRepositoryInterface $members,
        private MessageConfigurationRepositoryInterface $configurations,
        private MessageTransportInterface $transport,
        private RegistrationRateLimiterInterface $limiter,
        private string $secret,
    ) {
    }

    public function digest(string $value): string
    {
        if (strlen($this->secret) < 32) throw new BusinessException('注册安全配置不可用', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
        return hash_hmac('sha256', $value, $this->secret);
    }

    public function send(string $method, string $target, string $binding, string $ip): array
    {
        $policy = $this->settings->registration();
        if (!$policy[$method . '_enabled'] || !$policy[$method . '_verification']) throw new BusinessException('此注册方式未开启验证码验证', ApiErrorCode::FORBIDDEN, 403);
        $this->limiter->check('code', $method . ':' . $target, $ip);
        $channel = $method === 'email' ? 'email' : 'sms';
        $type = MessageTypeCatalog::get('customer', 'register_verification');
        $stored = $this->configurations->get('customer', 'register_verification');
        $selected = null;
        foreach (MessageGroups::fromStored('customer', $type, $stored['configurations']) as $group) {
            $config = $group['configurations'][$channel] ?? [];
            if ($group['kind'] === 'customer' && $group['enabled'] && ($config['enabled'] ?? false)) { $selected = $config; break; }
        }
        if (!$selected || empty($selected['channel_id']) || !str_contains(json_encode($selected, JSON_THROW_ON_ERROR), '{{code}}')) {
            throw new BusinessException('注册验证码暂不可用，请联系管理员检查消息模板和发送通道', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
        }
        $id = bin2hex(random_bytes(16)); $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->members->issueChallenge(['challenge_id' => $id, 'method' => $method,
            'target_hash' => $this->digest('register:' . $method . ':' . $target), 'binding_hash' => $this->digest($binding),
            'code_hash' => $this->digest($id . ':' . $code), 'status' => 'pending', 'attempts' => 0,
            'expires_at' => date('Y-m-d H:i:s', time() + 300), 'created_at' => date('Y-m-d H:i:s')]);
        $variables = ['code' => $code, 'expires_in' => '5', 'site_name' => $this->settings->base()['site_title'], 'customer_name' => '会员'];
        $payload = ['address' => $target, 'title' => MessageScene::render($selected['title'] ?? '注册验证码', $variables),
            'body' => MessageScene::render($selected['body'], $variables), 'template_id' => $selected['template_id'] ?? '',
            'parameters' => [], 'channel_id' => $selected['channel_id'], 'channel_revision' => $selected['channel_revision'] ?? 0];
        foreach (json_decode($selected['variables'] ?? '{}', true, 8, JSON_THROW_ON_ERROR) as $key => $template) $payload['parameters'][$key] = MessageScene::render($template, $variables);
        $accepted = false;
        try {
            $result = $this->transport->send($channel, 'register-code:' . $id, $payload);
            $accepted = in_array($result['status'], ['accepted', 'sent', 'delivered'], true);
        } catch (\Throwable) {
            // 不回显厂商报文或敏感内容；未知结果不自动重试。
        }
        $this->members->activateChallenge($id, $accepted);
        if (!$accepted) throw new BusinessException('验证码发送失败，请稍后重试或联系管理员', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
        return ['challenge_id' => $id, 'expires_in' => 300, 'retry_after' => 60];
    }

    public function verify(string $id, string $method, string $target, string $binding, string $code): void
    {
        if (!$this->members->consumeChallenge($id, $method, $this->digest('register:' . $method . ':' . $target), $this->digest($binding), $this->digest($id . ':' . $code))) {
            throw new BusinessException('验证码错误、已使用或已过期，请重新获取', ApiErrorCode::VALIDATION_FAILED, 422);
        }
    }
}
