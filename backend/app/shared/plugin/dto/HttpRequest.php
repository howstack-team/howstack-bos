<?php

declare(strict_types=1);

namespace app\shared\plugin\dto;

/** 固定端点请求；没有任意 URL、TLS 或重试设置入口。 */
final readonly class HttpRequest
{
    /**
     * 描述一个已声明端点的请求。
     * @param string $method HTTP 方法
     * @param string $path 包清单中的固定相对端点
     * @param array<string, string> $headers 必要认证头，不记录原值
     * @param array<string, mixed> $query 查询字段
     * @param array<string, mixed>|null $json JSON 请求字段
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers = [],
        public array $query = [],
        public ?array $json = null,
    ) {}
}
