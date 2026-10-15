<?php

declare(strict_types=1);

namespace app\modules\install\application;

use app\modules\install\contract\{InstallationAuthorizationRepositoryInterface, InstallationStateRepositoryInterface};
use app\modules\install\exception\InstallationException;

final readonly class InstallationAuthorizationService
{
    public function __construct(private InstallationAuthorizationRepositoryInterface $keys,
        private InstallationStateRepositoryInterface $state)
    {
    }

    /** 仅受控CLI调用；轮换立即撤销旧浏览器安装授权。 */
    public function issue(): string
    {
        $this->assertUninstalled();
        $key = bin2hex(random_bytes(32));
        $this->keys->issue(hash('sha256', $key), time() + 3600);
        return $key;
    }

    public function authorize(string $key): string
    {
        $this->assertUninstalled();
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) throw $this->denied();
        $proof = hash('sha256', $key);
        $this->assertProof($proof);
        return $proof;
    }

    public function assertProof(string $proof): void
    {
        $this->assertUninstalled();
        if (!$this->keys->valid($proof)) throw $this->denied();
    }

    public function revoke(): void
    {
        $this->keys->revoke();
    }

    private function assertUninstalled(): void
    {
        if ($this->state->isInstalled()) throw new InstallationException('系统已经安装，禁止重新授权安装', 409);
    }

    private function denied(): InstallationException
    {
        return new InstallationException('请先使用服务器命令生成安装授权码，授权码无效或已过期', 403);
    }
}
