<?php

declare(strict_types=1);

namespace tests\unit\modules\member\validation;

use app\modules\member\validation\MemberValidator;
use PHPUnit\Framework\TestCase;
use support\validation\ValidationException;

/** 验证会员管理输入白名单和密码规则。 */
final class MemberValidatorTest extends TestCase
{
    public function testCreateRequiresPassword(): void
    {
        $this->expectException(ValidationException::class);
        MemberValidator::member($this->validInput(password: null), true);
    }

    public function testOversizedPasswordIsRejectedByAdminInput(): void
    {
        $this->expectException(ValidationException::class);
        MemberValidator::member($this->validInput(str_repeat('a', 73)), true);
    }

    public function testUpdateAcceptsMissingPasswordAndDropsUnknownFields(): void
    {
        $validated = MemberValidator::member($this->validInput(password: null) + ['balance' => 999999], false);

        self::assertArrayNotHasKey('password', $validated);
        self::assertArrayNotHasKey('balance', $validated);
    }

    /** @return array<string, mixed> */
    private function validInput(?string $password): array
    {
        $input = [
            'nickname' => '测试会员',
            'mobile' => '13800138001',
            'email' => null,
            'description' => '',
            'status' => 1,
        ];
        if ($password !== null) {
            $input['password'] = $password;
        }

        return $input;
    }
}
