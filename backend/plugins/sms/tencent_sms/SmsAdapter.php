<?php

declare(strict_types=1);

namespace plugins\sms\tencent_sms;

use app\shared\plugin\contract\SmsAdapterInterface;
use app\shared\plugin\contract\HttpClientInterface;
use app\shared\plugin\configuration\ChannelFields as Fields;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\sms\SmsMessage;
use app\shared\plugin\dto\sms\SmsResult;
use app\shared\plugin\exception\PluginException;
use app\shared\plugin\http\HttpClient;
use Throwable;
use TencentCloud\Sms\V20210111\Models\SendSmsRequest;
use app\shared\plugin\cloud\TencentCloudApi;

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
            'secret_id' => Fields::text($v, 'secret_id', 128),
            'secret_key' => Fields::text($v, 'secret_key', 128),
            'sdk_app_id' => Fields::text($v, 'sdk_app_id', 128),
            'sign_name' => Fields::text($v, 'sign_name', 128),
            'region' => Fields::region($v),
        ]);
    }

    /** @inheritDoc */
    public function send(SmsMessage $message, Configuration $configuration): SmsResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        if ($message->templateId === '' || !array_is_list($message->parameters)) {
            throw new PluginException('腾讯云短信需要模板编号及按模板顺序提供的参数', 'invalid_request');
        }
        $request = new SendSmsRequest();
        $request->fromJsonString(json_encode([
            'PhoneNumberSet' => [$message->recipient], 'SmsSdkAppId' => $c['sdk_app_id'],
            'SignName' => $c['sign_name'], 'TemplateId' => $message->templateId,
            'TemplateParamSet' => $message->parameters, 'SessionContext' => $message->key,
        ], JSON_THROW_ON_ERROR));
        try {
            $data = (new TencentCloudApi($this->http))->request('sms', '2021-01-11', 'SendSms', $request, $c['secret_id'], $c['secret_key'], $c['region']);
            $entry = $data['SendStatusSet'][0] ?? null;
            if (isset($data['Error'])) {
                return new SmsResult('failed', $message->key);
            }
            if (!is_array($entry) || count($data['SendStatusSet']) !== 1 || ($entry['PhoneNumber'] ?? '') !== $message->recipient) {
                return new SmsResult('unknown', $message->key);
            }
            $status = ($entry['Code'] ?? '') === 'Ok' ? 'accepted' : (isset($entry['Code']) ? 'failed' : 'unknown');
            return new SmsResult($status, $message->key, $status === 'accepted' ? ($entry['SerialNo'] ?? null) : null);
        } catch (Throwable) {
            return new SmsResult('unknown', $message->key);
        }
    }
}
