<?php

declare(strict_types=1);

namespace app\modules\message\contract;

/** 跨模块公开消息命令；随业务事务提交，不执行网络请求。 */
interface MessagePublisherInterface
{
    /** 发布持久通知计划，不执行外部网络请求。
     * @param string $eventKey 业务幂等事件键
     * @param string $scene 固定通知场景
     * @param string $businessType 固定业务类型
     * @param int $businessId 业务内部ID
     * @param int $memberId 关联会员内部ID
     * @param array<string,int|string> $variables 白名单变量
     * @param list<string> $audiences 接收受众
     * @param bool $ensureMemberInbox 工单回复必须保留本人站内提醒，避免与已配置渠道重复
     * @return int 持久化逻辑消息ID
     */
    public function publish(string $eventKey, string $scene, string $businessType, int $businessId, int $memberId,
        array $variables, array $audiences = ['customer', 'admin'], bool $ensureMemberInbox = false): int;
}
