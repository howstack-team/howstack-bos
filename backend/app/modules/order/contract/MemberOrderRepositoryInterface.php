<?php

declare(strict_types=1);

namespace app\modules\order\contract;

/** 只查询当前会员订单，不包含管理员备注与供应资料。 */
interface MemberOrderRepositoryInterface
{
    /** @param int $memberId 认证会员 @param int $page 页码 @param int $size 每页条数 @return array{data:list<array<string,mixed>>,total:int} 订单列表 */
    public function paginate(int $memberId, int $page, int $size): array;
    /** @param int $memberId 认证会员 @param int $id 订单 @return array<string,mixed>|null 本人订单或null */
    public function find(int $memberId, int $id): ?array;
}
