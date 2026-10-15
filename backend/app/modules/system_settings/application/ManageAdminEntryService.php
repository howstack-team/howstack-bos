<?php

declare(strict_types=1);

namespace app\modules\system_settings\application;

use app\exception\BusinessException;
use app\modules\admin\access\application\AdminAccessControlService;
use app\modules\admin\auth\domain\AdminAccount;
use app\modules\system_settings\contract\AdminEntryPathPolicyInterface;
use app\modules\system_settings\contract\AdminEntryRepositoryInterface;
use app\shared\http\ApiErrorCode;

/** 后台访问路径独立保存，不与数据库中的登录策略混合提交。 */
final readonly class ManageAdminEntryService
{
    public function __construct(
        private AdminEntryRepositoryInterface $repository,
        private AdminEntryPathPolicyInterface $paths,
        private AdminAccessControlService $access,
    ) {
    }

    /** @return array{admin_path: string} 当前实际生效的首级路径 */
    public function get(AdminAccount $actor): array
    {
        $this->access->requirePermission($actor, 'admin.page.settings.view');
        return ['admin_path' => $this->repository->adminPath()];
    }

    /** @param array<string, mixed> $input 当前路径和新路径；返回已生效路径 */
    public function save(AdminAccount $actor, array $input): array
    {
        $this->access->requirePermission($actor, 'admin.page.settings.view');
        $this->access->requirePermission($actor, 'admin.page.settings.update');
        $errors = [];
        foreach (['admin_path', 'current_admin_path'] as $field) {
            if (!is_string($input[$field] ?? null) || !preg_match('/^[a-z][a-z0-9-]{2,31}$/D', $input[$field])) {
                $errors[$field] = ['后台路径须以小写字母开头，包含小写字母、数字或连字符，长度为3至32位'];
            }
        }
        if (array_diff(array_keys($input), ['admin_path', 'current_admin_path']) !== []) {
            $errors['admin_path'] = ['不能提交后台路径之外的配置'];
        }
        if ($errors !== []) {
            throw new BusinessException('请检查后台访问路径', ApiErrorCode::VALIDATION_FAILED, 422, ['errors' => $errors]);
        }
        $this->paths->check($input['admin_path']);
        $this->repository->changeAdminPath($input['current_admin_path'], $input['admin_path']);
        return ['admin_path' => $input['admin_path']];
    }
}
