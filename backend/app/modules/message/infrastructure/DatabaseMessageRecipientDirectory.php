<?php

declare(strict_types=1);

namespace app\modules\message\infrastructure;

use app\modules\message\contract\MessageRecipientDirectoryInterface;
use support\Db;

/** 跨模块只读接收人投影，不回显密码、手机号或邮箱到配置选项。 */
final class DatabaseMessageRecipientDirectory implements MessageRecipientDirectoryInterface
{
    public function recipients(string $audience, int $memberId, array $policy): array
    {
        $query = Db::table($audience === 'customer' ? 'members' : 'admin_users')->where('status', 1);
        if ($audience === 'customer') {
            $query->where('id', $memberId);
        } else {
            match ($policy['mode'] ?? 'founder') {
                'departments' => $query->whereIn('department_id', Db::table('admin_departments')->select('id')->where('status', 1)->whereIn('id', $policy['ids'])),
                'roles' => $query->whereIn('id', Db::table('admin_user_roles')->select('admin_user_id')->whereIn('role_id', Db::table('admin_roles')->select('id')->where('status', 1)->whereIn('id', $policy['ids']))),
                'members' => $query->whereIn('id', $policy['ids']),
                default => $query->where('is_founder', 1),
            };
        }
        return $query->orderBy('id')->get(['id', 'nickname', 'email', 'mobile'])->map(static fn (object $row): array =>
            ['id' => (int) $row->id, 'name' => $row->nickname, 'email' => $row->email ?? '', 'mobile' => $row->mobile ?? ''])->all();
    }

    public function active(string $audience, int $accountId): bool
    {
        return $audience === 'robot' || Db::table($audience === 'customer' ? 'members' : 'admin_users')->where('id', $accountId)->where('status', 1)->exists();
    }

    public function options(): array
    {
        $options = [];
        foreach (['departments' => 'admin_departments', 'roles' => 'admin_roles', 'members' => 'admin_users'] as $key => $table) {
            $field = $key === 'members' ? 'nickname' : 'name';
            $query = Db::table($table)->where('status', 1)->orderBy('id');
            $options[$key] = $query->get(['id', $field])->map(static fn (object $row): array =>
                ['id' => (int) $row->id, 'name' => $row->{$field}])->all();
        }
        return $options;
    }
}
