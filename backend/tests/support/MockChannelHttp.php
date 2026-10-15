<?php

declare(strict_types=1);

namespace tests\support;

use app\shared\plugin\http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

/** 测试使用的 HTTP 替身，真实 SDK 加签且所有外部请求留在内存。 */
final class MockChannelHttp
{
    /** @param list<mixed> $responses 响应 @param array<mixed>|null $history 请求历史 @return HttpClient 注入替身 */
    public static function create(array $responses, ?array &$history): HttpClient
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        return new HttpClient(new Client(['handler' => $stack]));
    }
}
