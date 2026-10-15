<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\HttpResult;
use app\shared\plugin\exception\PluginException;

/** 公共 HTTP 快捷接口；不提供容器、数据库或其他核心对象。 */
interface HttpClientInterface
{
    /**
     * 发送 GET 查询。
     * @param string $url 外部平台地址
     * @param array<string, mixed> $query 查询参数
     * @param array<string, string> $headers 请求头
     * @return HttpResult 协议响应，业务状态由插件判断
     * @throws PluginException 参数、连接或响应错误
     */
    public function get(string $url, array $query = [], array $headers = []): HttpResult;

    /**
     * 发送 POST JSON。
     * @param string $url 外部平台地址
     * @param array<string, mixed> $data JSON 数据
     * @param array<string, string> $headers 请求头
     * @return HttpResult 协议响应
     * @throws PluginException 参数、连接或响应错误
     */
    public function post(string $url, array $data = [], array $headers = []): HttpResult;

    /**
     * 发送 PUT JSON。
     * @param string $url 外部平台地址
     * @param array<string, mixed> $data JSON 数据
     * @param array<string, string> $headers 请求头
     * @return HttpResult 协议响应
     * @throws PluginException 参数、连接或响应错误
     */
    public function put(string $url, array $data = [], array $headers = []): HttpResult;

    /**
     * 发送 DELETE，可附带查询参数；正文使用 request() 显式指定。
     * @param string $url 外部平台地址
     * @param array<string, mixed> $query 查询参数
     * @param array<string, string> $headers 请求头
     * @return HttpResult 协议响应
     * @throws PluginException 参数、连接或响应错误
     */
    public function delete(string $url, array $query = [], array $headers = []): HttpResult;

    /**
     * 发送 POST 表单。
     * @param string $url 外部平台地址
     * @param array<string, mixed> $data 表单数据
     * @param array<string, string> $headers 请求头
     * @return HttpResult 协议响应
     * @throws PluginException 参数、连接或响应错误
     */
    public function postForm(string $url, array $data = [], array $headers = []): HttpResult;

    /**
     * 发送一次请求，不自动重试。
     * @param string $method GET/HEAD/POST/PUT/PATCH/DELETE/OPTIONS
     * @param string $url 外部平台地址
     * @param array<string, mixed> $options headers/query/json/form_params/body/timeout/connect_timeout
     * @return HttpResult 大小受限的响应
     * @throws PluginException 参数、连接或响应错误
     */
    public function request(string $method, string $url, array $options = []): HttpResult;
}
