<?php

declare(strict_types=1);

namespace app\modules\admin\auth\domain;

/**
 * 表示可用于运营后台认证的管理员账号。
 */
final readonly class AdminAccount
{
    /**
     * 创建管理员账号实体。
     * @param int         $id           管理员标识
     * @param string      $username     管理员账号
     * @param string      $nickname     管理员昵称
     * @param string      $passwordHash 密码哈希
     * @param int         $status       状态，1 表示启用
     * @param string|null $lastLoginAt  最近登录时间
     * @param bool        $isFounder    是否为安装时创建的创始管理员
     * @param int|null    $departmentId 所属部门标识
     */
    public function __construct(
        public int $id,
        public string $username,
        public string $nickname,
        public string $passwordHash,
        public int $status,
        public ?string $lastLoginAt,
        public bool $isFounder = false,
        public ?int $departmentId = null,
    ) {
    }

    /**
     * 判断管理员账号是否允许登录。
     * @return bool 状态为启用时返回 true
     */
    public function isActive(): bool
    {
        return $this->status === 1;
    }

    /**
     * 生成可安全返回给前端的管理员资料。
     * @return array{id: int, username: string, nickname: string, last_login_at: string|null, is_founder: bool, department_id: int|null} 管理员公开资料
     */
    public function profile(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'nickname' => $this->nickname,
            'last_login_at' => $this->lastLoginAt,
            'is_founder' => $this->isFounder,
            'department_id' => $this->departmentId,
        ];
    }
}
