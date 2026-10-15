<?php

declare(strict_types=1);

namespace app\modules\member\contract;

use app\modules\member\domain\MemberAccount;

interface MemberAuthRepositoryInterface
{
    public function findByMobile(string $mobile): ?MemberAccount;

    public function findByEmail(string $email): ?MemberAccount;

    public function findByPublicId(string $publicId): ?MemberAccount;

    public function findById(int $id): ?MemberAccount;

    public function recordLogin(int $id): void;
}
