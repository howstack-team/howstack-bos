<?php

declare(strict_types=1);

namespace plugins\captcha\tencent_captcha;

use app\shared\plugin\contract\CaptchaAdapterInterface;
use app\shared\plugin\contract\HttpClientInterface;
use app\shared\plugin\configuration\ChannelFields as Fields;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\captcha\CaptchaContext;
use app\shared\plugin\dto\captcha\CaptchaChallenge;
use app\shared\plugin\dto\captcha\CaptchaProof;
use app\shared\plugin\dto\captcha\CaptchaResult;
use app\shared\plugin\exception\PluginException;
use app\shared\plugin\http\HttpClient;
use Throwable;
use app\shared\plugin\cloud\TencentCloudApi;
use TencentCloud\Captcha\V20190722\Models\DescribeCaptchaResultRequest;

/** 腾讯云天御验证码；无业务权限，不保存连接秘密，不自动重试或异常放行。 */
final readonly class CaptchaAdapter implements CaptchaAdapterInterface
{
    /** @param HttpClientInterface $http 宿主单次 HTTP 能力 */
    public function __construct(private HttpClientInterface $http = new HttpClient())
    {
    }


    /** @inheritDoc */
    public function validateConfiguration(Configuration $configuration): Configuration
    {
        $v = $configuration->values;
        $id = Fields::text($v, 'captcha_app_id');
        if (!preg_match('/^[1-9][0-9]{0,15}$/D', $id)) {
            throw new PluginException('腾讯验证码应用 ID 无效', 'invalid_configuration');
        }
        return new Configuration([
            'captcha_app_id' => $id, 'app_secret_key' => Fields::text($v, 'app_secret_key'),
            'secret_id' => Fields::text($v, 'secret_id'), 'secret_key' => Fields::text($v, 'secret_key'),
        ]);
    }

    /** @inheritDoc */
    public function initialize(CaptchaContext $context, Configuration $configuration): CaptchaChallenge
    {
        $c = $this->validateConfiguration($configuration)->values;
        return new CaptchaChallenge('tencent_captcha', ['appId' => $c['captcha_app_id']]);
    }

    /** @inheritDoc */
    public function fingerprint(CaptchaProof $proof, CaptchaContext $context, Configuration $configuration): string
    {
        $c = $this->validateConfiguration($configuration)->values;
        return hash('sha256', $c['captcha_app_id'] . ':' . $proof->text('ticket', 4096));
    }

    /** @inheritDoc */
    public function verify(CaptchaProof $proof, CaptchaContext $context, Configuration $configuration): CaptchaResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        $ticket = $proof->text('ticket', 4096);
        $randstr = $proof->text('randstr', 128);
        if (str_starts_with($ticket, 'trerror')) {
            return new CaptchaResult('rejected');
        }
        try {
            $model = new DescribeCaptchaResultRequest();
            $model->fromJsonString(json_encode([
                'CaptchaType' => 9, 'Ticket' => $ticket, 'Randstr' => $randstr,
                'UserIp' => $context->clientIp, 'CaptchaAppId' => (int) $c['captcha_app_id'],
                'AppSecretKey' => $c['app_secret_key'],
            ], JSON_THROW_ON_ERROR));
            $data = (new TencentCloudApi($this->http))->request('captcha', '2019-07-22', 'DescribeCaptchaResult', $model, $c['secret_id'], $c['secret_key'], '');
            if (!is_int($data['CaptchaCode'] ?? null)) {
                return new CaptchaResult('unknown');
            }
            return new CaptchaResult($data['CaptchaCode'] === 1 ? 'verified' : 'rejected');
        } catch (Throwable) {
            return new CaptchaResult('unknown');
        }
    }

}
