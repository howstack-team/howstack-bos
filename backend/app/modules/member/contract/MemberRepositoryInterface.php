<?php

declare(strict_types=1);

namespace app\modules\member\contract;

/** 会员资料持久化契约。 */
interface MemberRepositoryInterface
{
    /**
     * 分页查询会员。
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function paginate(string $keyword, ?int $status, int $page, int $pageSize): array;

    /**
     * 查询指定会员的安全资料。
     * @param int $id 会员标识
     * @return array<string, mixed>|null 会员资料，不存在时返回 null
     */
    public function find(int $id): ?array;

    /** 判断手机号是否已被使用。 */
    public function mobileExists(string $mobile, ?int $exceptId = null): bool;

    /** 判断邮箱是否已被使用。 */
    public function emailExists(string $email, ?int $exceptId = null): bool;

    /**
     * 新增或更新会员。
     * @param int|null             $id   会员标识，新增时为空
     * @param array<string, mixed> $data 已规范化的持久化字段
     * @return int 保存后的会员标识
     */
    public function save(?int $id, array $data): int;

    /** 判断会员是否存在必须保留的实名认证历史。 */
    public function hasRealNameRecords(int $id): bool;
    /**
     * 判断会员是否存在不可删除的订单历史。
     * @param int $id 会员标识
     * @return bool 存在订单时返回 true
     */
    public function hasOrders(int $id): bool;

    /** 删除指定会员。 */
    public function delete(int $id): void;
}
