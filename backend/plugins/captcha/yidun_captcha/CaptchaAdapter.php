<?php

declare(strict_types=1);

namespace plugins\captcha\yidun_captcha;

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

/** 网易云盾行为式验证码；无业务权限，不保存连接秘密，不自动重试或异常放行。 */
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
        return new Configuration([
            'captcha_id' => Fields::text($v, 'captcha_id'), 'secret_id' => Fields::text($v, 'secret_id'),
            'secret_key' => Fields::text($v, 'secret_key'),
        ]);
    }

    /** @inheritDoc */
    public function initialize(CaptchaContext $context, Configuration $configuration): CaptchaChallenge
    {
        $c = $this->validateConfiguration($configuration)->values;
        return new CaptchaChallenge('yidun_captcha', ['captchaId' => $c['captcha_id'], 'user' => $context->id]);
    }

    /** @inheritDoc */
    public function fingerprint(CaptchaProof $proof, CaptchaContext $context, Configuration $configuration): string
    {
        $c = $this->validateConfiguration($configuration)->values;
        return hash('sha256', $c['captcha_id'] . ':' . $proof->text('validate', 16384));
    }

    /** @inheritDoc */
    public function verify(CaptchaProof $proof, CaptchaContext $context, Configuration $configuration): CaptchaResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        $params = [
            'captchaId' => $c['captcha_id'], 'validate' => $proof->text('validate', 16384),
            'user' => $context->id, 'secretId' => $c['secret_id'], 'version' => 'v2',
            'timestamp' => (string) (int) floor(microtime(true) * 1000), 'nonce' => bin2hex(random_bytes(16)),
        ];
        ksort($params, SORT_STRING);
        $canonical = '';
        foreach ($params as $key => $value) {
            $canonical .= $key . $value;
        }
        $params['signature'] = md5($canonical . $c['secret_key']);
        try {
            $reply = $this->http->postForm('https://c.dun.163yun.com/api/v2/verify', $params);
            $data = $reply->json();
            if (!$reply->isSuccessful() || ($data['error'] ?? null) !== 0 || !is_bool($data['result'] ?? null)) {
                return new CaptchaResult('unknown');
            }
            return new CaptchaResult($data['result'] ? 'verified' : 'rejected');
        } catch (Throwable) {
            return new CaptchaResult('unknown');
        }
    }

}
