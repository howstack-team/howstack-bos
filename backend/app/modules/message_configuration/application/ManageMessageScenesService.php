<?php

declare(strict_types=1);

namespace app\modules\message_configuration\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\message\contract\MessageRecipientDirectoryInterface;
use app\modules\message_configuration\contract\MessageConfigurationRepositoryInterface;
use app\modules\message_configuration\domain\MessageGroups;
use app\modules\message_configuration\domain\MessageTypeCatalog;
use app\modules\message_configuration\validation\MessageRecipientPolicyValidator;
use app\shared\contract\TransactionManagerInterface;
use app\shared\http\ApiErrorCode;

/** 以固定场景管理多个接收组；受众修订一起提交，保存不执行网络发送。 */
final readonly class ManageMessageScenesService
{
    public function __construct(
        private AdminAccessControlService $access,
        private MessageConfigurationRepositoryInterface $repository,
        private ManageMessageConfigurationsService $legacy,
        private MessageRecipientDirectoryInterface $directory,
        private TransactionManagerInterface $transactions,
        private MessageRecipientPolicyValidator $policies,
    ) {
    }

    public function list(AdminAccount $actor, array $query): array
    {
        $this->access->requirePermission($actor, 'admin.message-configurations.view');
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        $size = filter_var($query['page_size'] ?? 10, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 100000 || !in_array($size, [10, 20, 50, 100], true)) {
            throw new BusinessException('分页参数无效');
        }
        $stored = ['customer' => $this->repository->all('customer'), 'admin' => $this->repository->all('admin')];
        $rows = [];
        foreach (MessageTypeCatalog::scenes() as $code => $scene) {
            $groups = [];
            $revisions = [];
            foreach ($scene['audiences'] as $audience => $type) {
                $row = $stored[$audience][$code] ?? ['revision' => 0, 'configurations' => []];
                $revisions[$audience] = $row['revision'];
                foreach (MessageGroups::fromStored($audience, $type, $row['configurations']) as $group) {
                    $channels = MessageGroups::channels($type, $group['kind']);
                    $active = array_values(array_filter($channels, static fn (string $channel): bool =>
                        $group['enabled'] && ($group['configurations'][$channel]['enabled'] ?? false)));
                    $groups[] = ['id' => $group['id'], 'name' => $group['name'], 'kind' => $group['kind'],
                        'enabled' => $group['enabled'], 'channels' => $active, 'available_channels' => $channels];
                }
            }
            $metadata = $scene['audiences'];
            unset($scene['audiences']);
            $rows[] = $scene + ['metadata' => (object) $metadata, 'revisions' => (object) $revisions, 'groups' => $groups,
                'enabled' => array_any($groups, static fn (array $group): bool => $group['channels'] !== [])];
        }
        return ['data' => array_slice($rows, ($page - 1) * $size, $size), 'total' => count($rows)];
    }

    public function get(AdminAccount $actor, string $code): array
    {
        $this->access->requirePermission($actor, 'admin.message-configurations.view');
        $scene = $this->scene($code);
        $groups = [];
        $revisions = [];
        $metadata = [];
        $options = [];
        $robots = [];
        $templates = [];
        foreach ($scene['audiences'] as $audience => $type) {
            $stored = $this->repository->get($audience, $code);
            $revisions[$audience] = $stored['revision'];
            $public = $this->legacy->get($actor, $audience, $code);
            $metadata[$audience] = $type;
            foreach ($audience === 'customer' ? ['customer'] : ['admin', 'robot'] as $kind) {
                $templates[$kind] = MessageGroups::create($type, $kind, $kind, MessageGroups::KINDS[$kind]);
            }
            foreach ((array) $public['channel_options'] as $channel => $available) {
                $indexed = array_column($options[$channel] ?? [], null, 'id');
                foreach ($available as $option) {
                    if (!isset($indexed[$option['id']]) || $option['available']) {
                        $indexed[$option['id']] = $option;
                    }
                }
                $options[$channel] = array_values($indexed);
            }
            $robots = array_replace($robots, (array) $public['robot_status']);
            foreach (MessageGroups::fromStored($audience, $type, $stored['configurations']) as $group) {
                $safe = [];
                foreach (MessageGroups::channels($type, $group['kind']) as $channel) {
                    $defaults = MessageTypeCatalog::defaults($type, $channel);
                    $safe[$channel] = array_replace($defaults, array_intersect_key($group['configurations'][$channel] ?? [], $defaults));
                    $selected = $safe[$channel]['channel_id'] ?? 0;
                    if ($selected > 0 && !in_array($selected, array_column($options[$channel] ?? [], 'id'), true)) {
                        $options[$channel][] = ['id' => $selected, 'name' => '原发送通道（当前不可用）', 'available' => false];
                    }
                }
                $group['configurations'] = (object) $safe;
                $groups[] = $group;
            }
        }
        unset($scene['audiences']);
        return $scene + ['revisions' => (object) $revisions, 'groups' => $groups, 'metadata' => (object) $metadata,
            'channel_options' => (object) $options, 'robot_status' => (object) $robots,
            'group_templates' => (object) $templates,
            'recipient_options' => isset($metadata['admin']) ? (object) $this->directory->options() : (object) []];
    }

    public function save(AdminAccount $actor, string $code, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.message-configurations.view');
        $this->access->requirePermission($actor, 'admin.message-configurations.update');
        $scene = $this->scene($code);
        $this->revisions($scene, $input['revisions'] ?? null);
        if (array_diff(array_keys($input), ['revisions', 'groups']) !== [] || !is_array($input['groups'] ?? null)
            || !array_is_list($input['groups']) || count($input['groups']) > 10) {
            throw new BusinessException('接收组配置无效，最多设置10组');
        }
        $prepared = array_fill_keys(array_keys($scene['audiences']), []);
        $ids = [];
        foreach ($input['groups'] as $index => $group) {
            if (!is_array($group) || array_diff(array_keys($group), ['id', 'name', 'kind', 'enabled', 'recipients', 'configurations']) !== []
                || !is_string($group['id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', $group['id'])
                || in_array($group['id'], $ids, true) || !is_string($group['name'] ?? null) || trim($group['name']) === ''
                || mb_strlen($group['name']) > 64 || preg_match('/[\x00-\x1f\x7f]/', $group['name'])
                || !is_string($group['kind'] ?? null) || !isset(MessageGroups::KINDS[$group['kind']]) || !is_bool($group['enabled'] ?? null)
                || !is_array($group['configurations'] ?? null)) {
                throw new BusinessException('接收组名称、标识或类型无效');
            }
            $ids[] = $group['id'];
            $audience = MessageGroups::audience($group['kind']);
            $type = $scene['audiences'][$audience] ?? throw new BusinessException('此场景不支持该接收对象');
            $type['channels'] = MessageGroups::channels($type, $group['kind']);
            $policy = $this->policies->values($group['recipients'] ?? []);
            if ($group['kind'] !== 'admin' && $policy !== ['mode' => 'founder', 'ids' => []]) {
                throw new BusinessException('客户由关联业务确定，机器人使用系统设置，不允许指定内部人员');
            }
            if ($group['enabled'] && $group['kind'] === 'admin' && $policy['mode'] !== 'founder'
                && array_diff($policy['ids'], array_column($this->directory->options()[$policy['mode']], 'id')) !== []) {
                throw new BusinessException('接收对象已停用或不存在，请重新选择');
            }
            try {
                $configurations = $this->legacy->prepareGroup($actor, $type, $group['configurations'], $group['enabled']);
            } catch (BusinessException $error) {
                $errors = [];
                foreach ($error->data()['errors'] ?? [] as $field => $messages) {
                    $errors['groups.' . $index . '.' . $field] = $messages;
                }
                throw new BusinessException($group['name'] . '：' . $error->getMessage(), $error->errorCode(), $error->httpStatus(),
                    ['errors' => $errors ?: ['groups.' . $index . '.name' => [$error->getMessage()]]], previous: $error);
            }
            $prepared[$audience][] = ['id' => $group['id'], 'name' => trim($group['name']), 'kind' => $group['kind'],
                'enabled' => $group['enabled'], 'recipients' => $policy, 'configurations' => $configurations];
        }
        $this->transactions->run(function () use ($code, $scene, $input, $prepared): void {
            // 稳定的受众顺序降低并发编辑的锁冲突；任一修订冲突整场景回滚。
            foreach ($scene['audiences'] as $audience => $type) {
                $mirror = [];
                foreach ($type['channels'] as $channel) {
                    $mirror[$channel] = MessageTypeCatalog::defaults($type, $channel);
                }
                foreach ($prepared[$audience] as $group) {
                    foreach ($group['configurations'] as $channel => $config) {
                        if (!$mirror[$channel]['enabled']) {
                            $mirror[$channel] = $config;
                            $mirror[$channel]['enabled'] = $group['enabled'] && $config['enabled'];
                        }
                    }
                }
                $mirror['_groups'] = $prepared[$audience];
                $this->repository->save($audience, $code, $input['revisions'][$audience], $mirror);
            }
        });
        return $this->get($actor, $code);
    }

    /** 同一批次全部成功；只改匹配组的渠道开关/引用，保留名称、范围和模板。 */
    public function batch(AdminAccount $actor, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.message-configurations.update');
        if (array_diff(array_keys($input), ['items', 'kind', 'channel', 'channel_id', 'enabled']) !== []
            || !is_array($input['items'] ?? null) || !array_is_list($input['items']) || count($input['items']) < 1 || count($input['items']) > 30
            || !is_string($input['kind'] ?? null) || !isset(MessageGroups::KINDS[$input['kind']])
            || !is_string($input['channel'] ?? null)
            || (!array_key_exists('channel_id', $input) && !array_key_exists('enabled', $input))
            || (isset($input['enabled']) && !is_bool($input['enabled']))
            || (array_key_exists('enabled', $input) && $input['enabled'] === null)
            || (array_key_exists('channel_id', $input) && (!in_array($input['channel'], ['email', 'sms'], true)
                || !is_int($input['channel_id']) || $input['channel_id'] < 1 || $input['channel_id'] > 2147483647))) {
            throw new BusinessException('批量配置参数无效');
        }
        $items = $input['items'];
        foreach ($items as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['code', 'revisions']) !== [] || !is_string($item['code'] ?? null)) {
                throw new BusinessException('批量场景参数无效');
            }
            $scene = $this->scene($item['code']);
            $this->revisions($scene, $item['revisions'] ?? null);
        }
        if (count(array_unique(array_column($items, 'code'))) !== count($items)) {
            throw new BusinessException('批量场景不可重复');
        }
        usort($items, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
        return $this->transactions->run(function () use ($actor, $input, $items): array {
            foreach ($items as $item) {
                $detail = $this->get($actor, $item['code']);
                if ((array) $detail['revisions'] != $item['revisions']) {
                    throw new BusinessException('消息配置已变化，请重新选择后操作', ApiErrorCode::CONFLICT, 409);
                }
                $changed = false;
                $groups = $detail['groups'];
                foreach ($groups as &$group) {
                    $group['configurations'] = (array) $group['configurations'];
                    if ($group['kind'] !== $input['kind'] || !isset($group['configurations'][$input['channel']])) {
                        continue;
                    }
                    foreach (['enabled', 'channel_id'] as $field) {
                        if (array_key_exists($field, $input)) {
                            $group['configurations'][$input['channel']][$field] = $input[$field];
                        }
                    }
                    $changed = true;
                }
                unset($group);
                if (!$changed) {
                    throw new BusinessException($detail['name'] . '没有匹配的接收组或渠道，请先单独配置');
                }
                $this->save($actor, $item['code'], ['revisions' => $item['revisions'], 'groups' => $groups]);
            }
            return ['updated' => count($items)];
        });
    }

    private function scene(string $code): array
    {
        return MessageTypeCatalog::scenes()[$code] ?? throw new BusinessException('消息场景不存在', ApiErrorCode::NOT_FOUND, 404);
    }

    private function revisions(array $scene, mixed $revisions): void
    {
        if (!is_array($revisions) || array_diff(array_keys($revisions), array_keys($scene['audiences'])) !== []
            || array_diff(array_keys($scene['audiences']), array_keys($revisions)) !== []
            || array_any($revisions, static fn (mixed $v): bool => !is_int($v) || $v < 0 || $v >= 2147483647)) {
            throw new BusinessException('场景修订参数无效');
        }
    }
}
