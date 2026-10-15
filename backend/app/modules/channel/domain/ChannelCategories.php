<?php

declare(strict_types=1);

namespace app\modules\channel\domain;

/** 固定的基础通道分类；与产品供应商和应用安装生命周期分别管理。 */
final class ChannelCategories
{
    public const LABELS = [
        'payment' => '支付通道',
        'sms' => '短信通道',
        'mail' => '邮件通道',
        'identity' => '实名认证',
        'storage' => '文件存储',
        'oauth' => '第三方登录',
        'captcha' => '人机验证',
    ];

    /** 只有发送与新文件存储需要类别级主用配置；其他类别由具体业务选择。 */
    public const DEFAULT_LABELS = [
        'sms' => '主用通道',
        'mail' => '主用通道',
        'storage' => '默认存储',
    ];
}
