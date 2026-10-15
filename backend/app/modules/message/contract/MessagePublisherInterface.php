<?php

declare(strict_types=1);

namespace app\modules\message\contract;

/** 跨模块公开消息命令；随业务事务提交，不执行网络请求。 */
interface MessagePublisherInterface
{
    /** @param array<string, int|string> $variables 固定业务变量 @param list<string> $audiences 场景适用接收组 */
    public function publish(string $eventKey, string $scene, string $businessType, int $businessId, int $memberId,
        array $variables, array $audiences = ['customer', 'admin']): int;
}
