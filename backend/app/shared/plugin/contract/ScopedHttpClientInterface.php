<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\{ConnectionContext, HttpRequest, HttpResult};
use app\shared\plugin\exception\PluginException;

/** 宿主网络门面，仅访问当前连接地址和审核过的固定端点。 */
interface ScopedHttpClientInterface
{
    /**
     * 快捷 GET，地址仍由本连接配置及清单端点确定。
     * @param ConnectionContext $context 当前插件与连接
     * @param string $path 已审核相对端点
     * @param array<string, mixed> $query 查询参数
     * @param array<string, string> $headers 请求头
     * @return HttpResult 有限响应
     * @throws PluginException 越界、网络或响应大小错误
     */
    public function get(ConnectionContext $context, string $path, array $query = [], array $headers = []): HttpResult;

    /**
     * 快捷 POST JSON，保持既有连接及端点限制。
     * @param ConnectionContext $context 当前插件与连接
     * @param string $path 已审核相对端点
     * @param array<string, mixed> $data JSON 数据
     * @param array<string, string> $headers 请求头
     * @return HttpResult 有限响应
     * @throws PluginException 越界、网络或响应大小错误
     */
    public function post(ConnectionContext $context, string $path, array $data = [], array $headers = []): HttpResult;

    /**
     * 快捷 PUT JSON，保持既有连接及端点限制。
     * @param ConnectionContext $context 当前插件与连接
     * @param string $path 已审核相对端点
     * @param array<string, mixed> $data JSON 数据
     * @param array<string, string> $headers 请求头
     * @return HttpResult 有限响应
     * @throws PluginException 越界、网络或响应大小错误
     */
    public function put(ConnectionContext $context, string $path, array $data = [], array $headers = []): HttpResult;

    /**
     * 快捷 DELETE，保持既有连接及端点限制。
     * @param ConnectionContext $context 当前插件与连接
     * @param string $path 已审核相对端点
     * @param array<string, mixed> $query 查询参数
     * @param array<string, string> $headers 请求头
     * @return HttpResult 有限响应
     * @throws PluginException 越界、网络或响应大小错误
     */
    public function delete(ConnectionContext $context, string $path, array $query = [], array $headers = []): HttpResult;

    /**
     * 执行一次受控调用，不自动重试。
     * @param ConnectionContext $context 当前插件与连接
     * @param HttpRequest       $request 固定端点请求
     * @return HttpResult 有限响应
     * @throws PluginException 越界、网络或响应大小错误
     */
    public function send(ConnectionContext $context, HttpRequest $request): HttpResult;
}
