<?php

declare(strict_types=1);

namespace app\shared\plugin\http;

use app\shared\plugin\contract\HttpClientInterface;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Throwable;

/** 让官方 SDK 的 Guzzle 请求复用宿主 HTTP 限额、TLS 及测试替换能力。 */
final readonly class SdkHttpHandler
{
    /**
     * @param HttpClientInterface $http 宿主单次 HTTP 能力
     */
    public function __construct(private HttpClientInterface $http)
    {
    }

    /**
     * @param RequestInterface $request SDK 已加签请求
     * @param array<string, mixed> $options SDK 的传输选项，不开放给业务调用者
     * @return PromiseInterface 有大小限制的响应
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        try {
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                if (in_array(strtolower($name), ['host', 'connection', 'proxy-authorization', 'transfer-encoding'], true)) {
                    continue;
                }
                $headers[$name] = implode(', ', $values);
            }
            $response = $this->http->request($request->getMethod(), (string) $request->getUri(), [
                'headers' => $headers,
                'body' => (string) $request->getBody(),
                'timeout' => 10,
                'connect_timeout' => 4,
            ]);
            return new FulfilledPromise(new Response($response->status, $response->headers, $response->body));
        } catch (Throwable $error) {
            return new RejectedPromise($error);
        }
    }
}
