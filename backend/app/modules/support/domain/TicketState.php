<?php

declare(strict_types=1);

namespace app\modules\support\domain;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 保留既有四种状态，关闭终结沟通，不允许隐式重新打开。 */
final class TicketState
{
    /**
     * 关闭状态禁止回复，按主体确定下一状态。
     * @param string $status 既有状态筛选，NULL不筛选
     * @param string $actorType member会员或admin管理员
     * @return string 已校验结果或统一响应
     */
    public static function afterReply(string $status, string $actorType): string
    {
        if (!in_array($status, ['open', 'processing', 'waiting_member'], true)) {
            throw new BusinessException('工单已关闭，不能继续回复', ApiErrorCode::CONFLICT, 409);
        }
        return $actorType === 'admin' ? 'waiting_member' : 'open';
    }
}
