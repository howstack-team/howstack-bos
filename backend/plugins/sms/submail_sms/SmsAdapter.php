<?php

declare(strict_types=1);

namespace plugins\sms\submail_sms;

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
            'app_id' => Fields::text($v, 'app_id', 128),
            'app_key' => Fields::text($v, 'app_key', 128),
        ]);
    }

    /** @inheritDoc */
    public function send(SmsMessage $message, Configuration $configuration): SmsResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        if (!preg_match('/^\+861[0-9]{10}$/D', $message->recipient) || $message->templateId === ''
            || ($message->parameters !== [] && array_is_list($message->parameters))) {
            throw new PluginException('赛邮需要中国大陆手机号、项目编号及按名称提供的参数', 'invalid_request');
        }
        $data = [
            'appid' => $c['app_id'], 'to' => substr($message->recipient, 3), 'project' => $message->templateId,
            'timestamp' => (string) time(), 'sign_type' => 'md5', 'sign_version' => '2',
        ];
        ksort($data);
        $canonical = [];
        foreach ($data as $key => $value) {
            $canonical[] = $key . '=' . $value;
        }
        $salt = $c['app_id'] . $c['app_key'];
        $data['signature'] = md5($salt . implode('&', $canonical) . $salt);
        $data['vars'] = json_encode((object) $message->parameters, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        try {
            $response = $this->http->postForm('https://api-v4.mysubmail.com/sms/xsend.json', $data);
            $reply = $response->json();
            $status = $response->isSuccessful() && ($reply['status'] ?? '') === 'success'
                ? 'accepted' : (($reply['status'] ?? '') === 'error' ? 'failed' : 'unknown');
            return new SmsResult($status, $message->key, $status === 'accepted' ? ($reply['send_id'] ?? null) : null);
        } catch (Throwable) {
            return new SmsResult('unknown', $message->key);
        }
    }
}
