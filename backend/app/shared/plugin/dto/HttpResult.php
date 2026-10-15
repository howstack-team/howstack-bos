<?php

declare(strict_types=1);

namespace app\shared\plugin\dto;

use app\shared\plugin\exception\PluginException;
use JsonException;

/** 大小受限的外部响应，原始内容只供本次插件调用解析。 */
final readonly class HttpResult
{
    /**
     * 返回协议状态及响应体。
     * @param int    $status HTTP 状态
     * @param string $body   最多 2 MiB 的原始响应，不得直接公开
     * @param array<string, list<string>> $headers 响应头，不得将平台秘密写入日志
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {
    }

    /**
     * @return bool HTTP 为 2xx；不代表平台业务成功
     */
    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * 解析 JSON 对象或数组，不向调用端暴露原始正文。
     * @return array<array-key, mixed> JSON 数据
     * @throws PluginException 响应格式错误
     */
    public function json(): array
    {
        try {
            $data = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new PluginException('外部响应不是有效 JSON', 'invalid_response', $error);
        }
        if (!is_array($data)) {
            throw new PluginException('外部响应须为 JSON 对象或数组', 'invalid_response');
        }
        return $data;
    }
}
