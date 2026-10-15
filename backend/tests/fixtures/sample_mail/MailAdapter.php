<?php

declare(strict_types=1);

namespace plugins\mail\sample_mail;

use app\shared\plugin\contract\MailAdapterInterface;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\mail\MailMessage;
use app\shared\plugin\dto\mail\MailResult;
use app\shared\plugin\http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;

/** 仅用于证明 HTTPS 邮件插件契约的测试包；MockHandler 不产生外部请求。 */
final readonly class MailAdapter implements MailAdapterInterface
{
    /** @inheritDoc */
    public function validateConfiguration(Configuration $configuration): Configuration
    {
        if (!str_starts_with($configuration->values['api_url'] ?? '', 'https://')
            || ($configuration->values['api_key'] ?? '') === '') {
            throw new InvalidArgumentException('邮件平台配置无效');
        }
        return $configuration;
    }

    /** @inheritDoc */
    public function send(MailMessage $message, Configuration $configuration): MailResult
    {
        $config = $this->validateConfiguration($configuration)->values;
        $http = new HttpClient(new Client(['handler' => new MockHandler([new Response(202)])]));
        $response = $http->post($config['api_url'], [
            'email' => $message->recipient,
            'subject' => $message->subject,
            'content' => $message->text,
        ], ['Authorization' => 'Bearer ' . $config['api_key']]);
        return new MailResult($response->status === 202 ? 'accepted' : 'failed', $message->messageId());
    }
}
