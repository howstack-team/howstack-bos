<?php

declare(strict_types=1);

namespace app\modules\install\contract;

interface InstallationAuthorizationRepositoryInterface
{
    public function issue(string $keyHash, int $expiresAt): void;
    public function valid(string $proof): bool;
    public function revoke(): void;
}
