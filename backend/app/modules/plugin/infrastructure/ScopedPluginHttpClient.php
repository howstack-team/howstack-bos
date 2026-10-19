<?php

declare(strict_types=1);

namespace app\modules\plugin\infrastructure;

use app\shared\plugin\contract\ScopedHttpClientInterface;
use app\shared\plugin\dto\{ConnectionContext, HttpRequest, HttpResult};
use app\shared\plugin\exception\PluginException;
use GuzzleHttp\ClientInterface;
use Throwable;

/** 本连接主机及固定端点、TLS、重定向、超时与响应大小的宿主约束。 */
final readonly class ScopedPluginHttpClient implements ScopedHttpClientInterface
{
    /**
     * 注入审核后的静态网络范围。
     * @param ClientInterface $client 宿主 HTTP 实现，插件不可获取
     * @param string $pluginId 固定插件身份
     * @param array<string, mixed> $network 已校验的网络范围
     */
    public function __construct(private ClientInterface $client, private string $pluginId, private array $network) {}

    /** @inheritDoc */
    public function get(ConnectionContext $context, string $path, array $query = [], array $headers = []): HttpResult
    {
        return $this->send($context, new HttpRequest('GET', $path, $headers, $query));
    }

    /** @inheritDoc */
    public function post(ConnectionContext $context, string $path, array $data = [], array $headers = []): HttpResult
    {
        return $this->send($context, new HttpRequest('POST', $path, $headers, json: $data));
    }

    /** @inheritDoc */
    public function put(ConnectionContext $context, string $path, array $data = [], array $headers = []): HttpResult
    {
        return $this->send($context, new HttpRequest('PUT', $path, $headers, json: $data));
    }

    /** @inheritDoc */
    public function delete(ConnectionContext $context, string $path, array $query = [], array $headers = []): HttpResult
    {
        return $this->send($context, new HttpRequest('DELETE', $path, $headers, $query));
    }

    /** @inheritDoc */
    public function send(ConnectionContext $context, HttpRequest $request): HttpResult
    {
        $endpoint = ['method' => $request->method, 'path' => $request->path];
        if ($context->pluginId !== $this->pluginId || !in_array($endpoint, $this->network['endpoints'], true)) {
            throw new PluginException('插件请求超出审核的端点范围', 'capability_denied');
        }
        preg_match_all('/\\{([a-z][a-z0-9_]*)\\}/', $request->path, $placeholders);
        if (array_diff(array_keys($request->pathParameters), $placeholders[1]) || array_diff($placeholders[1], array_keys($request->pathParameters))) {
            throw new PluginException('路径参数与审核的端点模板不一致', 'capability_denied');
        }
        $path = $request->path;
        foreach ($request->pathParameters as $name => $value) {
            if (!is_int($value) || $value < 1 || $value > 2147483647) {
                throw new PluginException('资源路径标识无效', 'capability_denied');
            }
            $path = str_replace('{' . $name . '}', (string) $value, $path);
        }
        $base = $context->configuration->values[$this->network['base_url_field']] ?? '';
        $url = is_string($base) ? parse_url($base) : false;
        if (!is_array($url) || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || preg_match('~[\\\\\x00-\x20]|%2f|%5c|%2e~i', $base) || str_contains($url['path'] ?? '', '..')) {
            throw new PluginException('请填写不包含登录信息或查询参数的平台地址');
        }
        foreach ($request->headers as $key => $value) {
            if (in_array(strtolower($key), ['host', 'connection', 'proxy-authorization'], true)
                || str_contains($key . $value, "\r") || str_contains($key . $value, "\n")) {
                throw new PluginException('插件请求头超出允许范围', 'capability_denied');
            }
        }
        try {
            $options = ['headers' => $request->headers, 'query' => $request->query,
                'timeout' => 10, 'connect_timeout' => 4, 'verify' => true, 'http_errors' => false,
                'allow_redirects' => false, 'stream' => true];
            if ($request->json !== null) {
                $options['json'] = $request->json;
            }
            $response = $this->client->request($request->method, rtrim($base, '/') . $path, $options);
            $body = '';
            $stream = $response->getBody();
            while (!$stream->eof() && strlen($body) <= 2097152) {
                $chunk = $stream->read(min(65536, 2097153 - strlen($body)));
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
            $stream->close();
            if (strlen($body) > 2097152) {
                throw new PluginException('供应响应超过大小限制', 'invalid_response');
            }
            return new HttpResult($response->getStatusCode(), $body, $response->getHeaders());
        } catch (PluginException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new PluginException('无法连接外部平台，请检查地址、证书和网络', 'network_error', $error);
        }
    }
}
