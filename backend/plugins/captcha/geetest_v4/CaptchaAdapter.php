<?php

declare(strict_types=1);

namespace plugins\captcha\geetest_v4;

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

/** 极验行为验证 4.0；无业务权限，不保存连接秘密，不自动重试或异常放行。 */
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
        $id = Fields::text($v, 'captcha_id');
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new PluginException('极验验证 ID 无效', 'invalid_configuration');
        }
        return new Configuration(['captcha_id' => $id, 'captcha_key' => Fields::text($v, 'captcha_key')]);
    }

    /** @inheritDoc */
    public function initialize(CaptchaContext $context, Configuration $configuration): CaptchaChallenge
    {
        $c = $this->validateConfiguration($configuration)->values;
        return new CaptchaChallenge('geetest_v4', ['captchaId' => $c['captcha_id'], 'product' => 'bind']);
    }

    /** @inheritDoc */
    public function fingerprint(CaptchaProof $proof, CaptchaContext $context, Configuration $configuration): string
    {
        $c = $this->validateConfiguration($configuration)->values;
        $lot = $proof->text('lot_number', 32);
        if (!preg_match('/^[a-f0-9]{32}$/D', $lot)) {
            throw new PluginException('极验流水号无效', 'invalid_request');
        }
        return hash('sha256', $c['captcha_id'] . ':' . $lot);
    }

    /** @inheritDoc */
    public function verify(CaptchaProof $proof, CaptchaContext $context, Configuration $configuration): CaptchaResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        $this->fingerprint($proof, $context, $configuration);
        $lot = $proof->text('lot_number', 32);
        $time = $proof->text('gen_time', 16);
        if (!ctype_digit($time) || abs(time() - (int) $time) > 300) {
            return new CaptchaResult('rejected');
        }
        $params = [
            'lot_number' => $lot, 'captcha_output' => $proof->text('captcha_output', 16384),
            'pass_token' => $proof->text('pass_token', 2048), 'gen_time' => $time,
            'sign_token' => hash_hmac('sha256', $lot, $c['captcha_key']),
        ];
        try {
            $reply = $this->http->postForm('https://gcaptcha4.geetest.com/validate?captcha_id=' . $c['captcha_id'], $params);
            $data = $reply->json();
            if (!$reply->isSuccessful() || ($data['status'] ?? null) !== 'success') {
                return new CaptchaResult('unknown');
            }
            if (($data['result'] ?? null) === 'fail') {
                return new CaptchaResult('rejected');
            }
            return new CaptchaResult(($data['result'] ?? null) === 'success'
                && ($data['captcha_args']['lot_number'] ?? null) === $lot ? 'verified' : 'unknown');
        } catch (Throwable) {
            return new CaptchaResult('unknown');
        }
    }

}
