<?php

declare(strict_types=1);

namespace app\modules\licensing\domain;

use app\shared\entitlement\EntitlementOperation;
use InvalidArgumentException;

/** 宿主代码登记的能力；证书不能远程添加处理器或放宽到期策略。 */
final readonly class CapabilityDefinition
{
    /**
     * @param string $id 稳定能力标识
     * @param string $name 中文展示名称
     * @param array<string, string> $permissions 操作值到 RBAC 权限的映射
     * @param bool $installed 已完成实际接入且当前可以执行
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $permissions,
        public bool $installed = true,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $id)
            || strlen($id) > 96 || trim($name) === '' || mb_strlen($name) > 80 || $permissions === []) {
            throw new InvalidArgumentException('能力定义无效');
        }
        foreach ($permissions as $operation => $permission) {
            if (EntitlementOperation::tryFrom($operation) === null || !is_string($permission)
                || !preg_match('/^[a-z][a-z0-9_.]{1,127}$/D', $permission)) {
                throw new InvalidArgumentException('能力操作或权限定义无效');
            }
        }
    }
}
