<?php

declare(strict_types=1);

namespace app\modules\member\contract;

interface MemberSessionRepositoryInterface
{
    public function create(string $id, int $memberId, string $passwordVersion, int $expiresAt): void;
    public function active(string $id, string $publicId, string $passwordVersion): bool;
    public function revoke(string $id): void;
}
