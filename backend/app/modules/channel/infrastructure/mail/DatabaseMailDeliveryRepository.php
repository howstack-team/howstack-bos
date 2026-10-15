<?php

declare(strict_types=1);

namespace app\modules\channel\infrastructure\mail;

use app\exception\BusinessException;
use app\modules\channel\contract\ChannelPlatformRegistryInterface;
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\channel\contract\mail\MailDeliveryRepositoryInterface;
use app\modules\channel\infrastructure\model\ChannelConnectionModel;
use app\modules\plugin\contract\PluginInstallationRepositoryInterface;
use app\shared\contract\TransactionManagerInterface;
use app\shared\http\ApiErrorCode;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\mail\MailMessage;
use app\shared\plugin\dto\mail\MailResult;
use support\Db;
use Illuminate\Database\UniqueConstraintViolationException;

/** 先锁插件再锁通道；发送开始后允许收尾，不因通道变更重复提交。 */
final readonly class DatabaseMailDeliveryRepository implements MailDeliveryRepositoryInterface
{
    /**
     * @param TransactionManagerInterface $transactions 事务
     * @param ChannelRepositoryInterface $channels 加密配置
     * @param ChannelPlatformRegistryInterface $platforms 可用平台
     * @param PluginInstallationRepositoryInterface $installations 插件锁
     */
    public function __construct(
        private TransactionManagerInterface $transactions,
        private ChannelRepositoryInterface $channels,
        private ChannelPlatformRegistryInterface $platforms,
        private PluginInstallationRepositoryInterface $installations,
    ) {
    }

    /** @inheritDoc */
    public function claim(int $channelId, int $revision, MailMessage $message, ?int $testActor): array
    {
        $hash = hash('sha256', json_encode([$channelId, $revision, $testActor, $message->recipient, $message->subject, $message->text, $message->html], JSON_THROW_ON_ERROR));
        // 已领取请求只查询结果；插件/通道后来停用或配置变更也不能重复执行。
        if (($cached = $this->cached($message, $hash)) !== null) {
            return $cached;
        }
        $identity = $this->channels->find('mail', $channelId);
        return $this->transactions->run(function () use ($channelId, $revision, $message, $testActor, $identity, $hash): array {
            $this->installations->lockAccepting($identity['plugin_id']);
            $channel = ChannelConnectionModel::query()->where('id', $channelId)->where('category', 'mail')->whereNull('deleted_at')->lockForUpdate()->first();
            if ($channel === null) {
                throw new BusinessException('邮件通道不存在', ApiErrorCode::NOT_FOUND, 404);
            }
            if (($cached = $this->cached($message, $hash)) !== null) {
                return $cached;
            }
            if ($channel->revision !== $revision || ($testActor === null && $channel->status !== 1)) {
                throw new BusinessException('邮件通道已变化或尚未启用，请刷新确认配置', ApiErrorCode::CONFLICT, 409);
            }
            $platform = $this->platforms->definition('mail', $channel->plugin_id);
            if ($platform === null || ($channel->platform_snapshot['package_hash'] ?? '') !== $platform['package_hash']) {
                throw new BusinessException('邮件平台不可用或配置需要重新保存', ApiErrorCode::CONFLICT, 409);
            }
            if ($testActor !== null && Db::table('mail_delivery_attempts')->where('channel_id', $channelId)->where('purpose', 'test')
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 30))->exists()) {
                throw new BusinessException('测试发送间隔至少30秒，请稍后再试', ApiErrorCode::TOO_MANY_REQUESTS, 429);
            }
            $configuration = new Configuration($this->channels->configuration('mail', $channelId));
            $now = date('Y-m-d H:i:s');
            try {
                Db::table('mail_delivery_attempts')->insert([
                    'request_key' => $message->key, 'channel_id' => $channelId, 'channel_revision' => $revision,
                    'actor_id' => $testActor, 'purpose' => $testActor === null ? 'business' : 'test',
                    'payload_hash' => $hash, 'status' => 'sending', 'created_at' => $now, 'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new BusinessException('发送请求标识已被其他通道使用', ApiErrorCode::CONFLICT, 409);
            }
            return ['configuration' => $configuration, 'plugin_id' => $channel->plugin_id, 'cached' => null];
        });
    }

    /**
     * @param MailMessage $message 已验证邮件
     * @param string $hash 请求摘要
     * @return array<string, mixed>|null 已领取的固定结果
     */
    private function cached(MailMessage $message, string $hash): ?array
    {
        $existing = Db::table('mail_delivery_attempts')->where('request_key', $message->key)->first();
        if ($existing === null) {
            return null;
        }
        if (!hash_equals($existing->payload_hash, $hash)) {
            throw new BusinessException('发送请求标识已被其他内容使用', ApiErrorCode::CONFLICT, 409);
        }
        return ['configuration' => null, 'plugin_id' => '',
            'cached' => new MailResult($existing->status === 'sending' ? 'unknown' : $existing->status, $message->messageId())];
    }

    /** @inheritDoc */
    public function finish(string $key, MailResult $result): void
    {
        Db::table('mail_delivery_attempts')->where('request_key', $key)->where('status', 'sending')
            ->update(['status' => $result->status, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /** @inheritDoc */
    public function defaultChannel(): array
    {
        $id = Db::table('channel_category_settings')->where('category', 'mail')->value('default_channel_id');
        $row = $id === null ? null : ChannelConnectionModel::query()->where('id', $id)->where('category', 'mail')
            ->where('status', 1)->whereNull('deleted_at')->first();
        if ($row === null) {
            throw new BusinessException('请先配置并启用默认邮件通道', ApiErrorCode::CONFLICT, 409);
        }
        return ['id' => $row->id, 'revision' => $row->revision];
    }
}
