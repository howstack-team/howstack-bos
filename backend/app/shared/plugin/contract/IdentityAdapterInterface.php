<?php

declare(strict_types=1);

namespace app\shared\plugin\contract;

use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\identity\IdentityReference;
use app\shared\plugin\dto\identity\IdentityRequest;
use app\shared\plugin\dto\identity\IdentityResult;

/** 身份验证 v1：浏览器返回只触发查单，不能自行标记认证成功。 */
interface IdentityAdapterInterface extends ConfigurablePluginInterface
{
    /**
     * @param IdentityRequest $request 本次认证输入
     * @param Configuration $configuration 本次配置
     * @return IdentityResult 认证链接及上游流水
     */
    public function initialize(IdentityRequest $request, Configuration $configuration): IdentityResult;

    /**
     * @param IdentityReference $reference 已封存的认证流水关系
     * @param Configuration $configuration 原通道配置
     * @return IdentityResult 已验签查询结果
     */
    public function query(IdentityReference $reference, Configuration $configuration): IdentityResult;
}
