<?php

declare(strict_types=1);

namespace app\modules\member\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\member\contract\MemberCatalogRepositoryInterface;
use app\shared\http\ApiErrorCode;

/** 编排会员分组和等级管理。 */
final readonly class MemberCatalogManagementService
{
    public function __construct(private MemberCatalogRepositoryInterface $catalog, private AdminAccessControlService $access)
    {
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function groups(
        AdminAccount $actor,
        string $keyword,
        ?int $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array
    {
        $this->access->requirePermission($actor, 'admin.member-groups.view');
        return $this->catalog->groups($keyword, $status, $page, $pageSize, $filters);
    }

    /**
     * @param array<string, mixed> $input @return array<string, mixed>
     */
    public function saveGroup(AdminAccount $actor, ?int $id, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.member-groups.' . ($id === null ? 'create' : 'update'));
        if ($id !== null && $this->catalog->group($id) === null) throw $this->notFound('会员分组');
        $code = strtolower(trim((string) $input['code']));
        if ($this->catalog->groupCodeExists($code, $id)) throw $this->conflict('分组编码已存在');
        $savedId = $this->catalog->saveGroup($id, [
            'name' => trim((string) $input['name']), 'code' => $code,
            'sort_order' => (int) $input['sort_order'], 'description' => trim((string) ($input['description'] ?? '')),
            'status' => (int) $input['status'],
        ]);
        return $this->catalog->group($savedId) ?? throw $this->notFound('会员分组');
    }

    public function deleteGroup(AdminAccount $actor, int $id): void
    {
        $this->access->requirePermission($actor, 'admin.member-groups.delete');
        if ($this->catalog->group($id) === null) throw $this->notFound('会员分组');
        if ($this->catalog->groupInUse($id)) throw $this->conflict('该分组仍有关联会员，不能删除');
        $this->catalog->deleteGroup($id);
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     * @param array<string, string> $filters 独立查询条件及白名单排序参数，条件使用 AND 组合
     */
    public function levels(
        AdminAccount $actor,
        string $keyword,
        ?int $status,
        int $page,
        int $pageSize,
        array $filters = [],
    ): array
    {
        $this->access->requirePermission($actor, 'admin.member-levels.view');
        return $this->catalog->levels($keyword, $status, $page, $pageSize, $filters);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function saveLevel(AdminAccount $actor, ?int $id, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.member-levels.' . ($id === null ? 'create' : 'update'));
        if ($id !== null && $this->catalog->level($id) === null) throw $this->notFound('会员等级');
        $code = strtolower(trim((string) $input['code']));
        if ($this->catalog->levelCodeExists($code, $id)) throw $this->conflict('等级编码已存在');
        $savedId = $this->catalog->saveLevel($id, [
            'name' => trim((string) $input['name']), 'code' => $code,
            'level_order' => (int) $input['level_order'], 'growth_threshold' => (int) $input['growth_threshold'],
            'description' => trim((string) ($input['description'] ?? '')), 'status' => (int) $input['status'],
        ]);
        return $this->catalog->level($savedId) ?? throw $this->notFound('会员等级');
    }

    public function deleteLevel(AdminAccount $actor, int $id): void
    {
        $this->access->requirePermission($actor, 'admin.member-levels.delete');
        if ($this->catalog->level($id) === null) throw $this->notFound('会员等级');
        if ($this->catalog->levelInUse($id)) throw $this->conflict('该等级仍有关联会员，不能删除');
        $this->catalog->deleteLevel($id);
    }

    /** @return array{groups: list<array{id: int, name: string}>, levels: list<array{id: int, name: string}>} */
    public function options(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.members.view');
        return $this->catalog->enabledOptions();
    }

    private function notFound(string $name): BusinessException { return new BusinessException($name . '不存在', ApiErrorCode::NOT_FOUND, 404); }
    private function conflict(string $message): BusinessException { return new BusinessException($message, ApiErrorCode::CONFLICT, 409); }
}
