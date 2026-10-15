<?php

declare(strict_types=1);

namespace app\modules\channel\infrastructure\captcha;

use app\shared\plugin\contract\ImageCaptchaInterface;
use app\shared\plugin\dto\captcha\CaptchaContext;
use app\shared\plugin\exception\PluginException;
use support\Redis;
use Webman\Captcha\CaptchaBuilder;

/** 使用现有 Webman 图片组件绘制；宿主以 HMAC 保存答案且错误尝试也一次性消费。 */
final readonly class WebmanImageCaptcha implements ImageCaptchaInterface
{
    /** @param string $applicationKey 宿主密钥，绝不传给插件 */
    public function __construct(private string $applicationKey)
    {
        if (strlen($applicationKey) < 16) {
            throw new PluginException('图像验证码宿主密钥未配置', 'captcha_unavailable');
        }
    }

    /** @inheritDoc */
    public function generate(CaptchaContext $context, int $length, int $width, int $height): string
    {
        if ($length < 4 || $length > 8 || $width < 120 || $width > 320 || $height < 40 || $height > 120) {
            throw new PluginException('验证码参数无效', 'invalid_request');
        }
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $answer = '';
        for ($i = 0; $i < $length; $i++) {
            $answer .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $builder = new CaptchaBuilder($answer);
        $builder->build($width, $height);
        $data = json_encode(['binding' => $this->binding($context), 'answer' => $this->digest($context, $answer)], JSON_THROW_ON_ERROR);
        if (!Redis::set('channel:captcha:image:' . $context->id, $data, 'EX', 300, 'NX')) {
            throw new PluginException('图片挑战已存在，请重新获取', 'captcha_unavailable');
        }
        return 'data:image/jpeg;base64,' . base64_encode($builder->get());
    }

    /** @inheritDoc */
    public function verify(CaptchaContext $context, string $answer): bool
    {
        $script = <<<'LUA'
local value = redis.call('GET', KEYS[1])
if not value then return false end
local decoded = cjson.decode(value)
if decoded.binding ~= ARGV[1] then return false end
redis.call('DEL', KEYS[1])
return value
LUA;
        $raw = Redis::eval($script, 1, 'channel:captcha:image:' . $context->id, $this->binding($context));
        if (!is_string($raw)) {
            return false;
        }
        $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        return is_string($data['answer'] ?? null) && hash_equals($data['answer'], $this->digest($context, strtolower(trim($answer))));
    }

    /**
     * @param CaptchaContext $context 绑定上下文
     * @return string 服务端绑定摘要
     */
    private function binding(CaptchaContext $context): string
    {
        return hash('sha256', $context->browserBinding . ':' . $context->purpose . ':' . $context->clientIp);
    }

    /**
     * @param CaptchaContext $context 挑战身份
     * @param string $answer 答案
     * @return string 不可离线枚举的 HMAC
     */
    private function digest(CaptchaContext $context, string $answer): string
    {
        return hash_hmac('sha256', $context->id . ':' . $answer, $this->applicationKey);
    }
}
