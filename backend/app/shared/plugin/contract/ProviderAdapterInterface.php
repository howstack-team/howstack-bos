<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\{CatalogPage, CatalogQuery, ConnectionContext, ConnectionTestResult, ProviderBinding};
use app\shared\plugin\exception\PluginException;

/** 供应 SDK v1 必要接口；不包含生产、支付和账务写入。 */
interface ProviderAdapterInterface extends ConfigurablePluginInterface
{
    /**
     * 测试只读连接认证。
     * @param ConnectionContext $context 本次连接
     * @return ConnectionTestResult 安全结果
     * @throws PluginException 认证或网络失败
     */
    public function testConnection(ConnectionContext $context): ConnectionTestResult;

    /**
     * 查询清单中声明的目录。
     * @param ConnectionContext $context 本次连接
     * @param CatalogQuery      $query   目录和分页
     * @return CatalogPage 标准目录
     * @throws PluginException 目录或网络失败
     */
    public function listCatalog(ConnectionContext $context, CatalogQuery $query): CatalogPage;

    /**
     * 使用传入的资源关系校验，无数据库或 HTTP 调用。
     * @param ProviderBinding $binding 当前部署位置的资料
     * @return void
     * @throws PluginException 资源组合或映射不支持
     */
    public function validateBinding(ProviderBinding $binding): void;
}
