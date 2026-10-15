<?php

declare(strict_types=1);

namespace app\modules\system_settings\application;

use app\modules\system_settings\contract\SystemSettingsRepositoryInterface;
use RuntimeException;

/** 供其他模块读取类型明确的设置；常驻进程中不缓存可变配置。 */
final readonly class GetSystemSettingsService
{
    public const array BASE_KEYS = [
        'site_title' => 'site.title',
        'site_url' => 'site.url',
        'copyright' => 'site.copyright',
        'icp_record' => 'site.icp_record',
        'public_security_record' => 'site.public_security_record',
        'business_license' => 'site.business_license',
    ];

    public const array SECURITY_KEYS = [
        'session_expire' => 'security.session_expire',
        'login_attempts' => 'security.login_attempts',
        'login_window' => 'security.login_window',
    ];

    public const array REGISTRATION_KEYS = [
        'mobile_enabled' => 'registration.mobile_enabled',
        'email_enabled' => 'registration.email_enabled',
        'mobile_verification' => 'registration.mobile_verification',
        'email_verification' => 'registration.email_verification',
    ];

    /** 只公开开关；缺失或损坏时阻止注册，常驻进程不缓存。 */
    public function registration(): array
    {
        $values = $this->repository->values('registration');
        $result = [];
        foreach (self::REGISTRATION_KEYS as $field => $key) {
            if (!isset($values[$key]) || !in_array($values[$key], ['0', '1'], true)) {
                throw new RuntimeException('会员注册设置无效，请完成系统迁移');
            }
            $result[$field] = (int) $values[$key];
        }
        return $result;
    }

    /**
     * 注入持久化边界。
     * @param SystemSettingsRepositoryInterface $repository 设置仓储
     */
    public function __construct(private SystemSettingsRepositoryInterface $repository)
    {
    }

    /**
     * 读取基础设置，旧安装缺少可选项时返回空字符串。
     * @return array{site_title: string, site_url: string, copyright: string, icp_record: string, public_security_record: string, business_license: string} 基础设置
     */
    public function base(): array
    {
        $values = $this->repository->values('base');
        $result = [];
        foreach (self::BASE_KEYS as $field => $key) {
            $result[$field] = $values[$key] ?? '';
        }

        return $result;
    }

    /**
     * 读取登录策略，损坏或缺失的安全配置阻止认证而不是静默放行。
     * @return array{session_expire: int, login_attempts: int, login_window: int} 分钟、次数、秒
     * @throws RuntimeException 安全配置不完整或超出有效范围
     */
    public function security(): array
    {
        $values = $this->repository->values('security');
        $ranges = ['session_expire' => [5, 1440], 'login_attempts' => [1, 100], 'login_window' => [10, 3600]];
        $result = [];
        foreach (self::SECURITY_KEYS as $field => $key) {
            $value = filter_var($values[$key] ?? null, FILTER_VALIDATE_INT);
            [$min, $max] = $ranges[$field];
            if ($value === false || $value < $min || $value > $max) {
                throw new RuntimeException('安全设置无效，请检查系统设置迁移和配置');
            }
            $result[$field] = $value;
        }

        return $result;
    }
}
