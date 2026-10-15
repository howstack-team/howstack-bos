<?php

declare(strict_types=1);

namespace app\modules\channel\infrastructure\oauth;

use app\modules\channel\contract\oauth\OAuthStateStoreInterface;
use app\shared\plugin\configuration\OAuthFields;
use app\shared\plugin\exception\PluginException;
use support\Redis;

/** Redis 的一次性状态；Lua 在核对会话绑定后原子删除，跨工作进程不能重复使用。 */
final class RedisOAuthStateStore implements OAuthStateStoreInterface
{
    /** @inheritDoc */
    public function issue(array $attempt, string $browserBinding): string
    {
        $this->validateBinding($browserBinding);
        $state = bin2hex(random_bytes(32));
        $value = json_encode($attempt + ['binding' => hash('sha256', $browserBinding)], JSON_THROW_ON_ERROR);
        if (!Redis::set('channel:oauth:v1:' . $state, $value, 'EX', 300, 'NX')) {
            throw new PluginException('登录状态创建失败，请重新发起登录', 'oauth_failed');
        }
        return $state;
    }

    /** @inheritDoc */
    public function consume(string $state, string $browserBinding): ?array
    {
        OAuthFields::state($state);
        $this->validateBinding($browserBinding);
        $script = <<<'LUA'
local value = redis.call('GET', KEYS[1])
if not value then return false end
local decoded = cjson.decode(value)
if decoded.binding ~= ARGV[1] then return false end
redis.call('DEL', KEYS[1])
return value
LUA;
        $raw = Redis::eval($script, 1, 'channel:oauth:v1:' . $state, hash('sha256', $browserBinding));
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_int($data['channel_id'] ?? null) || $data['channel_id'] < 1 || !is_int($data['revision'] ?? null) || $data['revision'] < 1) {
            return null;
        }
        if (isset($data['remember_me']) && !is_bool($data['remember_me'])) return null;
        return ['channel_id' => $data['channel_id'], 'revision' => $data['revision']]
            + (array_key_exists('remember_me', $data) ? ['remember_me' => $data['remember_me']] : []);
    }

    /**
     * @param string $binding 由服务端 Session 保存的随机值
     * @return void
     * @throws PluginException 绑定值无效
     */
    private function validateBinding(string $binding): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $binding)) {
            throw new PluginException('登录会话无效', 'oauth_failed');
        }
    }
}
