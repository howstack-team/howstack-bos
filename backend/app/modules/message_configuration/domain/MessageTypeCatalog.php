<?php

declare(strict_types=1);

namespace app\modules\message_configuration\domain;

use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

/** 消息事件、可用渠道和模板变量由核心发布，不能由浏览器任意新增。 */
final class MessageTypeCatalog
{
    public const CHANNELS = [
        'customer' => ['email' => '邮件', 'sms' => '短信', 'message' => '消息', 'wechat' => '公众号'],
        'admin' => ['email' => '邮件', 'dingtalk' => '钉钉', 'wecom' => '企业微信', 'feishu' => '飞书', 'message' => '消息'],
    ];

    private const VARIABLES = [
        'site_name' => '站点名称', 'customer_name' => '客户名称', 'code' => '验证码', 'expires_in' => '有效分钟数',
        'order_no' => '订单编号', 'amount' => '金额（元）', 'service_name' => '服务名称', 'service_no' => '服务编号',
        'due_at' => '到期时间', 'ticket_no' => '工单编号', 'ticket_title' => '工单标题',
        'reason' => '处理说明', 'version' => '版本号', 'occurred_at' => '发生时间',
    ];

    /** @return array<string, array<string, mixed>> 当前受众的固定事件目录 */
    public static function all(string $audience): array
    {
        if (!isset(self::CHANNELS[$audience])) {
            throw new BusinessException('消息接收对象无效', ApiErrorCode::VALIDATION_FAILED, 422);
        }
        $customer = [
            'register_verification' => ['注册验证码', '客户注册时验证手机号或邮箱', ['sms', 'email'], ['code', 'expires_in']],
            'login_verification' => ['登录验证码', '客户登录时使用手机号验证码验证身份', ['sms'], ['code', 'expires_in']],
            'password_reset' => ['找回密码', '客户找回密码时验证身份', ['email', 'sms'], ['code', 'expires_in']],
            'security_changed' => ['账号安全变更', '密码、邮箱或手机号等安全资料变更后通知客户', ['email', 'sms', 'message'], ['occurred_at']],
            'order_created' => ['订单创建', '订单创建后提醒客户确认和付款', ['email', 'message'], ['order_no', 'amount']],
            'order_paid' => ['订单支付成功', '订单收款确认后通知客户', ['email', 'sms', 'message', 'wechat'], ['order_no', 'amount']],
            'order_cancelled' => ['订单取消', '订单取消或关闭后通知客户', ['email', 'message'], ['order_no']],
            'service_activated' => ['服务开通成功', '服务交付完成后通知客户，不包含实例密码', ['email', 'sms', 'message', 'wechat'], ['service_name', 'service_no']],
            'service_due' => ['服务即将到期', '服务到期前提醒客户续费', ['email', 'sms', 'message', 'wechat'], ['service_name', 'service_no', 'due_at']],
            'service_expired' => ['服务已到期', '服务到期后通知客户', ['email', 'sms', 'message', 'wechat'], ['service_name', 'service_no', 'due_at']],
            'service_suspended' => ['服务暂停', '服务暂停后通知客户处理', ['email', 'sms', 'message', 'wechat'], ['service_name', 'service_no', 'reason']],
            'renewal_succeeded' => ['续费成功', '服务续期确认后通知客户', ['email', 'sms', 'message', 'wechat'], ['service_name', 'due_at']],
            'renewal_failed' => ['自动续费失败', '余额不足等原因导致自动续费失败时提醒客户', ['email', 'sms', 'message', 'wechat'], ['service_name', 'reason']],
            'ticket_replied' => ['工单回复', '工作人员回复工单后通知客户', ['email', 'message', 'wechat'], ['ticket_no', 'ticket_title']],
            'real_name_approved' => ['实名认证通过', '实名认证审核通过后通知客户', ['email', 'sms', 'message'], []],
            'real_name_rejected' => ['实名认证未通过', '实名认证审核未通过后通知客户补充资料', ['email', 'sms', 'message'], ['reason']],
            'recharge_received' => ['充值到账', '充值入账后通知客户', ['email', 'sms', 'message', 'wechat'], ['amount']],
            'refund_completed' => ['退款完成', '退款完成后通知客户', ['email', 'sms', 'message', 'wechat'], ['order_no', 'amount']],
            'invoice_issued' => ['发票已开具', '发票开具后通知客户查看', ['email', 'message', 'wechat'], ['amount']],
        ];
        $admin = [
            'order_created' => ['新订单', '客户创建订单后通知管理员', ['order_no', 'amount']],
            'order_paid' => ['订单已付款', '订单收款确认后通知管理员', ['order_no', 'amount']],
            'delivery_failed' => ['服务交付异常', '服务开通失败或结果未知时通知管理员处理', ['service_name', 'service_no', 'reason']],
            'renewal_failed' => ['服务续费异常', '服务续期失败或结果未知时通知管理员处理', ['service_name', 'service_no', 'reason']],
            'ticket_created' => ['新工单', '客户提交工单后通知管理员处理', ['ticket_no', 'ticket_title']],
            'ticket_replied' => ['客户回复工单', '客户回复工单后通知管理员', ['ticket_no', 'ticket_title']],
            'real_name_pending' => ['实名认证待审核', '客户提交实名认证后通知管理员审核', []],
            'refund_pending' => ['退款待审核', '客户提交退款申请后通知管理员审核', ['order_no', 'amount']],
            'invoice_pending' => ['发票待处理', '客户提交发票申请后通知管理员处理', ['amount']],
            'security_alert' => ['安全告警', '发现异常登录等安全事件时通知管理员', ['reason', 'occurred_at']],
            'system_update' => ['发现系统新版本', '后端发现已验证的新版本后通知管理员', ['version']],
        ];
        $result = [];
        foreach ($audience === 'customer' ? $customer : $admin as $code => $item) {
            [$name, $description] = $item;
            $channels = $audience === 'customer' ? $item[2] : array_keys(self::CHANNELS['admin']);
            $keys = array_merge(['site_name'], $audience === 'customer' ? ['customer_name'] : [], $audience === 'customer' ? $item[3] : $item[2]);
            $result[$code] = [
                'code' => $code, 'name' => $name, 'description' => $description, 'channels' => $channels,
                'variables' => array_map(static fn (string $key): array => ['key' => $key, 'label' => self::VARIABLES[$key]], $keys),
            ];
        }
        return $result;
    }

    /** @return array<string, mixed> 固定事件及其支持范围 */
    public static function get(string $audience, string $code): array
    {
        return self::all($audience)[$code]
            ?? throw new BusinessException('消息类型不存在', ApiErrorCode::NOT_FOUND, 404);
    }

    /** @return array<string, array<string, mixed>> 同一场景合并展示，保留各受众的方向与变量声明。 */
    public static function scenes(): array
    {
        $result = [];
        foreach (['customer', 'admin'] as $audience) {
            foreach (self::all($audience) as $code => $type) {
                $result[$code] ??= ['code' => $code, 'name' => $code === 'ticket_replied' ? '工单回复' : $type['name'],
                    'description' => $type['description'],
                    'purpose' => \app\modules\message\domain\MessageScene::metadata($code)['purpose'], 'audiences' => []];
                $result[$code]['audiences'][$audience] = $type;
            }
        }
        return $result;
    }

    /** @return array<string, int|bool|string> 默认禁用的模板初值 */
    public static function defaults(array $type, string $channel): array
    {
        $values = ['enabled' => false];
        if (in_array($channel, ['email', 'sms'], true)) {
            $values['channel_id'] = 0;
        }
        if (in_array($channel, ['email', 'message', 'dingtalk', 'wecom', 'feishu'], true)) {
            $values['title'] = '{{site_name}} - ' . $type['name'];
        }
        if ($channel !== 'wechat') {
            $values['body'] = $type['description'] . '。';
            foreach ($type['variables'] as $variable) {
                if (!in_array($variable['key'], ['site_name', 'customer_name'], true)) {
                    $values['body'] .= '\n' . $variable['label'] . '：{{' . $variable['key'] . '}}';
                }
            }
            $values['body'] = str_replace('\\n', "\n", $values['body']);
        }
        if (in_array($channel, ['sms', 'wechat'], true)) {
            $values['template_id'] = '';
            $values['variables'] = '{}';
        }
        return $values;
    }

}
