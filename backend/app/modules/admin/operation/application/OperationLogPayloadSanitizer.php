<?php

declare(strict_types=1);

namespace app\modules\admin\operation\application;

/** 只提取审计所需的业务标识和状态，不保存完整 HTTP 报文。 */
final class OperationLogPayloadSanitizer
{
    private const array FIELDS = [
        'administrators' => ['username', 'nickname', 'department_id', 'status', 'role_ids'],
        'roles' => ['name', 'code', 'data_scope', 'status', 'department_ids', 'permissions'],
        'departments' => ['name', 'parent_id', 'status'],
        'members' => ['username', 'nickname', 'status'],
        'member-groups' => ['name', 'code', 'sort_order', 'description', 'status'],
        'member-levels' => ['name', 'code', 'level_order', 'growth_threshold', 'description', 'status'],
        'real-name-authentications' => ['decision', 'reason'],
        'service-instances' => ['action', 'image_id', 'acknowledged', 'reason', 'evidence_reference', 'months', 'expected_amount_minor'],
        'cloud-products' => ['name', 'code', 'revision', 'status'],
        'business-plugins' => ['action', 'revision'],
        'official-platform' => ['mode'],
        'application-center' => ['action', 'application_id', 'version_id', 'request_id'],
        'system-updates' => ['request_id'],
        'compute-resources' => ['name', 'code', 'status'],
        'cloud-delivery-rules' => ['name', 'connection_id', 'line_ids', 'priority', 'revision', 'status'],
        'settings' => [
            'site_title', 'site_keywords', 'site_description', 'site_url', 'copyright',
            'icp_record', 'public_security_record', 'business_license',
            'session_expire', 'login_attempts', 'login_window', 'revision',
        ],
        'message-configurations' => ['revision'],
        'themes' => ['revision', 'release_id', 'mode', 'responsive', 'desktop', 'mobile'],
        'website' => ['revision', 'theme_revision', 'digest'],
        'website-seo' => ['revision'],
        'channels' => ['name', 'plugin_id', 'status', 'sort_order', 'revision'],
    ];

    /**
     * 从已知写接口提取有限的操作参数；未知接口只保留路径目标 ID。
     * @param array<string, mixed> $input 请求体参数
     */
    public function params(string $resource, ?string $targetId, array $input): string
    {
        $result = [];
        if ($targetId !== null && ctype_digit($targetId)) {
            $result['target_id'] = (int) $targetId;
        }
        if ($resource === 'website' && $targetId !== null && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $targetId)) {
            $result['theme_id'] = $targetId;
        }
        if ($resource === 'files') {
            $result['file_ids'] = array_values(array_filter(array_slice(is_array($input['ids'] ?? null) ? $input['ids'] : [], 0, 100), static fn(mixed $id): bool => is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id) === 1));
            return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        if ($resource === 'website-seo') {
            if (is_string($targetId) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $targetId)) $result['page_id'] = $targetId;
            if (is_string($input['scope'] ?? null) && preg_match('/^(core|theme:[a-z][a-z0-9_-]{0,63})$/D', $input['scope'])) $result['scope'] = $input['scope'];
        }
        if ($resource === 'settings' && in_array($targetId, ['base', 'security', 'notifications'], true)) {
            $result['category'] = match ($targetId) { 'base' => '基础设置', 'security' => '安全设置', 'notifications' => '消息通知' };
        }

        if (in_array($resource, ['message-configurations', 'message-scenes'], true) && $targetId === 'batch') {
            $codes = [];
            foreach (array_slice(is_array($input['items'] ?? null) ? $input['items'] : [], 0, 30) as $item) {
                $code = is_array($item) ? ($item['code'] ?? null) : null;
                if (is_string($code) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) === 1) {
                    $codes[] = $code;
                }
            }
            $result['message_types'] = $codes;
            if ($resource === 'message-scenes' && is_string($input['kind'] ?? null) && in_array($input['kind'], ['customer', 'admin', 'robot'], true)) {
                $result['group_kind'] = $input['kind'];
            }
            if (in_array($input['channel'] ?? null, ['email', 'sms', 'message', 'wechat', 'dingtalk', 'wecom', 'feishu'], true)) {
                $result['channel'] = $input['channel'];
            }
            if (is_bool($input['enabled'] ?? null)) {
                $result['enabled'] = $input['enabled'];
            }
            if (is_int($input['channel_id'] ?? null)) {
                $result['channel_id'] = $input['channel_id'];
            }
            return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        if ($resource === 'message-scenes') {
            $result['revisions'] = array_intersect_key(is_array($input['revisions'] ?? null) ? $input['revisions'] : [], ['customer' => true, 'admin' => true]);
            $result['revisions'] = array_filter($result['revisions'], static fn (mixed $value): bool => is_int($value) && $value >= 0);
        }

        foreach (self::FIELDS[$resource] ?? [] as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = $this->safeValue($field, $input[$field]);
            if ($value !== null) {
                $result[$field] = $value;
            }
        }

        if (in_array($resource, ['message-configurations', 'message-scenes'], true) && $targetId !== null && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $targetId) === 1) {
            $result['message_type'] = $targetId;
        }

        // 固定插件配置的目标由路由确定，不使用客户端伪造的平台标识。
        if ($resource === 'channels' && $targetId !== null && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $targetId) === 1) {
            $result['plugin_id'] = $targetId;
            unset($result['name']);
        }

        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json) ? $json : '{}';
    }

    /** @return string|int|list<int|string>|null */
    private function safeValue(string $field, mixed $value): string|int|array|null
    {
        if (in_array($field, ['department_id', 'parent_id', 'status', 'sort_order', 'level_order', 'growth_threshold', 'session_expire', 'login_attempts', 'login_window', 'connection_id', 'priority', 'revision'], true)) {
            return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : null;
        }

        if (in_array($field, ['role_ids', 'department_ids', 'line_ids'], true)) {
            if (!is_array($value)) {
                return null;
            }
            if (count($value) > 256) {
                return count($value) . ' 项，超过审计明细上限';
            }
            $ids = [];
            foreach ($value as $item) {
                if (filter_var($item, FILTER_VALIDATE_INT) !== false && (int) $item > 0) {
                    $ids[] = (int) $item;
                }
            }
            return $ids;
        }

        if ($field === 'permissions') {
            if (!is_array($value)) {
                return null;
            }
            if (count($value) > 256) {
                return count($value) . ' 项，超过审计明细上限';
            }
            $codes = [];
            foreach ($value as $item) {
                if (is_string($item) && preg_match('/^[a-z0-9._-]{1,100}$/', $item) === 1) {
                    $codes[] = $item;
                }
            }
            return $codes;
        }

        if (!is_string($value)) {
            return null;
        }
        if ($field === 'data_scope' && !in_array($value, ['all', 'department_and_children', 'department', 'self', 'custom'], true)) {
            return null;
        }
        if (in_array($field, ['username', 'code'], true) && preg_match('/^[A-Za-z][A-Za-z0-9_]{1,63}$/', $value) !== 1) {
            return null;
        }

        return mb_strimwidth(trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $value) ?? ''), 0, 128, '…');
    }
}
