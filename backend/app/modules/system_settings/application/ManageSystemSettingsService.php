<?php

declare(strict_types=1);

namespace app\modules\system_settings\application;

use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\system_settings\contract\SystemSettingsRepositoryInterface;
use app\modules\system_settings\validation\SystemSettingsValidator;

/** 后台设置用例：验证权限及固定字段后按分类整体保存。 */
final readonly class ManageSystemSettingsService
{
    /**
     * 初始化后台设置管理用例。
     * @param SystemSettingsRepositoryInterface $repository 设置仓储
     * @param GetSystemSettingsService          $reader 类型化读取门面
     * @param AdminAccessControlService         $access 后台权限服务
     * @param SystemSettingsValidator           $validator 固定输入规则
     */
    public function __construct(
        private SystemSettingsRepositoryInterface $repository,
        private GetSystemSettingsService $reader,
        private AdminAccessControlService $access,
        private SystemSettingsValidator $validator,
    ) {
    }

    /**
     * 返回两个已实现表单的分类。
     * @param AdminAccount $actor 当前管理员
     * @return list<array{id: int, code: string, name: string, sort_order: int}> 分类
     */
    public function categories(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.page.settings.view');

        return $this->repository->categories();
    }

    /**
     * 读取固定分类表单。
     * @param AdminAccount $actor 当前管理员
     * @param string       $category 分类编码
     * @return array<string, int|string> 表单值
     */
    public function get(AdminAccount $actor, string $category): array
    {
        $this->access->requirePermission($actor, 'admin.page.settings.view');
        $category = $this->validator->category($category);

        return $category === 'base' ? $this->reader->base() : $this->reader->security();
    }

    /**
     * 保存单个分类并返回持久化值。
     * @param AdminAccount         $actor 当前管理员
     * @param string               $category 分类编码
     * @param array<string, mixed> $input 请求表单
     * @return array<string, int|string> 已保存的表单值
     */
    public function save(AdminAccount $actor, string $category, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.page.settings.view');
        $this->access->requirePermission($actor, 'admin.page.settings.update');
        $category = $this->validator->category($category);
        $values = $this->validator->values($category, $input);
        $mapping = $category === 'base' ? GetSystemSettingsService::BASE_KEYS : GetSystemSettingsService::SECURITY_KEYS;
        $stored = [];
        foreach ($mapping as $field => $key) {
            $stored[$key] = (string) $values[$field];
        }
        $this->repository->save($category, $stored);

        return $values;
    }
}
