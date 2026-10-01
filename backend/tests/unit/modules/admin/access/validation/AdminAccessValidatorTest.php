<?php

declare(strict_types=1);

namespace tests\unit\modules\admin\access\validation;

use app\modules\admin\access\validation\AdminAccessValidator;
use PHPUnit\Framework\TestCase;
use support\validation\ValidationException;

/**
 * 验证角色可以先创建再授权，但必须明确提交权限数组。
 */
final class AdminAccessValidatorTest extends TestCase
{
    /**
     * 空权限数组允许创建尚未授权的角色。
     * @return void
     */
    public function testRoleAcceptsEmptyPermissions(): void
    {
        $validated = AdminAccessValidator::role($this->roleInput([]));

        self::assertSame([], $validated['permissions']);
    }

    /**
     * 缺少权限字段仍应拒绝，避免更新时无意清空授权。
     * @return void
     */
    public function testRoleRequiresPermissionField(): void
    {
        $input = $this->roleInput([]);
        unset($input['permissions']);

        $this->expectException(ValidationException::class);
        AdminAccessValidator::role($input);
    }

    /**
     * 权限字段必须为数组，不能接受字符串。
     * @return void
     */
    public function testRoleRejectsNonArrayPermissions(): void
    {
        $this->expectException(ValidationException::class);
        AdminAccessValidator::role($this->roleInput('admin.roles.view'));
    }

    /**
     * 构造其余字段均合法的角色输入。
     * @param mixed $permissions 待校验的权限值
     * @return array<string, mixed> 角色输入
     */
    private function roleInput(mixed $permissions): array
    {
        return [
            'name' => '测试角色',
            'code' => 'test_role',
            'description' => '',
            'data_scope' => 'self',
            'status' => 1,
            'permissions' => $permissions,
            'department_ids' => [],
        ];
    }
}
