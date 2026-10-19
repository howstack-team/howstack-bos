<?php

declare(strict_types=1);
namespace app\modules\service_instance\contract;
/** 会员服务只读边界，查询时强制约束归属，绝不返回供应密钥或内部映射。 */
interface MemberServiceRepositoryInterface
{
    /** @param int $memberId 本人 @param int $page 页码 @param int $size 每页条数 @return array{data:list<array<string,mixed>>,total:int} 本人云服务器 */
    public function page(int $memberId, int $page, int $size): array;
    /** @param int $memberId 本人 @param int $serviceId 服务 @return array<string,mixed>|null 本人服务 */
    public function find(int $memberId, int $serviceId): ?array;
}
