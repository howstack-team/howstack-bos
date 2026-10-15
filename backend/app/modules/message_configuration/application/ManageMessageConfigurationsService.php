<?php

declare(strict_types=1);

namespace app\modules\message_configuration\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\channel\contract\ChannelRepositoryInterface;
use app\modules\message_configuration\contract\MessageConfigurationRepositoryInterface;
use app\modules\message_configuration\domain\MessageTypeCatalog;
use app\modules\message_configuration\validation\MessageConfigurationValidator;
use app\modules\message_configuration\validation\BatchMessageConfigurationValidator;
use app\shared\contract\TransactionManagerInterface;
use app\modules\system_settings\contract\NotificationRobotSettingsRepositoryInterface;
use app\modules\system_settings\domain\NotificationRobotDefinition;
use app\shared\http\ApiErrorCode;

/** 配置消息策略、接收对象与模板；发送由统一消息模块执行。 */
final readonly class ManageMessageConfigurationsService
{
    public function __construct(
        private AdminAccessControlService $access,
        private MessageConfigurationRepositoryInterface $repository,
        private ChannelRepositoryInterface $channels,
        private MessageConfigurationValidator $validator,
        private NotificationRobotSettingsRepositoryInterface $robots,
        private TransactionManagerInterface $transactions,
        private BatchMessageConfigurationValidator $batchValidator,
        private \app\modules\message\contract\MessageRecipientDirectoryInterface $directory,
    ) {
    }

    /** @return array<string, mixed> 消息目录分页与渠道公开标签，不返回秘密或模板正文 */
    public function list(AdminAccount $actor, string $audience, array $query): array
    {
        $this->access->requirePermission($actor, 'admin.message-configurations.view');
        $types = MessageTypeCatalog::all($audience);
        foreach (['name', 'description'] as $key) {
            if (isset($query[$key]) && (!is_string($query[$key]) || mb_strlen($query[$key]) > 128)) {
                throw new BusinessException('查询字段无效');
            }
        }
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        $size = filter_var($query['page_size'] ?? 10, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 100000 || !in_array($size, [10, 20, 50, 100], true)) {
            throw new BusinessException('分页参数无效');
        }
        $stored = $this->repository->all($audience);
        $rows = [];
        foreach ($types as $code => $type) {
            if (mb_stripos($type['name'], trim($query['name'] ?? '')) === false
                || mb_stripos($type['description'], trim($query['description'] ?? '')) === false) {
                continue;
            }
            $state = [];
            foreach ($type['channels'] as $channel) {
                $state[$channel] = (bool) ($stored[$code]['configurations'][$channel]['enabled'] ?? false);
            }
            $rows[] = ['code' => $code, 'name' => $type['name'], 'description' => $type['description'],
                'channels' => (object) $state, 'revision' => $stored[$code]['revision'] ?? 0];
        }
        return ['data' => array_slice($rows, ($page - 1) * $size, $size), 'total' => count($rows),
            'channel_labels' => (object) MessageTypeCatalog::CHANNELS[$audience]];
    }

    /** @return array<string, mixed> 编辑抽屉的渠道、模板、变量及共享机器人配置状态 */
    public function get(AdminAccount $actor, string $audience, string $code): array
    {
        $this->access->requirePermission($actor, 'admin.message-configurations.view');
        $type = MessageTypeCatalog::get($audience, $code);
        $stored = $this->repository->get($audience, $code);
        $configs = [];
        $robotStatus = [];
        $robots = $this->robots->get()['robots'];
        foreach ($type['channels'] as $channel) {
            // 输出严格使用当前模板字段，历史机器人秘密也不能回显。
            $defaults = MessageTypeCatalog::defaults($type, $channel);
            $config = array_replace($defaults, array_intersect_key($stored['configurations'][$channel] ?? [], $defaults));
            if (isset(NotificationRobotDefinition::LABELS[$channel])) {
                $robotStatus[$channel] = ($robots[$channel]['webhook_url'] ?? '') !== '';
            }
            $configs[$channel] = (object) $config;
        }
        return $type + ['audience' => $audience, 'revision' => $stored['revision'],
            'configurations' => (object) $configs, 'robot_status' => (object) $robotStatus,
            'recipients' => $stored['configurations']['_recipients'] ?? ['mode' => 'founder', 'ids' => []],
            'recipient_options' => $audience === 'admin' ? (object) $this->directory->options() : (object) [],
            'channel_options' => (object) $this->options($type, $stored['configurations'])];
    }

    /** @return array<string, mixed> 保存后只返回脱敏资料 */
    public function save(AdminAccount $actor, string $audience, string $code, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.message-configurations.view');
        $this->access->requirePermission($actor, 'admin.message-configurations.update');
        $this->requireLegacy($audience, $code);
        $type = MessageTypeCatalog::get($audience, $code);
        if ($audience !== 'admin' && array_key_exists('recipients', $input)) {
            throw new BusinessException('客户消息接收人由关联业务确定，不允许指定管理员范围');
        }
        $policy = $input['recipients'] ?? $this->repository->get($audience, $code)['configurations']['_recipients'] ?? ['mode' => 'founder', 'ids' => []];
        $policy = (new \app\modules\message_configuration\validation\MessageRecipientPolicyValidator())->values($policy);
        if ($audience === 'admin') {
            $mode = $policy['mode'];
            $available = $mode === 'founder' ? [] : array_column($this->directory->options()[$mode], 'id');
            if (array_diff($policy['ids'], $available) !== []) {
                throw new BusinessException('接收对象已停用或不存在，请重新选择');
            }
        }
        unset($input['recipients']);
        $values = $this->validator->values($type, $input);
        $configurations = $this->validateChannels($values['configurations']);
        $configurations['_recipients'] = $policy;
        $this->repository->save($audience, $code, $values['revision'], $configurations);
        return $this->get($actor, $audience, $code);
    }

    /** @return array<string, mixed> 全部成功才提交；模板和其他渠道保持原配置 */
    public function batch(AdminAccount $actor, string $audience, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.message-configurations.view');
        $this->access->requirePermission($actor, 'admin.message-configurations.update');
        $values = $this->batchValidator->values($audience, $input);
        return $this->transactions->run(function () use ($audience, $values): array {
            if (isset($values['channel_id'])) {
                $connection = $this->channels->find($values['channel'] === 'email' ? 'mail' : 'sms', $values['channel_id']);
                if ($connection['status'] !== 1 || !$connection['platform_available'] || !$connection['configuration_current']) {
                    throw new BusinessException('请选择已配置并启用的发送通道');
                }
            }
            $result = [];
            foreach ($values['items'] as $item) {
                $this->requireLegacy($audience, $item['code']);
                $type = MessageTypeCatalog::get($audience, $item['code']);
                $stored = $this->repository->get($audience, $item['code']);
                if ($stored['revision'] !== $item['revision']) {
                    throw new BusinessException($type['name'] . '配置已变化，请重新选择后操作', ApiErrorCode::CONFLICT, 409);
                }
                $configurations = [];
                foreach ($type['channels'] as $channel) {
                    $defaults = MessageTypeCatalog::defaults($type, $channel);
                    $configurations[$channel] = array_replace($defaults, array_intersect_key($stored['configurations'][$channel] ?? [], $defaults));
                }
                foreach (['channel_id', 'enabled'] as $field) {
                    if (array_key_exists($field, $values)) {
                        $configurations[$values['channel']][$field] = $values[$field];
                    }
                }
                try {
                    $validated = $this->validator->values($type, ['revision' => $item['revision'], 'configurations' => $configurations]);
                    $prepared = $this->validateChannels($validated['configurations'], $values['channel']);
                    $final = array_replace($prepared, $stored['configurations']);
                    $final[$values['channel']] = $prepared[$values['channel']];
                    $this->repository->save($audience, $item['code'], $item['revision'], $final);
                } catch (BusinessException $error) {
                    throw new BusinessException($type['name'] . '：' . $error->getMessage(), $error->errorCode(), $error->httpStatus(), previous: $error);
                }
                $result[] = ['code' => $item['code'], 'revision' => $item['revision'] + 1];
            }
            return ['updated' => count($result), 'channel' => $values['channel'], 'items' => $result];
        });
    }

    /** 新的组策略复用已有模板、通道、修订及权限校验。 */
    public function prepareGroup(AdminAccount $actor, array $type, array $configurations, bool $groupEnabled = true): array
    {
        $this->access->requirePermission($actor, 'admin.message-configurations.update');
        $type['group_enabled'] = $groupEnabled;
        return $this->validateChannels($this->validator->values($type, ['revision' => 0, 'configurations' => $configurations])['configurations'], groupEnabled: $groupEnabled);
    }

    private function requireLegacy(string $audience, string $code): void
    {
        if (array_key_exists('_groups', $this->repository->get($audience, $code)['configurations'])) {
            throw new BusinessException('此场景已使用接收组配置，请使用新版场景编辑页面', ApiErrorCode::CONFLICT, 409);
        }
    }

    /** @param array<string, mixed> $configurations 仅重新核验实际修改的渠道 */
    private function validateChannels(array $configurations, ?string $onlyChannel = null, bool $groupEnabled = true): array
    {
        $robots = $this->robots->get()['robots'];
        foreach ($configurations as $channel => &$config) {
            if ($onlyChannel !== null && $onlyChannel !== $channel) {
                continue;
            }
            $enabled = $groupEnabled && $config['enabled'];
            if ($enabled && isset(NotificationRobotDefinition::LABELS[$channel])
                && ($robots[$channel]['webhook_url'] ?? '') === '') {
                throw new BusinessException('请先在系统设置的消息通知中配置' . NotificationRobotDefinition::LABELS[$channel] . '机器人',
                    ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => ['configurations.' . $channel . '.enabled' => ['请先完成统一机器人配置']]]);
            }
            if (!in_array($channel, ['email', 'sms'], true)) {
                continue;
            }
            $config['channel_revision'] = 0;
            if ($config['channel_id'] > 0) {
                $row = $this->channels->find($channel === 'email' ? 'mail' : 'sms', $config['channel_id']);
                $config['channel_revision'] = $row['revision'];
                if ($enabled && ($row['status'] !== 1 || !$row['platform_available'] || !$row['configuration_current'])) {
                    throw new BusinessException('启用消息前请先启用并保存对应发送通道');
                }
                if ($channel === 'sms' && $enabled && $row['plugin_id'] !== 'smsbao' && $config['template_id'] === '') {
                    throw new BusinessException('当前短信通道需要填写厂商模板 ID');
                }
            } elseif ($enabled) {
                throw new BusinessException('启用消息前请选择发送通道');
            }
        }
        unset($config);
        return $configurations;
    }

    /** @return array<string, list<array<string, mixed>>> 消息配置权限仅授予读取发送通道名称，不授予通道修改或测试权限 */
    private function options(array $type, array $configurations): array
    {
        $result = [];
        foreach (array_intersect(['email', 'sms'], $type['channels']) as $channel) {
            $category = $channel === 'email' ? 'mail' : 'sms';
            $rows = $this->channels->list($category, ['page' => 1, 'page_size' => 100, 'status' => 1])['data'];
            $result[$channel] = [];
            foreach ($rows as $row) {
                if ($row['platform_available'] && $row['configuration_current']) {
                    $result[$channel][] = ['id' => $row['id'], 'name' => $row['platform_name'], 'available' => true];
                }
            }
            $selected = (int) ($configurations[$channel]['channel_id'] ?? 0);
            if ($selected > 0 && !in_array($selected, array_column($result[$channel], 'id'), true)) {
                $result[$channel][] = ['id' => $selected, 'name' => '原发送通道（当前不可用）', 'available' => false];
            }
        }
        return $result;
    }
}
