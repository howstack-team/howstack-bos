<?php

declare(strict_types=1);

namespace app\modules\channel\infrastructure;

use app\modules\channel\contract\ChannelPlatformRegistryInterface;
use app\modules\channel\contract\TransactionAdapterFactoryInterface;
use app\modules\plugin\contract\PluginPackageRegistryInterface;
use app\modules\plugin\infrastructure\PluginDependencyLoader;
use app\shared\plugin\contract\IdentityAdapterInterface;
use app\shared\plugin\contract\PaymentAdapterInterface;
use app\shared\plugin\exception\PluginException;
use app\shared\plugin\http\HttpClient;
use GuzzleHttp\ClientInterface;

/** 启用状态、类别和摘要全部匹配后才实例化；底层 HTTP 可由宿主注入。 */
final readonly class InstalledTransactionAdapterFactory implements TransactionAdapterFactoryInterface
{
    /**
     * @param PluginPackageRegistryInterface $packages 已校验包
     * @param ChannelPlatformRegistryInterface $platforms 安装状态
     * @param ClientInterface $http 宿主 HTTP 客户端
     * @param \app\shared\plugin\contract\LocalObjectStoreInterface|null $localStorage 受限本地存储
     * @param \app\shared\plugin\contract\ImageCaptchaInterface|null $imageCaptcha 受限图像验证码
     */
    public function __construct(
        private PluginPackageRegistryInterface $packages,
        private ChannelPlatformRegistryInterface $platforms,
        private ClientInterface $http,
        private ?\app\shared\plugin\contract\LocalObjectStoreInterface $localStorage = null,
        private ?\app\shared\plugin\contract\ImageCaptchaInterface $imageCaptcha = null,
        private ?\app\modules\plugin\contract\PluginInstallationRepositoryInterface $installations = null,
    ) {
    }

    /** @inheritDoc */
    public function templateSubmission(string $category, string $pluginId): \app\shared\plugin\contract\SubmitTemplateAdapterInterface
    {
        $this->templateCategory($category);
        $adapter = $this->load($category, $pluginId);
        if (!$adapter instanceof \app\shared\plugin\contract\SubmitTemplateAdapterInterface
            || ($this->packages->packages()[$pluginId]['template_review']['submit'] ?? false) !== true) {
            throw new PluginException('该平台不支持模板提交审核', 'capability_unsupported');
        }
        return $adapter;
    }

    /** @inheritDoc */
    public function templateReviewQuery(string $category, string $pluginId): \app\shared\plugin\contract\QueryTemplateReviewAdapterInterface
    {
        $this->templateCategory($category);
        $adapter = $this->load($category, $pluginId);
        if (!$adapter instanceof \app\shared\plugin\contract\QueryTemplateReviewAdapterInterface
            || ($this->packages->packages()[$pluginId]['template_review']['query'] ?? false) !== true) {
            throw new PluginException('该平台不支持模板审核状态查询', 'capability_unsupported');
        }
        return $adapter;
    }

    /** @param string $category 固定通道类别 @return void 拒绝其他类别的模板能力调用 */
    private function templateCategory(string $category): void
    {
        if (!in_array($category, ['sms', 'mail'], true)) {
            throw new PluginException('该通道类别不支持模板审核', 'capability_unsupported');
        }
    }

    /** @inheritDoc */
    public function payment(string $pluginId): PaymentAdapterInterface
    {
        $adapter = $this->load('payment', $pluginId);
        if (!$adapter instanceof PaymentAdapterInterface) {
            throw new PluginException('支付插件入口无效', 'plugin_unavailable');
        }
        return $adapter;
    }

    /** @inheritDoc */
    public function retainedPayment(string $pluginId, string $packageHash): PaymentAdapterInterface
    {
        $package = $this->packages->packages()[$pluginId] ?? null;
        $record = $this->installations?->all()[$pluginId] ?? null;
        if ($package === null || $record === null || $package['category'] !== 'payment'
            || !in_array($record['state'], ['enabled', 'draining'], true)
            || $package['package_hash'] !== $packageHash || $record['package_hash'] !== $packageHash) {
            throw new PluginException('原支付插件不可用，请恢复受信包后核查原交易', 'plugin_unavailable');
        }
        PluginDependencyLoader::load($package['path'], 'plugins\\payment\\' . $pluginId . '\\');
        $entry = $package['entry'];
        $adapter = new $entry(new HttpClient($this->http));
        if (!$adapter instanceof PaymentAdapterInterface) throw new PluginException('原支付插件入口无效', 'plugin_unavailable');
        return $adapter;
    }

    /** @inheritDoc */
    public function identity(string $pluginId): IdentityAdapterInterface
    {
        $adapter = $this->load('identity', $pluginId);
        if (!$adapter instanceof IdentityAdapterInterface) {
            throw new PluginException('身份验证插件入口无效', 'plugin_unavailable');
        }
        return $adapter;
    }

    /**
     * @param string $pluginId 已启用插件
     * @return \app\shared\plugin\contract\SmsAdapterInterface 固定类别入口
     */
    public function sms(string $pluginId): \app\shared\plugin\contract\SmsAdapterInterface
    {
        $adapter = $this->load('sms', $pluginId);
        if (!$adapter instanceof \app\shared\plugin\contract\SmsAdapterInterface) {
            throw new PluginException('通道插件入口无效', 'plugin_unavailable');
        }
        return $adapter;
    }

    /**
     * @param string $pluginId 已启用插件
     * @return \app\shared\plugin\contract\StorageAdapterInterface 固定类别入口
     */
    public function storage(string $pluginId): \app\shared\plugin\contract\StorageAdapterInterface
    {
        $adapter = $this->load('storage', $pluginId);
        if (!$adapter instanceof \app\shared\plugin\contract\StorageAdapterInterface) {
            throw new PluginException('通道插件入口无效', 'plugin_unavailable');
        }
        return $adapter;
    }

    /**
     * @param string $pluginId 已启用插件
     * @return \app\shared\plugin\contract\OAuthAdapterInterface 固定类别入口
     */
    public function oauth(string $pluginId): \app\shared\plugin\contract\OAuthAdapterInterface
    {
        $adapter = $this->load('oauth', $pluginId);
        if (!$adapter instanceof \app\shared\plugin\contract\OAuthAdapterInterface) {
            throw new PluginException('通道插件入口无效', 'plugin_unavailable');
        }
        return $adapter;
    }

    /** @inheritDoc */
    public function captcha(string $pluginId): \app\shared\plugin\contract\CaptchaAdapterInterface
    {
        $adapter = $this->load('captcha', $pluginId);
        if (!$adapter instanceof \app\shared\plugin\contract\CaptchaAdapterInterface) {
            throw new PluginException('人机验证入口无效', 'plugin_unavailable');
        }
        return $adapter;
    }

    /**
     * @param string $category 固定类别
     * @param string $pluginId 已验证身份
     * @return object 单次插件实例
     * @throws PluginException 未启用或摘要改变
     */
    private function load(string $category, string $pluginId): object
    {
        if ($this->platforms->definition($category, $pluginId) === null) {
            throw new PluginException('通道插件未启用或包已变化', 'plugin_unavailable');
        }
        $package = $this->packages->packages()[$pluginId];
        PluginDependencyLoader::load($package['path'], 'plugins\\' . $category . '\\' . $pluginId . '\\');
        $entry = $package['entry'];
        if ($category === 'mail' && $package['validated_network']['transport'] === 'smtp') {
            return new $entry();
        }
        if ($category === 'storage' && $package['validated_network']['transport'] === 'local') {
            return new $entry(new HttpClient($this->http), $this->localStorage ?? new \app\shared\plugin\http\UnavailableLocalObjectStore());
        }
        if ($category === 'captcha' && $package['validated_network']['transport'] === 'local') {
            return new $entry(new HttpClient($this->http), $this->imageCaptcha ?? new \app\shared\plugin\http\UnavailableImageCaptcha());
        }
        return new $entry(new HttpClient($this->http));
    }
}
