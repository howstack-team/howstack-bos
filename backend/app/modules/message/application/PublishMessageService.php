<?php

declare(strict_types=1);

namespace app\modules\message\application;

use app\modules\message\contract\MessageRepositoryInterface;
use app\modules\message\contract\MessageRecipientDirectoryInterface;
use app\modules\message\domain\MessageScene;
use app\modules\message_configuration\contract\MessageConfigurationRepositoryInterface;
use app\modules\message_configuration\domain\MessageTypeCatalog;
use app\modules\message_configuration\domain\MessageGroups;
use app\modules\system_settings\contract\NotificationRobotSettingsRepositoryInterface;
use app\shared\contract\TransactionManagerInterface;
use InvalidArgumentException;

/** 业务调用入口；必须随业务事务记录，网络发送由维护进程和队列执行。 */
final readonly class PublishMessageService implements \app\modules\message\contract\MessagePublisherInterface
{
    public function __construct(
        private MessageRepositoryInterface $messages,
        private MessageRecipientDirectoryInterface $directory,
        private MessageConfigurationRepositoryInterface $configurations,
        private NotificationRobotSettingsRepositoryInterface $robots,
        private TransactionManagerInterface $transactions,
        private \app\modules\channel\contract\ChannelDisplayNameInterface $channelNames,
    ) {
    }

    /** @inheritDoc */
    public function publish(string $eventKey, string $scene, string $businessType, int $businessId, int $memberId, array $variables, array $audiences = ['customer', 'admin'], bool $ensureMemberInbox = false): int
    {
        if ($audiences === [] || array_diff($audiences, ['customer', 'admin']) !== []) {
            throw new InvalidArgumentException('消息接收组无效');
        }
        if ($ensureMemberInbox && ($scene !== 'ticket_replied' || !in_array('customer', $audiences, true) || $memberId < 1)) {
            throw new InvalidArgumentException('必要站内通知用途无效');
        }
        if ($eventKey === '' || strlen($eventKey) > 256 || $businessId < 1 || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $businessType)) {
            throw new InvalidArgumentException('消息业务事件标识无效');
        }
        if (MessageScene::metadata($scene)['purpose'] === 'verification') {
            throw new InvalidArgumentException('验证码必须经过独立验证服务，不允许进入普通通知队列和日志');
        }
        return $this->transactions->run(function () use ($eventKey, $scene, $businessType, $businessId, $memberId, $variables, $audiences, $ensureMemberInbox): int {
            $targets = [];
            $seen = [];
            $known = false;
            $robotConfig = $this->robots->get();
            $channelNames = [];
            foreach (array_unique($audiences) as $audience) {
                $type = MessageTypeCatalog::all($audience)[$scene] ?? null;
                if ($type === null) {
                    continue;
                }
                $known = true;
                $stored = $this->configurations->get($audience, $scene);
                foreach (MessageGroups::fromStored($audience, $type, $stored['configurations']) as $group) {
                    if (!$group['enabled']) continue;
                    $recipients = $group['kind'] === 'robot' ? [] : $this->directory->recipients($audience, $memberId, $group['recipients']);
                    foreach (MessageGroups::channels($type, $group['kind']) as $channel) {
                        $config = $group['configurations'][$channel] ?? [];
                        if (!($config['enabled'] ?? false)) {
                            continue;
                        }
                        $robots = in_array($channel, ['dingtalk', 'wecom', 'feishu'], true);
                        $channelId = (int) ($config['channel_id'] ?? 0);
                        $nameKey = $channel . ':' . $channelId;
                        if (!isset($channelNames[$nameKey])) {
                            $channelNames[$nameKey] = in_array($channel, ['email', 'sms'], true) && $channelId > 0
                                ? $this->channelNames->name($channel === 'email' ? 'mail' : 'sms', $channelId)
                                : '';
                        }
                        $receivers = $robots ? [['id' => 0, 'name' => '', 'email' => '', 'mobile' => '']] : $recipients;
                        foreach ($receivers as $recipient) {
                            $deduplication = ($robots ? 'robot' : $audience) . ':' . $recipient['id'] . ':' . $channel;
                            if (isset($seen[$deduplication])) continue;
                            $seen[$deduplication] = true;
                            $values = array_intersect_key($variables, array_flip(array_column($type['variables'], 'key')));
                            $values['customer_name'] = $audience === 'customer' ? $recipient['name'] : ($variables['customer_name'] ?? '');
                            $payload = [
                                'title' => MessageScene::render($config['title'] ?? $type['name'], $values),
                                'body' => MessageScene::render($config['body'] ?? '', $values),
                                'address' => $channel === 'sms' ? $recipient['mobile'] : $recipient['email'],
                                'template_id' => $config['template_id'] ?? '',
                                'parameters' => [], 'channel_id' => $config['channel_id'] ?? 0,
                                'channel_revision' => $config['channel_revision'] ?? 0,
                                'configuration_revision' => $stored['revision'],
                            ];
                            foreach (json_decode($config['variables'] ?? '{}', true, 8, JSON_THROW_ON_ERROR) as $key => $template) {
                                $payload['parameters'][$key] = MessageScene::render($template, $values);
                            }
                            if ($robots) {
                                $payload['robot'] = $robotConfig['robots'][$channel] ?? [];
                                $payload['robot_revision'] = $robotConfig['revision'];
                            }
                            $targets[] = ['audience' => $robots ? 'robot' : $audience, 'account_id' => $recipient['id'],
                                'channel_name' => $channelNames[$nameKey],
                                'group_key' => $group['id'], 'group_name' => $group['name'],
                                'target_key' => $group['id'] . ':' . ($robots ? $channel : $recipient['id']), 'channel' => $channel, 'payload' => $payload];
                        }
                    }
                }
            }
            if (!$known) {
                throw new InvalidArgumentException('消息场景不存在');
            }
            if ($ensureMemberInbox && !isset($seen['customer:' . $memberId . ':message'])) {
                $targets[] = ['audience' => 'customer', 'account_id' => $memberId, 'group_key' => 'ticket_required', 'group_name' => '工单站内提醒',
                    'target_key' => 'ticket_required:' . $memberId, 'channel' => 'message', 'payload' => ['title' => '工单有新回复',
                        'body' => '工单 ' . ($variables['ticket_no'] ?? '') . '「' . ($variables['ticket_title'] ?? '') . '」已收到工作人员回复，请查看沟通记录。',
                        'address' => '', 'template_id' => '', 'parameters' => [], 'channel_id' => 0, 'channel_revision' => 0, 'configuration_revision' => 0]];
            }
            return $this->messages->record(hash('sha256', $eventKey . ':' . $scene . ':' . $businessType . ':' . $businessId),
                $scene, $businessType, $businessId, $targets);
        });
    }
}
