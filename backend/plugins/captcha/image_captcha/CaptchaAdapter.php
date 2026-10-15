<?php

declare(strict_types=1);

namespace plugins\captcha\image_captcha;

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
use app\shared\plugin\contract\ImageCaptchaInterface;
use app\shared\plugin\http\UnavailableImageCaptcha;

/** 图像验证码；无业务权限，不保存连接秘密，不自动重试或异常放行。 */
final readonly class CaptchaAdapter implements CaptchaAdapterInterface
{
    /**
     * @param HttpClientInterface $http 统一构造入口
     * @param ImageCaptchaInterface $images 宿主受限图像能力
     */
    public function __construct(
        private HttpClientInterface $http = new HttpClient(),
        private ImageCaptchaInterface $images = new UnavailableImageCaptcha(),
    ) {
    }


    /** @inheritDoc */
    public function validateConfiguration(Configuration $configuration): Configuration
    {
        $v = $configuration->values;
        foreach (['length' => [4, 8], 'width' => [120, 320], 'height' => [40, 120]] as $key => [$min, $max]) {
            if (!is_int($v[$key] ?? null) || $v[$key] < $min || $v[$key] > $max) {
                throw new PluginException('图像验证码尺寸或长度无效', 'invalid_configuration');
            }
        }
        return new Configuration(array_intersect_key($v, array_flip(['length', 'width', 'height'])));
    }

    /** @inheritDoc */
    public function initialize(CaptchaContext $context, Configuration $configuration): CaptchaChallenge
    {
        $c = $this->validateConfiguration($configuration)->values;
        return new CaptchaChallenge('image_captcha', [
            'image' => $this->images->generate($context, $c['length'], $c['width'], $c['height']),
        ]);
    }

    /** @inheritDoc */
    public function fingerprint(CaptchaProof $proof, CaptchaContext $context, Configuration $configuration): string
    {
        $proof->text('answer', 16);
        return hash('sha256', 'image_captcha:' . $context->id);
    }

    /** @inheritDoc */
    public function verify(CaptchaProof $proof, CaptchaContext $context, Configuration $configuration): CaptchaResult
    {
        $this->validateConfiguration($configuration);
        return new CaptchaResult($this->images->verify($context, $proof->text('answer', 16)) ? 'verified' : 'rejected');
    }

}
