<?php

declare(strict_types=1);

namespace app\modules\member\domain;

final readonly class MemberAccount
{
    public function __construct(
        public int $id,
        public string $mobile,
        public string $nickname,
        public string $passwordHash,
        public int $status,
    ) {
    }

    /** @return array{id: int, mobile: string, nickname: string} */
    public function profile(): array
    {
        return ['id' => $this->id, 'mobile' => $this->mobile, 'nickname' => $this->nickname];
    }
}
