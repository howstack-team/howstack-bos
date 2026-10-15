<?php

declare(strict_types=1);

namespace app\modules\official_platform\exception;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;
use Throwable;

/** 使用固定安全提示映射官网失败，不公开凭证、URL或外部响应正文。 */
final class OfficialPlatformException extends BusinessException
{
    public readonly string $reason;

    /**
     * @param string $reason 官网协议失败码
     * @param Throwable|null $previous 私有诊断链
     */
    public function __construct(string $reason, ?Throwable $previous = null)
    {
        $this->reason = preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $reason) ? $reason : 'INVALID_RESPONSE';
        $message = match ($this->reason) {
            'NOT_CONFIGURED' => '尚未配置官网接入地址，请联系系统维护人员',
            'INSTALLATION_BUSY' => '已有安装或更新任务，请等待任务完成',
            'RELEASE_CONTEXT_MISMATCH' => '可用更新已发生变化，请重新查看版本信息后确认',
            'NOT_CONNECTED' => '请先激活软件授权',
            'INVALID_ACTIVATION_CODE' => '授权码无效，请检查后重新填写',
            'SOFTWARE_MISMATCH' => '授权码不适用于本软件',
            'LICENSE_INACTIVE' => '授权已到期或已撤销，请续费或联系服务人员',
            'LICENSE_NOT_FOUND' => '当前实例没有商业授权',
            'INSTANCE_LIMIT_EXCEEDED' => '安装实例名额已用完，请在官网管理绑定实例',
            'INSTALLATION_CONFLICT' => '安装身份不匹配，请核对原安装数据',
            'INSTALLATION_OCCUPIED' => '实例已绑定或旧授权名额尚未释放',
            'UPDATE_NOT_ENTITLED' => '版本已撤回、不适配或不具备更新权益',
            'REPLAY_DETECTED' => '请求已使用，请重新操作',
            'LICENSE_EXPIRED' => '商业授权已到期，请续订后刷新',
            'LICENSE_REPLAY_REJECTED' => '授权证书修订已过期或内容冲突，已保留上次验证结果',
            'LICENSE_CLOCK_ERROR' => '系统时间发生回退，请校准时间后重新验证授权',
            'INVALID_LICENSE_CERTIFICATE' => '商业证书结构无效，请确认官网已接入最新权益契约',
            'ENDPOINT_NOT_AVAILABLE' => '官网尚未提供所需的 v1 接口，请按接入要求完成配置',
            'UNTRUSTED_SIGNATURE' => '官网签名校验未通过，请核对预置的发布公钥',
            'PACKAGE_INTEGRITY_ERROR' => '下载文件与签名清单不一致，已停止安装',
            'INVALID_CONFIGURATION' => '官网接入配置无效，请检查地址及信任公钥',
            'RATE_LIMITED' => '官网请求过于频繁，请稍后重试',
            'INVALID_SIGNATURE', 'REQUEST_EXPIRED' => '官网请求签名被拒绝，请核对实例身份与系统时间',
            'APPLICATION_EXPIRED', 'APPLICATION_NOT_ACQUIRED' => '当前实例没有有效的应用使用权益',
            default => '官网请求未完成，请稍后重试或查看接入状态',
        };
        parent::__construct($message, ApiErrorCode::SERVICE_UNAVAILABLE, 503, previous: $previous,
            data: ['reason' => $this->reason]);
    }
}
