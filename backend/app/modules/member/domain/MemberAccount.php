<?php

declare(strict_types=1);

namespace app\modules\member\domain;

final readonly class MemberAccount
{
    public function __construct(
        public int $id,
        public ?string $mobile,
        public string $nickname,
        public string $passwordHash,
        public int $status,
        public string $publicId,
        public ?string $email = null,
    ) {
    }

    /** @return array{id: string, mobile: ?string, email: ?string, nickname: string} */
    public function profile(): array
    {
        if (!PublicMemberId::valid($this->publicId)) throw new \LogicException('会员公开编号未初始化');
        return ['id' => $this->publicId, 'mobile' => $this->mobile, 'email' => $this->email, 'nickname' => $this->nickname];
    }
}
