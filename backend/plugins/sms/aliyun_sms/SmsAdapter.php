<?php

declare(strict_types=1);

namespace plugins\sms\aliyun_sms;

use app\shared\plugin\contract\SmsAdapterInterface;
use app\shared\plugin\contract\HttpClientInterface;
use app\shared\plugin\configuration\ChannelFields as Fields;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\sms\SmsMessage;
use app\shared\plugin\dto\sms\SmsResult;
use app\shared\plugin\exception\PluginException;
use app\shared\plugin\http\HttpClient;
use Throwable;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\SendSmsRequest;
use app\shared\plugin\cloud\AlibabaRpc;

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
            'access_key_id' => Fields::text($v, 'access_key_id', 128),
            'access_key_secret' => Fields::text($v, 'access_key_secret', 128),
            'sign_name' => Fields::text($v, 'sign_name', 128),
        ]);
    }

    /** @inheritDoc */
    public function send(SmsMessage $message, Configuration $configuration): SmsResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        if (!preg_match('/^\+861[0-9]{10}$/D', $message->recipient) || $message->templateId === ''
            || ($message->parameters !== [] && array_is_list($message->parameters))) {
            throw new PluginException('阿里云国内短信需要中国大陆手机号、模板编号及按名称提供的参数', 'invalid_request');
        }
        $request = new SendSmsRequest([
            'phoneNumbers' => $message->recipient,
            'signName' => $c['sign_name'],
            'templateCode' => $message->templateId,
            'templateParam' => json_encode((object) $message->parameters, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'outId' => $message->key,
        ]);
        try {
            $data = (new AlibabaRpc($this->http))->request('dysmsapi.aliyuncs.com', '2017-05-25', 'SendSms', $request, $c['access_key_id'], $c['access_key_secret']);
            $status = ($data['Code'] ?? '') === 'OK' ? 'accepted' : (isset($data['Code']) ? 'failed' : 'unknown');
            return new SmsResult($status, $message->key, $status === 'accepted' ? ($data['BizId'] ?? null) : null);
        } catch (Throwable) {
            return new SmsResult('unknown', $message->key);
        }
    }
}
