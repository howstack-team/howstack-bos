<?php

declare(strict_types=1);

namespace app\modules\message\infrastructure;

use app\exception\BusinessException;
use app\modules\channel\application\ChannelExecutionConfiguration;
use app\modules\channel\contract\TransactionAdapterFactoryInterface;
use app\modules\channel\contract\mail\MailAdapterExecutorInterface;
use app\modules\message\contract\MessageTransportInterface;
use app\modules\system_settings\domain\NotificationRobotDefinition;
use app\shared\plugin\dto\mail\MailMessage;
use app\shared\plugin\dto\sms\SmsMessage;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use Throwable;

/** 宿主固定插件边界；配置修订变化则停止，不把旧任务切到新通道。 */
final readonly class InstalledMessageTransport implements MessageTransportInterface
{
    public function __construct(
        private ChannelExecutionConfiguration $configuration,
        private MailAdapterExecutorInterface $mail,
        private TransactionAdapterFactoryInterface $adapters,
        private ClientInterface $http,
        private \app\modules\system_settings\contract\NotificationRobotSettingsRepositoryInterface $robots,
    ) {
    }

    public function send(string $channel, string $requestKey, array $payload): array
    {
        if (isset(NotificationRobotDefinition::LABELS[$channel])) {
            return $this->robot($channel, $payload);
        }
        if (!in_array($channel, ['email', 'sms'], true)) {
            return ['status' => 'failed', 'error' => 'channel_not_integrated', 'retry' => false];
        }
        // DTO和通道预检在外部请求之前执行，失败明确没有发送副作用。
        try {
            [$pluginId, $configuration] = $this->configuration->resolve($channel === 'email' ? 'mail' : 'sms',
                (int) $payload['channel_id'], (int) $payload['channel_revision'], true);
            $address = $payload['address'];
            if ($channel === 'sms' && preg_match('/^1[3-9][0-9]{9}$/D', $address)) {
                $address = '+86' . $address;
            }
            $message = $channel === 'email'
                ? new MailMessage($requestKey, $address, $payload['title'], $payload['body'])
                : new SmsMessage($requestKey, $address, $payload['template_id'], $payload['parameters'], $payload['body']);
            $sms = $channel === 'sms' ? $this->adapters->sms($pluginId) : null;
        } catch (BusinessException | InvalidArgumentException) {
            return ['status' => 'failed', 'error' => 'channel_or_recipient_invalid', 'retry' => false];
        }
        try {
            $result = $channel === 'email' ? $this->mail->send($pluginId, $configuration, $message) : $sms->send($message, $configuration);
            if (($channel === 'email' && $result->messageId !== $message->messageId()) || ($channel === 'sms' && $result->key !== $requestKey)) {
                return ['status' => 'unknown', 'error' => 'provider_request_mismatch', 'retry' => false];
            }
            return ['status' => $result->status, 'error' => $result->status === 'accepted' ? '' : 'provider_' . $result->status,
                'retry' => $result->status === 'failed'];
        } catch (Throwable) {
            return ['status' => 'unknown', 'error' => 'transport_outcome_unknown', 'retry' => false];
        }
    }

    private function robot(string $channel, array $payload): array
    {
        if ($this->robots->get()['revision'] !== ($payload['robot_revision'] ?? -1)) {
            return ['status' => 'failed', 'error' => 'robot_configuration_changed', 'retry' => false];
        }
        $robot = $payload['robot'] ?? [];
        $url = $robot['webhook_url'] ?? '';
        if (!NotificationRobotDefinition::validWebhook($channel, $url)) {
            return ['status' => 'failed', 'error' => 'robot_not_configured', 'retry' => false];
        }
        $text = $payload['title'] . "\n" . $payload['body'];
        $body = $channel === 'feishu' ? ['msg_type' => 'text', 'content' => ['text' => $text]]
            : ['msgtype' => 'text', 'text' => ['content' => $text]];
        $secret = $robot['sign_secret'] ?? '';
        if ($secret !== '') {
            if ($channel === 'dingtalk') {
                $timestamp = (string) (int) (microtime(true) * 1000);
                $sign = base64_encode(hash_hmac('sha256', $timestamp . "\n" . $secret, $secret, true));
                $url .= '&' . http_build_query(['timestamp' => $timestamp, 'sign' => $sign], '', '&', PHP_QUERY_RFC3986);
            } elseif ($channel === 'feishu') {
                $body['timestamp'] = (string) time();
                $body['sign'] = base64_encode(hash_hmac('sha256', '', $body['timestamp'] . "\n" . $secret, true));
            }
        }
        try {
            $response = $this->http->request('POST', $url, ['json' => $body, 'timeout' => 10, 'connect_timeout' => 3,
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false]);
            if ($response->getStatusCode() !== 200) {
                return ['status' => 'unknown', 'error' => 'robot_http_outcome_unknown', 'retry' => false];
            }
            $raw = (string) $response->getBody();
            if (strlen($raw) > 65536) {
                return ['status' => 'unknown', 'error' => 'robot_response_invalid', 'retry' => false];
            }
            $result = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            $code = $result[$channel === 'feishu' ? 'code' : 'errcode'] ?? null;
            if (!is_int($code)) {
                return ['status' => 'unknown', 'error' => 'robot_response_invalid', 'retry' => false];
            }
            return ['status' => $code === 0 ? 'accepted' : 'failed', 'error' => $code === 0 ? '' : 'robot_rejected', 'retry' => false];
        } catch (Throwable) {
            return ['status' => 'unknown', 'error' => 'robot_outcome_unknown', 'retry' => false];
        }
    }
}
