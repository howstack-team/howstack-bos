<?php

declare(strict_types=1);
namespace plugins\provider\magic_cloud\app\infrastructure;

use app\shared\plugin\contract\ScopedHttpClientInterface;
use app\shared\plugin\dto\{ConnectionContext, HttpRequest};
use app\shared\plugin\dto\provider\{ProvisionRequest, ProvisionResult};
use app\shared\plugin\exception\PluginException;

/** 魔方云 v1：固定主机名关联请求；创建只提交一次，超时只能查询，查无结果不重建。 */
final readonly class MagicCloudProvisioningGateway
{
    /** @param ScopedHttpClientInterface $http 宿主审核过的固定端点 */
    public function __construct(private ScopedHttpClientInterface $http) {}
    /** @param ConnectionContext $context 原连接 @param ProvisionRequest $request 单台请求 @return ProvisionResult 创建受理结果 */
    public function create(ConnectionContext $context, ProvisionRequest $request): ProvisionResult
    {
        $c = $request->configuration;
        $payload = ['cpu' => $c['flavor']['cpu'], 'memory' => $c['flavor']['memory_mib'],
            'os' => $c['image']['external_id'], 'area' => $c['zone']['external_id'], 'node' => $c['flavor_group']['external_id'],
            'store' => $c['system_disk']['type']['external_id'], 'ip_group' => $c['line']['external_id'],
            'network_type' => 'normal', 'port' => $c['image']['default_port'] ?? 22,
            'client' => $context->configuration->values['client_id'] ?? 0, 'system_disk_size' => $c['system_disk']['size_gib'],
            'other_data_disk' => array_map(static fn (array $d): array => ['size' => $d['size_gib'], 'store' => $d['type']['external_id']], $c['data_disks']),
            'out_bw' => $c['network']['egress_mbps'], 'in_bw' => $c['network']['ingress_mbps'], 'ip_num' => $c['network']['ipv4_count']];
        $allowed = ['cpu', 'memory', 'os', 'area', 'node', 'store', 'ip_group', 'client', 'system_disk_size', 'other_data_disk', 'out_bw', 'in_bw', 'ip_num', 'network_type', 'port'];
        if (array_diff(array_keys($request->parameters), $allowed)) return new ProvisionResult('failed');
        $payload = array_replace($payload, $request->parameters);
        foreach (['cpu', 'memory', 'os', 'area', 'node', 'store', 'ip_group', 'client', 'system_disk_size'] as $key) {
            if (!is_scalar($payload[$key]) || !ctype_digit((string) $payload[$key]) || (int) $payload[$key] < 1) return new ProvisionResult('failed');
        }
        foreach (['out_bw', 'in_bw', 'ip_num'] as $key) if (!is_scalar($payload[$key]) || !ctype_digit((string) $payload[$key])) return new ProvisionResult('failed');
        if (!in_array($payload['network_type'], ['normal', 'vpc'], true) || ($payload['port'] !== 'auto' && (!is_scalar($payload['port']) || !ctype_digit((string) $payload['port']) || (int) $payload['port'] < 1 || (int) $payload['port'] > 65535))) return new ProvisionResult('failed');
        if (!is_array($payload['other_data_disk']) || !array_is_list($payload['other_data_disk']) || count($payload['other_data_disk']) > 32) return new ProvisionResult('failed');
        foreach ($payload['other_data_disk'] as &$disk) {
            if (!is_array($disk)) return new ProvisionResult('failed');
            if (isset($disk['size_gib'], $disk['type']['external_id'])) $disk = ['size' => $disk['size_gib'], 'store' => $disk['type']['external_id']];
            foreach (['size', 'store'] as $key) if (!isset($disk[$key]) || !is_scalar($disk[$key]) || !ctype_digit((string) $disk[$key]) || (int) $disk[$key] < 1) return new ProvisionResult('failed');
            $disk = array_intersect_key($disk, array_flip(['size', 'store']));
        }
        unset($disk);
        $payload += ['num' => 1, 'hostname' => $request->requestKey, 'rootpass' => $request->password];
        $raw = $this->call($context, 'POST', '/v1/clouds', $this->token($context), $payload);
        $data = $raw['data'] ?? $raw;
        $id = is_array($data) ? ($data['id'] ?? ($data[0]['id'] ?? null)) : $data;
        if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id < 1) return new ProvisionResult('unknown');
        return new ProvisionResult('pending', (string) $id);
    }
    /** @param ConnectionContext $context 原连接 @param ProvisionRequest $request 固定主机名和已确认 ID @return ProvisionResult 查询结果 */
    public function query(ConnectionContext $context, ProvisionRequest $request): ProvisionResult
    {
        $token = $this->token($context);
        $matches = [];
        // 不依赖供应商是否严格支持 hostname 搜索；本地再次精确匹配，分页未找到始终为未知。
        for ($page = 1; $page <= 10; $page++) {
            $raw = $this->call($context, 'GET', '/v1/clouds', $token, ['page' => $page, 'per_page' => 100, 'list_type' => 'page', 'search' => $request->requestKey, 'searchtype' => '0', 'user' => $context->configuration->values['client_id'], 'orderby' => 'id', 'sort' => 'desc']);
            $rows = $raw['data'] ?? $raw['list'] ?? $raw;
            if (isset($rows['data'])) $rows = $rows['data'];
            if (!is_array($rows) || !array_is_list($rows)) return new ProvisionResult('unknown', $request->externalId);
            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['id'])) continue;
                $match = $request->externalId !== null ? (string) $row['id'] === $request->externalId : ($row['hostname'] ?? '') === $request->requestKey;
                if ($match) $matches[(string) $row['id']] = $row;
            }
            if (count($rows) < 100) break;
        }
        if (count($matches) !== 1) return new ProvisionResult('unknown', $request->externalId);
        $row = reset($matches);
        if (isset($row['hostname']) && $row['hostname'] !== $request->requestKey) return new ProvisionResult('unknown', $request->externalId);
        $addresses = [];
        foreach (['mainip', 'main_ip', 'public_ip', 'ip'] as $key) if (is_string($row[$key] ?? null) && filter_var($row[$key], FILTER_VALIDATE_IP)) $addresses[] = $row[$key];
        foreach (['ips', 'ip_list', 'float_ip', 'ipaddress'] as $key) {
            foreach (is_array($row[$key] ?? null) ? $row[$key] : [] as $item) {
                $ip = is_string($item) ? trim($item) : (is_array($item) ? ($item['ip'] ?? $item['address'] ?? null) : null);
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) $addresses[] = $ip;
            }
        }
        $addresses = array_values(array_unique($addresses));
        // 电源状态已就绪但 IP 尚未分配完整时继续查询，不能提前结束任务。
        $active = in_array(strtolower((string) ($row['status'] ?? '')), ['on', 'off', 'running', 'stopped'], true)
            && count($addresses) >= (int) ($request->configuration['network']['ipv4_count'] ?? 0);
        return new ProvisionResult($active ? 'active' : 'pending', (string) $row['id'], array_values(array_unique($addresses)));
    }
    /** @param ConnectionContext $context 原连接 @return string 当前 API 令牌，永不持久化 */
    private function token(ConnectionContext $context): string
    {
        return (new MagicCloudCatalogGateway($this->http))->authenticate($context->configuration->values);
    }
    /** @param ConnectionContext $context 原连接 @param string $method 固定方法 @param string $path 固定端点 @param string $token 临时令牌 @param array<string,mixed> $data 请求数据 @return array<string,mixed> 结构受检响应 */
    private function call(ConnectionContext $context, string $method, string $path, #[\SensitiveParameter] string $token, #[\SensitiveParameter] array $data): array
    {
        $response = $this->http->send($context, new HttpRequest($method, $path, $token === '' ? [] : ['access-token' => $token], $method === 'GET' ? $data : [], $method === 'GET' ? null : $data));
        $raw = json_decode($response->body, true);
        // 任意非成功响应都不能证明没有创建资源；保持查单，禁止按状态码盲目重建。
        if ($response->status < 200 || $response->status >= 300 || !is_array($raw)) throw new PluginException('魔方云响应未确认，请查询开通结果', 'result_unknown');
        $code = $raw['code'] ?? $raw['status'] ?? null;
        if (($code !== null && !in_array($code, [0, 1, 200, '0', '1', '200'], true)) || !empty($raw['error'])) throw new PluginException('魔方云尚未确认操作结果', 'result_unknown');
        return $raw;
    }
}
