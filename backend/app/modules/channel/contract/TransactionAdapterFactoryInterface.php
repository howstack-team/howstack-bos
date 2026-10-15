<?php

declare(strict_types=1);

namespace app\modules\channel\contract;

use app\shared\plugin\contract\IdentityAdapterInterface;
use app\shared\plugin\contract\PaymentAdapterInterface;

/** 宿主只加载固定类别接口，不开放任意类或方法调用。 */
interface TransactionAdapterFactoryInterface
{
    /**
     * @param string $category sms 或 mail
     * @param string $pluginId 已审核、启用且实现提交接口的插件
     * @return \app\shared\plugin\contract\SubmitTemplateAdapterInterface 可选模板提交入口
     * @throws \app\shared\plugin\exception\PluginException 类别不支持、能力不存在或包不可用
     */
    public function templateSubmission(string $category, string $pluginId): \app\shared\plugin\contract\SubmitTemplateAdapterInterface;

    /**
     * @param string $category sms 或 mail
     * @param string $pluginId 已审核、启用且实现查询接口的插件
     * @return \app\shared\plugin\contract\QueryTemplateReviewAdapterInterface 可选审核查询入口
     * @throws \app\shared\plugin\exception\PluginException 类别不支持、能力不存在或包不可用
     */
    public function templateReviewQuery(string $category, string $pluginId): \app\shared\plugin\contract\QueryTemplateReviewAdapterInterface;

    /**
     * @param string $pluginId 已安装的支付插件
     * @return PaymentAdapterInterface 支付入口
     */
    public function payment(string $pluginId): PaymentAdapterInterface;

    /** 原交易收尾入口；仅审核摘要匹配且已安装的支付插件，支持draining，不能用于选择新通道。 */
    public function retainedPayment(string $pluginId, string $packageHash): PaymentAdapterInterface;

    /**
     * @param string $pluginId 已安装的身份验证插件
     * @return IdentityAdapterInterface 身份验证入口
     */
    public function identity(string $pluginId): IdentityAdapterInterface;
    /**
     * @param string $pluginId 已启用插件
     * @return \app\shared\plugin\contract\SmsAdapterInterface 固定通道入口
     */
    public function sms(string $pluginId): \app\shared\plugin\contract\SmsAdapterInterface;

    /**
     * @param string $pluginId 已启用插件
     * @return \app\shared\plugin\contract\StorageAdapterInterface 固定通道入口
     */
    public function storage(string $pluginId): \app\shared\plugin\contract\StorageAdapterInterface;

    /**
     * @param string $pluginId 已启用插件
     * @return \app\shared\plugin\contract\OAuthAdapterInterface 固定通道入口
     */
    public function oauth(string $pluginId): \app\shared\plugin\contract\OAuthAdapterInterface;

    /**
     * @param string $pluginId 已启用的人机验证插件
     * @return \app\shared\plugin\contract\CaptchaAdapterInterface 固定验证入口
     */
    public function captcha(string $pluginId): \app\shared\plugin\contract\CaptchaAdapterInterface;

}
