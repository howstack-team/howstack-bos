<?php

declare(strict_types=1);

namespace plugins\provider\magic_cloud\app\infrastructure;

use app\shared\plugin\contract\ScopedHttpClientInterface;
use app\shared\plugin\dto\{Configuration, ConnectionContext, HttpRequest};
use app\shared\plugin\exception\PluginException;
use Throwable;

/** 参考 dk-idc 魔方云 v1 协议，首期只登录及读取目录；保持 TLS 校验。 */
final readonly class MagicCloudCatalogGateway
{
    /**
     * 使用宿主受限网络门面。
     * @param ScopedHttpClientInterface $http 固定连接与端点范围
     */
    public function __construct(private ScopedHttpClientInterface $http) {}

    /**
     * 仅认证，不创建资源。
     * @param array<string, string|int|bool> $config 当前配置
     * @return void
     * @throws PluginException 认证失败
     */
    public function test(array $config): void
    {
        $this->login($config);
    }

    /**
     * 读取声明过的目录。
     * @param array<string, string|int|bool> $config 当前配置
     * @param string $kind 目录类型
     * @param array<string, int> $query 分页参数
     * @return array{data: list<array<string, mixed>>, total: int, page: int} 标准目录
     * @throws PluginException 目录或网络错误
     */
    public function catalog(array $config, string $kind, array $query): array
    {
        if (!in_array($kind, ['areas', 'nodes', 'images', 'stores', 'ip_groups', 'image_groups'], true)) {
            throw new PluginException('供应目录类型不支持');
        }
        $token = $this->login($config);
        $raw = $this->request($config['api_url'], 'GET', $kind, ['headers' => ['access-token' => $token], 'query' =>
            ['list_type' => 'page', 'orderby' => 'id', 'sort' => 'asc'] + $query]);
        $list = $raw['data'] ?? $raw['list'] ?? $raw;
        if (is_array($list) && isset($list['data'])) {
            $list = $list['data'];
        }
        if (!is_array($list)) {
            throw new PluginException('供应目录返回格式无效', 'invalid_response');
        }
        $entries = [];
        foreach ($list as $entry) {
            if (!is_array($entry) || !isset($entry['id']) || !is_scalar($entry['id'])) {
                continue;
            }
            $externalId = trim((string) $entry['id']);
            if ($externalId === '' || mb_strlen($externalId) > 128) {
                throw new PluginException('供应资源标识无效或过长', 'invalid_response');
            }
            $metadata = [];
            foreach (['area', 'node', 'image_group', 'architecture', 'type', 'enable'] as $field) {
                if (isset($entry[$field]) && is_scalar($entry[$field])) {
                    if (is_string($entry[$field]) && mb_strlen($entry[$field]) > 128) {
                        throw new PluginException('供应资源元数据过长', 'invalid_response');
                    }
                    $metadata[$field] = $entry[$field];
                }
            }
            $name = $entry['show_name'] ?? $entry['name'] ?? ('资源 ' . $externalId);
            $entries[] = ['external_id' => $externalId, 'name' => mb_substr(is_scalar($name) ? (string) $name : ('资源 ' . $externalId), 0, 128), 'metadata' => $metadata];
        }
        $meta = $raw['meta'] ?? [];
        return ['data' => $entries, 'total' => max(count($entries), (int) ($meta['total'] ?? $raw['total'] ?? count($entries))), 'page' => $query['page'] ?? 1];
    }

    /** @param array{api_url: string, username: string, password: string} $config 配置 @return string 本次调用令牌 */
    private function login(array $config): string
    {
        $raw = $this->request($config['api_url'], 'POST', 'login', ['json' => ['username' => $config['username'], 'password' => $config['password']]], true);
        $token = is_string($raw) ? $raw : ($raw['token'] ?? (is_array($raw['data'] ?? null) ? ($raw['data']['token'] ?? '') : ($raw['data'] ?? '')));
        $token = is_string($token) ? trim($token, " \t\n\r\0\x0B\"'") : '';
        if (!preg_match('/^[A-Za-z0-9._~+\/=\-]{16,8192}$/D', $token)) {
            throw new PluginException('魔方云认证未返回有效令牌', 'invalid_response');
        }
        return $token;
    }

    /** @param string $url 配置地址 @param string $method 方法 @param string $path 固定路径 @param array<string, mixed> $options 请求选项 @param bool $login 登录响应 @return array<string, mixed>|string 受检响应 */
    private function request(string $url, string $method, string $path, array $options, bool $login = false): array|string
    {
        try {
            $response = $this->http->send(
                new ConnectionContext('magic_cloud', new Configuration(['api_url' => $url])),
                new HttpRequest($method, '/v1/' . $path, $options['headers'] ?? [], $options['query'] ?? [], $options['json'] ?? null),
            );
            if ($response->status >= 300) {
                throw new PluginException('魔方云请求未成功（HTTP ' . $response->status . '）', 'invalid_response');
            }
            $body = $response->body;
            $raw = json_decode($body, true);
            if ($login && !is_array($raw)) {
                return is_string($raw) ? $raw : trim($body);
            }
            if ($raw === null && !$login && (trim($body) === '' || trim($body) === 'null')) {
                return [];
            }
            if (!is_array($raw)) {
                throw new PluginException('魔方云响应格式无效', 'invalid_response');
            }
            $status = $raw['code'] ?? $raw['status'] ?? null;
            if ((is_numeric($status) && !in_array((int) $status, [0, 1, 200], true))
                || (is_string($status) && in_array(strtolower($status), ['error', 'failed', 'fail'], true))
                || (!empty($raw['error']) && $status === null)) {
                throw new PluginException('魔方云拒绝了请求，请核对账号权限与配置', 'invalid_response');
            }
            return $raw;
        } catch (PluginException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new PluginException('无法连接魔方云，请检查地址、证书和网络', 'network_error', previous: $error);
        }
    }
}
