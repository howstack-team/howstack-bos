<?php

declare(strict_types=1);

namespace plugins\captcha\aliyun_captcha;

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
use app\shared\plugin\cloud\AlibabaRpc;
use AlibabaCloud\SDK\Captcha\V20230305\Models\VerifyIntelligentCaptchaRequest;

/** 阿里云验证码 2.0；无业务权限，不保存连接秘密，不自动重试或异常放行。 */
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
            'access_key_id' => Fields::text($v, 'access_key_id'), 'access_key_secret' => Fields::text($v, 'access_key_secret'),
            'scene_id' => Fields::text($v, 'scene_id'), 'prefix' => Fields::text($v, 'prefix'),
            'region' => Fields::choice($v, 'region', ['cn', 'sgp']),
        ]);
    }

    /** @inheritDoc */
    public function initialize(CaptchaContext $context, Configuration $configuration): CaptchaChallenge
    {
        $c = $this->validateConfiguration($configuration)->values;
        return new CaptchaChallenge('aliyun_captcha', ['SceneId' => $c['scene_id'], 'prefix' => $c['prefix'], 'region' => $c['region']]);
    }

    /** @inheritDoc */
    public function fingerprint(CaptchaProof $proof, CaptchaContext $context, Configuration $configuration): string
    {
        $c = $this->validateConfiguration($configuration)->values;
        return hash('sha256', $c['prefix'] . ':' . $c['scene_id'] . ':' . $proof->text('captcha_verify_param', 16384));
    }

    /** @inheritDoc */
    public function verify(CaptchaProof $proof, CaptchaContext $context, Configuration $configuration): CaptchaResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        $raw = $proof->text('captcha_verify_param', 16384);
        try {
            $model = new VerifyIntelligentCaptchaRequest(['captchaVerifyParam' => $raw, 'sceneId' => $c['scene_id']]);
            $host = $c['region'] === 'cn' ? 'captcha.cn-shanghai.aliyuncs.com' : 'captcha.ap-southeast-1.aliyuncs.com';
            $data = (new AlibabaRpc($this->http))->request($host, '2023-03-05', 'VerifyIntelligentCaptcha', $model, $c['access_key_id'], $c['access_key_secret']);
            if (($data['Success'] ?? null) !== true || ($data['Code'] ?? null) !== 'Success'
                || !is_bool($data['Result']['VerifyResult'] ?? null)) {
                return new CaptchaResult('unknown');
            }
            if ($data['Result']['VerifyResult'] === false) {
                return new CaptchaResult('rejected');
            }
            // T005 为平台测试模式；生产业务不能将测试结果作为真实验证通过。
            return new CaptchaResult(($data['Result']['VerifyCode'] ?? null) === 'T001' ? 'verified' : 'rejected');
        } catch (Throwable) {
            return new CaptchaResult('unknown');
        }
    }

}
