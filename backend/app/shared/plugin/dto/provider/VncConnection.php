<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\provider;

/** 仅限已认证的直接WSS连接；调试和JSON序列化不暴露连接凭据。 */
final readonly class VncConnection implements \JsonSerializable
{
    /**
     * @param string $websocketUrl 完整WSS地址，可能含供应商临时令牌
     * @param string $password VNC认证密码，禁止使用或返回实例系统登录密码
     */
    public function __construct(
        #[\SensitiveParameter] public string $websocketUrl,
        #[\SensitiveParameter] public string $password,
    ) {
        $url = parse_url($websocketUrl);
        if (strlen($websocketUrl) > 8192 || preg_match('/[\x00-\x20\x7F\\\\]/', $websocketUrl)
            || !is_array($url) || ($url['scheme'] ?? '') !== 'wss' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
            || (isset($url['port']) && ($url['port'] < 1 || $url['port'] > 65535))
            || strlen($password) > 2048 || str_contains($password, "\0")) {
            throw new \InvalidArgumentException('供应商未返回有效的安全VNC连接');
        }
    }

    /**
     * @return array<string,string> 安全日志投影
     */
    public function jsonSerialize(): array
    {
        return ['vnc_credentials' => '[redacted]'];
    }

    /**
     * @return array<string,string> 安全调试投影
     */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
