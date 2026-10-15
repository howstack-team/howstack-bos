<?php

declare(strict_types=1);

namespace app\modules\channel\infrastructure\mail;

use app\modules\channel\contract\ChannelPlatformRegistryInterface;
use app\modules\channel\contract\mail\MailAdapterExecutorInterface;
use app\modules\plugin\contract\PluginPackageRegistryInterface;
use app\modules\plugin\infrastructure\PluginDependencyLoader;
use app\shared\plugin\contract\MailAdapterInterface;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\mail\MailMessage;
use app\shared\plugin\dto\mail\MailResult;
use RuntimeException;

/** 只执行已安装、启用且摘要一致的邮件入口，配置按本次调用显式传入。 */
final readonly class InstalledMailAdapterExecutor implements MailAdapterExecutorInterface
{
    /**
     * @param PluginPackageRegistryInterface $packages 已验证包
     * @param ChannelPlatformRegistryInterface $platforms 可用声明
     */
    public function __construct(private PluginPackageRegistryInterface $packages, private ChannelPlatformRegistryInterface $platforms,
        private \GuzzleHttp\ClientInterface $http = new \GuzzleHttp\Client())
    {
    }

    /** @inheritDoc */
    public function send(string $pluginId, Configuration $configuration, MailMessage $message): MailResult
    {
        if ($this->platforms->definition('mail', $pluginId) === null) {
            return new MailResult('failed', $message->messageId());
        }
        $package = $this->packages->packages()[$pluginId];
        PluginDependencyLoader::load($package['path'], 'plugins\\mail\\' . $pluginId . '\\');
        $entry = $package['entry'];
        $adapter = $package['validated_network']['transport'] === 'https'
            ? new $entry(new \app\shared\plugin\http\HttpClient($this->http)) : new $entry();
        if (!$adapter instanceof MailAdapterInterface) {
            throw new RuntimeException('邮件插件入口无效');
        }
        return $adapter->send($message, $configuration);
    }
}
