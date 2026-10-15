<?php

declare(strict_types=1);

namespace plugins\mail\aliyun_dm;

use app\shared\plugin\contract\MailAdapterInterface;
use app\shared\plugin\contract\HttpClientInterface;
use app\shared\plugin\configuration\ChannelFields as Fields;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\mail\MailMessage;
use app\shared\plugin\dto\mail\MailResult;
use app\shared\plugin\http\HttpClient;
use Throwable;
use app\shared\plugin\cloud\AlibabaRpc;
use AlibabaCloud\SDK\Dm\V20151123\Models\SingleSendMailRequest;

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
        return new Configuration([
            'access_key_id' => Fields::text($v, 'access_key_id', 128),
            'access_key_secret' => Fields::text($v, 'access_key_secret', 128),
            'from_name' => Fields::text($v, 'from_name', 128),
            'from_email' => Fields::email($v, 'from_email'),
            'region' => Fields::choice($v, 'region', ['cn-hangzhou', 'ap-southeast-1', 'ap-southeast-2']),
        ]);
    }

    /** @inheritDoc */
    public function send(MailMessage $message, Configuration $configuration): MailResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        $host = $c['region'] === 'cn-hangzhou' ? 'dm.aliyuncs.com' : 'dm.' . $c['region'] . '.aliyuncs.com';
        $request = new SingleSendMailRequest([
            'accountName' => $c['from_email'], 'addressType' => 1, 'replyToAddress' => false,
            'toAddress' => $message->recipient, 'subject' => $message->subject,
            'textBody' => $message->text, 'htmlBody' => $message->html, 'fromAlias' => $c['from_name'],
        ]);
        try {
            $reply = (new AlibabaRpc($this->http))->request($host, '2015-11-23', 'SingleSendMail', $request, $c['access_key_id'], $c['access_key_secret']);
            $status = is_string($reply['EnvId'] ?? null) && $reply['EnvId'] !== ''
                ? 'accepted' : (isset($reply['Code']) ? 'failed' : 'unknown');
            return new MailResult($status, $message->messageId());
        } catch (Throwable) {
            return new MailResult('unknown', $message->messageId());
        }
    }
}
