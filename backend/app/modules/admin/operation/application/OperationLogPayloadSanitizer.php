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
        'settings' => [
            'site_title', 'site_subtitle', 'site_keywords', 'site_description', 'site_url', 'copyright',
            'icp_record', 'public_security_record', 'business_license',
            'session_expire', 'login_attempts', 'login_window',
        ],
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
        if ($resource === 'settings' && in_array($targetId, ['base', 'security'], true)) {
            $result['category'] = $targetId === 'base' ? '基础设置' : '安全设置';
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

        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json) ? $json : '{}';
    }

    /** @return string|int|list<int|string>|null */
    private function safeValue(string $field, mixed $value): string|int|array|null
    {
        if (in_array($field, ['department_id', 'parent_id', 'status', 'sort_order', 'level_order', 'growth_threshold', 'session_expire', 'login_attempts', 'login_window'], true)) {
            return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : null;
        }

        if (in_array($field, ['role_ids', 'department_ids'], true)) {
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
