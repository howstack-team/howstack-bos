<?php

declare(strict_types=1);

namespace tests\unit\modules\member\application;

use app\modules\member\application\RealNameApplicationService;
use app\modules\member\contract\MemberRepositoryInterface;
use app\modules\member\contract\RealNameAuthenticationRepositoryInterface;
use app\shared\infrastructure\SodiumSensitiveDataCipher;
use PHPUnit\Framework\TestCase;

/** 验证个人认证升级企业认证时保留历史关联并加密证件。 */
final class RealNameApplicationServiceTest extends TestCase
{
    public function testEnterpriseUpgradeLinksMatchingApprovedPersonalRecord(): void
    {
        $cipher = new SodiumSensitiveDataCipher(str_repeat('b', 64));
        $members = $this->createMock(MemberRepositoryInterface::class);
        $members->expects(self::once())->method('find')->with(9)->willReturn(['id' => 9]);
        $records = $this->createMock(RealNameAuthenticationRepositoryInterface::class);
        $records->method('hasPending')->willReturn(false);
        $records->method('latestApproved')->willReturnCallback(static fn (int $memberId, string $type): ?array => match ($type) {
            'enterprise' => null,
            'personal' => ['id' => 3, 'id_number_hash' => $cipher->fingerprint('510101199001011234')],
        });
        $records->expects(self::once())->method('create')->with(self::callback(static function (array $data): bool {
            return $data['upgrade_from_id'] === 3
                && $data['authentication_type'] === 'enterprise'
                && $data['id_number_masked'] === '510***********1234'
                && !str_contains($data['id_number_ciphertext'], '510101199001011234');
        }))->willReturn(12);
        $service = new RealNameApplicationService($records, $members, $cipher);

        $id = $service->submitEnterprise(9, [
            'company_name' => '示例科技有限公司', 'registration_code' => '91510100ABCDEFGH12',
            'legal_representative' => '张三', 'legal_representative_id_number' => '510101199001011234',
        ]);

        self::assertSame(12, $id);
    }
}
