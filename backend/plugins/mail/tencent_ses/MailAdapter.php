<?php

declare(strict_types=1);

namespace plugins\mail\tencent_ses;

use app\shared\plugin\contract\MailAdapterInterface;
use app\shared\plugin\contract\HttpClientInterface;
use app\shared\plugin\configuration\ChannelFields as Fields;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\mail\MailMessage;
use app\shared\plugin\dto\mail\MailResult;
use app\shared\plugin\http\HttpClient;
use Throwable;
use app\shared\plugin\cloud\TencentCloudApi;
use app\shared\plugin\exception\PluginException;
use TencentCloud\Ses\V20201002\Models\SendEmailRequest;

/** 每次创建请求，只返回受理状态，不保存令牌或平台报文。 */
final readonly class MailAdapter implements MailAdapterInterface
{
    /**
     * @param HttpClientInterface $http 宿主 HTTP 能力
     */
    public function __construct(private HttpClientInterface $http = new HttpClient())
    {
    }

    /** @inheritDoc */
    public function validateConfiguration(Configuration $configuration): Configuration
    {
        $v = $configuration->values;
        if (!is_int($v['template_id'] ?? null) || $v['template_id'] < 1 || $v['template_id'] > 2147483647) {
            throw new PluginException('腾讯云邮件模板 ID 无效', 'invalid_configuration');
        }
        return new Configuration([
            'secret_id' => Fields::text($v, 'secret_id'), 'secret_key' => Fields::text($v, 'secret_key'),
            'region' => Fields::region($v), 'from_email' => Fields::email($v, 'from_email'), 'template_id' => $v['template_id'],
        ]);
    }

    /** @inheritDoc */
    public function send(MailMessage $message, Configuration $configuration): MailResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        $request = new SendEmailRequest();
        $request->fromJsonString(json_encode([
            'FromEmailAddress' => $c['from_email'], 'Destination' => [$message->recipient],
            'Subject' => $message->subject, 'SmtpMessageId' => $message->messageId(),
            'Template' => ['TemplateID' => $c['template_id'], 'TemplateData' => json_encode([
                'text' => $message->text, 'html' => $message->html, 'subject' => $message->subject,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        ], JSON_THROW_ON_ERROR));
        try {
            $data = (new TencentCloudApi($this->http))->request('ses', '2020-10-02', 'SendEmail', $request, $c['secret_id'], $c['secret_key'], $c['region']);
            $status = isset($data['Error']) ? 'failed' : (is_string($data['MessageId'] ?? null) && $data['MessageId'] !== '' ? 'accepted' : 'unknown');
            return new MailResult($status, $message->messageId());
        } catch (Throwable) {
            return new MailResult('unknown', $message->messageId());
        }
    }
}
