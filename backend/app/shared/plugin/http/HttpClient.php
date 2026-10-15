<?php

declare(strict_types=1);

namespace app\shared\plugin\http;

use app\shared\plugin\contract\HttpClientInterface;
use app\shared\plugin\dto\HttpResult;
use app\shared\plugin\exception\PluginException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Throwable;

/** 可直接创建的公共客户端；无平台分支、重试或跨调用凭据。 */
final readonly class HttpClient implements HttpClientInterface
{
    private const int MAX_BODY_BYTES = 2097152;

    /**
     * @param ClientInterface $client 底层客户端，可注入测试实现
     */
    public function __construct(private ClientInterface $client = new Client())
    {
    }

    /** @inheritDoc */
    public function get(string $url, array $query = [], array $headers = []): HttpResult
    {
        $options = ['headers' => $headers];
        if ($query !== []) {
            $options['query'] = $query;
        }
        return $this->request('GET', $url, $options);
    }

    /** @inheritDoc */
    public function post(string $url, array $data = [], array $headers = []): HttpResult
    {
        return $this->request('POST', $url, ['json' => $data, 'headers' => $headers]);
    }

    /** @inheritDoc */
    public function put(string $url, array $data = [], array $headers = []): HttpResult
    {
        return $this->request('PUT', $url, ['json' => $data, 'headers' => $headers]);
    }

    /** @inheritDoc */
    public function delete(string $url, array $query = [], array $headers = []): HttpResult
    {
        $options = ['headers' => $headers];
        if ($query !== []) {
            $options['query'] = $query;
        }
        return $this->request('DELETE', $url, $options);
    }

    /** @inheritDoc */
    public function postForm(string $url, array $data = [], array $headers = []): HttpResult
    {
        return $this->request('POST', $url, ['form_params' => $data, 'headers' => $headers]);
    }

    /** @inheritDoc */
    public function request(string $method, string $url, array $options = []): HttpResult
    {
        $method = strtoupper($method);
        $this->validate($method, $url, $options);
        $timeout = $options['timeout'] ?? 10;
        $options += ['timeout' => $timeout, 'connect_timeout' => min(4, $timeout)];
        $options = array_replace($options, [
            'verify' => true,
            'allow_redirects' => false,
            'http_errors' => false,
            'stream' => true,
            'cookies' => false,
            'auth' => null,
        ]);

        try {
            $response = $this->client->request($method, $url, $options);
            $stream = $response->getBody();
            $body = '';
            try {
                while (!$stream->eof()) {
                    $chunk = $stream->read(min(65536, self::MAX_BODY_BYTES + 1 - strlen($body)));
                    if ($chunk === '' && !$stream->eof()) {
                        throw new PluginException('外部响应未读取完整', 'invalid_response');
                    }
                    $body .= $chunk;
                    if (strlen($body) > self::MAX_BODY_BYTES) {
                        throw new PluginException('外部响应超过大小限制', 'invalid_response');
                    }
                }
            } finally {
                $stream->close();
            }

            return new HttpResult($response->getStatusCode(), $body, $response->getHeaders());
        } catch (PluginException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new PluginException('外部请求未完成，请检查平台地址、证书和网络', 'network_error', $error);
        }
    }

    /**
     * 快捷 API 只开放数据和有限超时选项；高级 SDK 仍按插件自身依赖接入。
     * @param string $method HTTP 方法
     * @param string $url 外部平台地址
     * @param array<string, mixed> $options 本次请求参数
     * @return void
     * @throws PluginException 请求参数无效
     */
    private function validate(string $method, string $url, array $options): void
    {
        $parts = parse_url($url);
        if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)
            || !is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || !filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\\\\\x00-\x20\x7f]/', $url)
            || array_diff(array_keys($options), ['headers', 'query', 'json', 'form_params', 'body', 'timeout', 'connect_timeout']) !== []) {
            throw new PluginException('HTTP 地址、方法或选项无效', 'invalid_request');
        }
        foreach (['headers', 'query', 'json', 'form_params'] as $key) {
            if (array_key_exists($key, $options) && !is_array($options[$key])) {
                throw new PluginException('HTTP 数据选项必须为数组', 'invalid_request');
            }
        }
        $payloads = array_intersect(array_keys($options), ['json', 'form_params', 'body']);
        if (count($payloads) > 1 || (array_key_exists('body', $options) && !is_string($options['body']))) {
            throw new PluginException('HTTP 请求只能指定一种正文格式', 'invalid_request');
        }
        foreach ($options['headers'] ?? [] as $name => $value) {
            if (!is_string($name) || !is_string($value) || !preg_match('/^[A-Za-z0-9!#$%&\x27*+.^_`|~-]+$/D', $name)
                || in_array(strtolower($name), ['host', 'connection', 'proxy-authorization'], true)
                || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new PluginException('HTTP 请求头无效', 'invalid_request');
            }
        }
        foreach (['timeout', 'connect_timeout'] as $key) {
            $value = array_key_exists($key, $options) ? $options[$key] : 10;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value <= 0 || $value > 30) {
                throw new PluginException('HTTP 超时必须大于 0 且不超过 30 秒', 'invalid_request');
            }
        }
    }
}
