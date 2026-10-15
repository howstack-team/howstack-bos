<?php

declare(strict_types=1);

namespace app\modules\channel\infrastructure\captcha;

use app\modules\channel\contract\captcha\CaptchaAttemptStoreInterface;
use app\shared\plugin\dto\captcha\CaptchaContext;
use app\shared\plugin\exception\PluginException;
use support\Redis;

/** 有界 TTL、会话/场景/IP 绑定和 Lua 原子消费；只缓存摘要及通道引用。 */
final class RedisCaptchaAttemptStore implements CaptchaAttemptStoreInterface
{
    /** @inheritDoc */
    public function issue(int $channelId, int $revision, string $binding, string $purpose, string $ip): CaptchaContext
    {
        $context = new CaptchaContext(bin2hex(random_bytes(32)), $binding, $purpose, $ip);
        if ($channelId < 1 || $revision < 1) {
            throw new PluginException('人机验证通道引用无效', 'invalid_request');
        }
        $rateKey = 'channel:captcha:rate:' . hash('sha256', $ip);
        $rate = Redis::eval("local n = redis.call('INCR', KEYS[1]); if n == 1 then redis.call('EXPIRE', KEYS[1], 60) end; return n", 1, $rateKey);
        if ($rate > 30) {
            throw new PluginException('验证码获取过于频繁，请稍后重试', 'rate_limited');
        }
        $value = json_encode([
            'channel_id' => $channelId, 'revision' => $revision, 'binding' => self::binding($context),
        ], JSON_THROW_ON_ERROR);
        if (!Redis::set('channel:captcha:attempt:' . $context->id, $value, 'EX', 300, 'NX')) {
            throw new PluginException('验证挑战创建失败', 'captcha_unavailable');
        }
        return $context;
    }

    /** @inheritDoc */
    public function consume(CaptchaContext $context): ?array
    {
        $script = <<<'LUA'
local value = redis.call('GET', KEYS[1])
if not value then return false end
local decoded = cjson.decode(value)
if decoded.binding ~= ARGV[1] then return false end
redis.call('DEL', KEYS[1])
return value
LUA;
        $raw = Redis::eval($script, 1, 'channel:captcha:attempt:' . $context->id, self::binding($context));
        if (!is_string($raw)) {
            return null;
        }
        $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_int($value['channel_id'] ?? null) || $value['channel_id'] < 1
            || !is_int($value['revision'] ?? null) || $value['revision'] < 1) {
            return null;
        }
        return ['channel_id' => $value['channel_id'], 'revision' => $value['revision']];
    }

    /** @inheritDoc */
    public function reserveProof(string $pluginId, string $fingerprint): bool
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $pluginId) || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
            throw new PluginException('验证票据指纹无效', 'invalid_request');
        }
        return (bool) Redis::set('channel:captcha:proof:' . $pluginId . ':' . $fingerprint, 'used', 'EX', 600, 'NX');
    }

    /**
     * @param CaptchaContext $context 服务端上下文
     * @return string 绑定摘要，不保存真实 IP 或会话随机值
     */
    private static function binding(CaptchaContext $context): string
    {
        return hash('sha256', $context->browserBinding . ':' . $context->purpose . ':' . $context->clientIp);
    }
}
