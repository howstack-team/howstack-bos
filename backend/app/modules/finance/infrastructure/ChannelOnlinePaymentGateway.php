<?php

declare(strict_types=1);

namespace app\modules\finance\infrastructure;

use app\exception\BusinessException;
use app\modules\finance\contract\OnlinePaymentGatewayInterface;
use app\modules\channel\contract\{ChannelRepositoryInterface, ChannelPlatformRegistryInterface, TransactionAdapterFactoryInterface};
use app\modules\channel\application\ChannelExecutionConfiguration;
use app\shared\contract\SensitiveDataCipherInterface;
use app\shared\http\ApiErrorCode;
use app\shared\plugin\contract\JsonPaymentNotificationAdapterInterface;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\payment\{PaymentRequest, PaymentResult, PaymentNotification};
use app\shared\plugin\exception\PluginException;

final readonly class ChannelOnlinePaymentGateway implements OnlinePaymentGatewayInterface
{
    public function __construct(private ChannelRepositoryInterface $channels, private ChannelPlatformRegistryInterface $platforms,
        private ChannelExecutionConfiguration $configuration, private TransactionAdapterFactoryInterface $adapters, private SensitiveDataCipherInterface $cipher) {}

    public function channels(): array
    {
        $result = [];
        for ($page = 1; ; $page++) {
            $rows = $this->channels->list('payment', ['status' => 1, 'page' => $page, 'page_size' => 100]);
            foreach ($rows['data'] as $row) {
                if ($row['platform_available'] && $row['configuration_current'])
                    $result[] = ['id' => (int) $row['id'], 'name' => $row['name'], 'plugin_id' => $row['plugin_id']];
            }
            if ($page * 100 >= $rows['total']) break;
        }
        return $result;
    }

    public function capture(int $channelId): array
    {
        $row = $this->channels->find('payment', $channelId);
        [$plugin, $config] = $this->configuration->resolve('payment', $channelId, (int) $row['revision'], true);
        $definition = $this->platforms->definition('payment', $plugin);
        if ($definition === null) throw new BusinessException('支付插件不可用', ApiErrorCode::SERVICE_UNAVAILABLE, 503);
        return ['plugin_id' => $plugin, 'channel_revision' => (int) $row['revision'], 'package_hash' => $definition['package_hash'],
            'configuration' => $this->adapters->payment($plugin)->validateConfiguration($config)];
    }

    public function execute(string $operation, array $attempt): PaymentResult
    {
        $adapter = $this->adapters->retainedPayment($attempt['plugin_id'], $attempt['package_hash']);
        $config = $this->config($attempt);
        $request = $this->request($attempt);
        return match ($operation) { 'create' => $adapter->create($request, $config), 'query' => $adapter->query($request, $config), 'close' => $adapter->close($request, $config), default => throw new \LogicException('支付操作无效') };
    }

    public function verify(array $attempt, array|PaymentNotification $notification): PaymentResult
    {
        $adapter = $this->adapters->retainedPayment($attempt['plugin_id'], $attempt['package_hash']);
        if ($notification instanceof PaymentNotification) {
            if (!$adapter instanceof JsonPaymentNotificationAdapterInterface) throw new PluginException('支付回调类型不匹配', 'invalid_notification');
            return $adapter->verifyJsonNotification($notification, $this->request($attempt), $this->config($attempt));
        }
        return $adapter->verifyNotification($notification, $this->request($attempt), $this->config($attempt));
    }

    private function config(array $attempt): Configuration
    {
        return new Configuration(json_decode($this->cipher->decrypt($attempt['configuration_encrypted']), true, 16, JSON_THROW_ON_ERROR));
    }
    private function request(array $attempt): PaymentRequest
    {
        return new PaymentRequest($attempt['payment_no'], $attempt['amount_minor'], '账户余额充值', $attempt['notify_url']);
    }
}
