<?php

declare(strict_types=1);

namespace app\modules\admin\access\domain;

/**
 * 表示一次请求可见的行级数据集合。
 */
final readonly class AdminDataScope
{
    /**
     * 创建可直接应用于数据库查询的数据范围。
     * @param bool      $all           是否允许全部数据
     * @param bool      $self          是否允许本人数据
     * @param list<int> $departmentIds 可见的部门标识
     */
    public function __construct(
        public bool $all,
        public bool $self,
        public array $departmentIds,
    ) {
    }
}
