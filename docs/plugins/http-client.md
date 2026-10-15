# 公共 HTTP 请求指南（第三方开发者）

适用产品：howstack-bos；公共插件协议：v1。

系统提供封装好的 HTTP 快捷客户端，普通 HTTP 调用无需安装 SDK 或声明 Guzzle 依赖。插件也可以按需引入自己的 Composer SDK，不设依赖包名白名单；依赖仍放在本插件目录，并遵循锁定、构建、完整性和版本冲突检查。

官方共用依赖可由项目明确纳入主 Composer。本项目支付宝 SDK 保留后端主 Composer；阿里云、腾讯云与微信支付 SDK 放在各自插件目录，第三方插件同样自行交付 Composer 锁文件和生产 vendor，不修改宿主依赖。七牛直接使用 REST，无 SDK。详见 [支付宝通道接入与插件开发](alipay-channels.md) 和 [官方通道插件接入与开发](cloud-channels.md)。

## 创建客户端

```php
<?php

declare(strict_types=1);

use app\shared\plugin\http\HttpClient;

$http = new HttpClient();
```

客户端底层使用系统维护的 Guzzle，不需要插件访问核心容器。请求地址、参数和认证头在每次调用传入，不跨调用保存平台凭据。以下为示例平台地址，不对应实际服务。

## GET、POST、PUT、DELETE

```php
<?php

use app\shared\plugin\http\HttpClient;

$http = new HttpClient();
$headers = ['Authorization' => 'Bearer example-token'];

// GET：第二个参数为查询条件。
$result = $http->get('https://api.example.test/items', ['page' => 1], $headers);
$data = $result->json();

// POST：第二个参数默认编码为 JSON。
$result = $http->post('https://api.example.test/items', ['name' => '云服务器'], $headers);

// PUT：第二个参数默认编码为 JSON。
$result = $http->put('https://api.example.test/items/1', ['name' => '香港云服务器'], $headers);

// DELETE：第二个参数为查询条件，默认没有正文。
$result = $http->delete('https://api.example.test/items/1', ['force' => 1], $headers);
```

| 方法 | 第二个参数 | 第三个参数 |
| --- | --- | --- |
| `get($url, $query, $headers)` | 查询参数 | 请求头 |
| `post($url, $data, $headers)` | JSON 数据 | 请求头 |
| `put($url, $data, $headers)` | JSON 数据 | 请求头 |
| `delete($url, $query, $headers)` | 查询参数 | 请求头 |
| `postForm($url, $data, $headers)` | URL 编码的表单数据 | 请求头 |

GET 和 DELETE 未传查询参数时保留 URL 中已有的查询部分。传入查询数组时，使用该数组构建查询部分。

## 表单与通用请求

```php
<?php

use app\shared\plugin\http\HttpClient;

$http = new HttpClient();
$http->postForm('https://api.example.test/token', [
    'client_id' => 'example-client',
    'client_secret' => 'example-secret',
]);

// DELETE 带 JSON 正文，例如平台的批量删除接口。
$http->request('DELETE', 'https://api.example.test/items', [
    'headers' => ['Authorization' => 'Bearer example-token'],
    'json' => ['ids' => [1, 2]],
    'timeout' => 15,
]);

// 原始字符串正文，例如需要对固定正文签名的平台。
$http->request('PUT', 'https://api.example.test/config', [
    'headers' => ['Content-Type' => 'text/plain'],
    'body' => 'example-configuration',
]);
```

`request()` 支持 GET、HEAD、POST、PUT、PATCH、DELETE、OPTIONS。可传选项：

- `headers`：字符串键值请求头；不接受换行和 Host 等传输层覆盖。
- `query`：查询数组。
- `json`：JSON 数据数组。
- `form_params`：表单数据数组。
- `body`：原始字符串正文。
- `timeout`、`connect_timeout`：大于 0、最多 30 秒。

`json`、`form_params`、`body` 三者只能选择一种。快捷方法默认请求超时 10 秒、连接超时 4 秒；请求超时小于 4 秒时，默认连接超时也随之缩短。需要 multipart、流式上传或其他高级能力时，可以使用插件自己的 SDK 或客户端。

## 响应与异常

返回公共 `HttpResult`：

| 成员 | 含义 |
| --- | --- |
| `$result->status` | HTTP 状态码 |
| `$result->body` | 原始响应字符串 |
| `$result->headers` | 响应头，每个值为字符串数组 |
| `$result->json()` | 解析 JSON 对象或数组；无效格式抛出异常 |
| `$result->isSuccessful()` | HTTP 状态是否为 2xx，不代表平台业务成功 |

204 等空响应不应调用 `json()`；根据平台协议读取状态和正文。4xx、5xx 返回原状态码供插件判断，3xx 不自动跳转。客户端默认校验证书、不自动重试，响应体最大 2 MiB，读取后关闭响应流。

地址、方法或选项不合法时抛出 `PluginException`，`reason=invalid_request`；网络失败为 `network_error`，超限或响应解析失败为 `invalid_response`。异常信息不包含原始平台报文，底层异常链供服务端诊断，不直接公开或写出凭据。

网络超时可能发生在平台已接收请求之后。插件按业务接口返回“结果未知”等正确状态，不直接重发开通、付款或邮件请求。

## 供应插件的快捷请求

供应插件的构造函数已有宿主注入的 `ScopedHttpClientInterface`。它也提供四种快捷方法，第一个参数是本次 `ConnectionContext`，第二个参数是清单声明的相对端点：

```php
<?php

// 放在供应插件的方法中；$context 为本次调用上下文。
$result = $this->http->get($context, '/list', ['page' => 1], ['Authorization' => 'Bearer example-token']);
$result = $this->http->post($context, '/create', ['name' => '示例']);
$result = $this->http->put($context, '/modify', ['id' => 'example-id', 'name' => '新名称']);
$result = $this->http->delete($context, '/delete', ['id' => 'example-id']);
```

调用此客户端时，方法和端点必须在插件清单中声明；实际主机由当前连接配置确定，不能借快捷方法切换到其他平台。需要显式正文或其他请求形式时，现有 `send($context, HttpRequest)` 仍可使用。

公共 `HttpClient` 是推荐工具，第三方 SDK 依赖继续独立管理；它不替代插件的接口声明、配置校验和审核。插件必须只访问本次配置对应的平台，不访问系统内部数据；同进程 PHP 不是安全沙箱。如何构建依赖与交付插件见 [邮件通道插件开发文档](mail-channel-development.md#插件自己的-composer-依赖)。
