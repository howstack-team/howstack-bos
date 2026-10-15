<?php

declare(strict_types=1);

namespace app\modules\channel\application\identity;

use app\modules\channel\application\ChannelExecutionConfiguration;
use app\modules\channel\contract\TransactionAdapterFactoryInterface;
use app\shared\plugin\dto\identity\IdentityReference;
use app\shared\plugin\dto\identity\IdentityRequest;
use app\shared\plugin\dto\identity\IdentityResult;

/** 供会员实名认证业务调用；只返回平台结果，宿主负责申请归属和状态落库。 */
final readonly class IdentityChannelService
{
    /**
     * @param ChannelExecutionConfiguration $configuration 本次配置
     * @param TransactionAdapterFactoryInterface $adapters 固定接口加载器
     */
    public function __construct(private ChannelExecutionConfiguration $configuration, private TransactionAdapterFactoryInterface $adapters)
    {
    }

    /**
     * @param int $channelId 本次通道
     * @param int $revision 通道修订
     * @param IdentityRequest $request 认证输入
     * @return IdentityResult 认证跳转入口
     */
    public function initialize(int $channelId, int $revision, IdentityRequest $request): IdentityResult
    {
        [$id, $configuration] = $this->configuration->resolve('identity', $channelId, $revision, true);
        return $this->adapters->identity($id)->initialize($request, $configuration);
    }

    /**
     * @param int $channelId 原通道
     * @param int $revision 原修订
     * @param IdentityReference $reference 已封存流水
     * @return IdentityResult 已验签结果
     */
    public function query(int $channelId, int $revision, IdentityReference $reference): IdentityResult
    {
        [$id, $configuration] = $this->configuration->resolve('identity', $channelId, $revision, false);
        return $this->adapters->identity($id)->query($reference, $configuration);
    }
}
