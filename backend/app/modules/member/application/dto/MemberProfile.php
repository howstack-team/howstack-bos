<?php

declare(strict_types=1);

namespace app\modules\member\application\dto;

/** 仓储到会员应用的安全资料；时间和空值沿用现有协议。 */
final readonly class MemberProfile
{
    public function __construct(
        public int $id,
        public string $nickname,
        public ?int $groupId,
        public ?string $groupName,
        public ?int $levelId,
        public ?string $levelName,
        public ?string $mobile,
        public ?string $email,
        public string $description,
        public int $status,
        public ?string $lastLoginAt,
        public ?string $createdAt,
        public ?string $updatedAt,
        public ?string $publicId = null,
    ) {
    }

    /** @return array<string, int|string|null> 现有会员 HTTP 白名单结构 */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->publicId,
            'nickname' => $this->nickname,
            'group_id' => $this->groupId,
            'group_name' => $this->groupName,
            'level_id' => $this->levelId,
            'level_name' => $this->levelName,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'description' => $this->description,
            'status' => $this->status,
            'last_login_at' => $this->lastLoginAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
