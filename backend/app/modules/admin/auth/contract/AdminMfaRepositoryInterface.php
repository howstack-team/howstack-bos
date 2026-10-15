<?php

declare(strict_types=1);
namespace app\modules\admin\auth\contract;
/** 锁顺序固定为管理员、MFA凭据、登录挑战；失败次数必须提交后再抛业务异常。 */
interface AdminMfaRepositoryInterface
{
    /** @param callable(array<string,mixed>): mixed $handler */
    public function locked(int $adminId, callable $handler): mixed;
    /** @return array<string,mixed>|null */
    public function find(int $adminId): ?array;
    /** 仅在locked事务内调用。 @param array<string,mixed> $values */
    public function save(int $adminId, array $values, bool $rotate = false): void;
    /** 创建新挑战并撤销该账号旧挑战。 @param array<string,mixed> $values */
    public function createChallenge(array $values): void;
    /** @return array<string,mixed>|null */
    public function challenge(string $hash): ?array;
    public function attempt(string $hash, bool $consume): void;
}
