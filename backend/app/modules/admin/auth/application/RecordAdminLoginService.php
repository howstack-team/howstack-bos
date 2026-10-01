<?php

declare(strict_types=1);

namespace app\modules\admin\auth\application;

use app\modules\admin\auth\contract\LoginLogRepositoryInterface;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\admin\auth\domain\UserAgentSummary;
use app\shared\contract\IpLocationResolverInterface;

/** 在登录请求中保存认证结果及其环境快照。 */
final readonly class RecordAdminLoginService
{
    public function __construct(
        private LoginLogRepositoryInterface $logs,
        private IpLocationResolverInterface $locations,
    ) {
    }

    public function record(?AdminAccount $account, string $username, bool $success, string $ip, string $userAgent, string $reason = ''): void
    {
        $environment = UserAgentSummary::parse($userAgent);
        $this->logs->append([
            'admin_user_id' => $account?->id,
            'username' => mb_substr($username, 0, 32),
            'nickname' => mb_substr($account?->nickname ?? $username, 0, 32),
            'status' => $success ? 1 : 0,
            'client_ip' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0',
            'client_ip_location' => $this->locations->resolve($ip),
            'browser' => $environment['browser'],
            'operating_system' => $environment['operating_system'],
            'failure_reason' => $success ? '' : $reason,
            'logged_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
