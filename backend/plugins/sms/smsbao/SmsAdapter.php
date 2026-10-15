<?php

declare(strict_types=1);

namespace plugins\sms\smsbao;

use app\shared\plugin\contract\SmsAdapterInterface;
use app\shared\plugin\contract\HttpClientInterface;
use app\shared\plugin\configuration\ChannelFields as Fields;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\sms\SmsMessage;
use app\shared\plugin\dto\sms\SmsResult;
use app\shared\plugin\exception\PluginException;
use app\shared\plugin\http\HttpClient;
use Throwable;
/** 单条发送；平台受理不代表最终送达，宿主持久化事件并防止重复提交。 */
final readonly class SmsAdapter implements SmsAdapterInterface
{
    /**
     * @param HttpClientInterface $http 宿主受限 HTTP 能力
     */
    public function __construct(private HttpClientInterface $http = new HttpClient())
    {
    }
    /** @inheritDoc */
    public function validateConfiguration(Configuration $configuration): Configuration
    {
        $v = $configuration->values;
        return new Configuration([
            'username' => Fields::text($v, 'username', 128),
            'api_key' => Fields::text($v, 'api_key', 128),
        ]);
    }

    /** @inheritDoc */
    public function send(SmsMessage $message, Configuration $configuration): SmsResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        if (!preg_match('/^\+861[0-9]{10}$/D', $message->recipient) || trim($message->text) === '') {
            throw new PluginException('短信宝需要中国大陆手机号及包含已审核签名的完整正文', 'invalid_request');
        }
        try {
            $response = $this->http->get('https://api.smsbao.com/sms', [
                'u' => $c['username'], 'p' => $c['api_key'], 'm' => substr($message->recipient, 3), 'c' => $message->text,
            ]);
            $code = trim($response->body);
            $status = $response->isSuccessful() && $code === '0' ? 'accepted'
                : ($response->isSuccessful() && in_array($code, ['30', '40', '41', '43', '50', '51'], true) ? 'failed' : 'unknown');
            return new SmsResult($status, $message->key);
        } catch (Throwable) {
            // 协议凭据位于查询参数，异常链和完整 URL 均不可进入业务日志。
            return new SmsResult('unknown', $message->key);
        }
    }
}
