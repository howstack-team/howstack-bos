<?php

declare(strict_types=1);

namespace app\modules\member\application;

use app\exception\BusinessException;
use app\modules\member\contract\MemberRepositoryInterface;
use app\modules\member\contract\RealNameAuthenticationRepositoryInterface;
use app\shared\contract\SensitiveDataCipherInterface;
use app\shared\http\ApiErrorCode;

/** 处理会员个人和企业实名认证申请，并保留升级链。 */
final readonly class RealNameApplicationService
{
    public function __construct(
        private RealNameAuthenticationRepositoryInterface $records,
        private MemberRepositoryInterface $members,
        private SensitiveDataCipherInterface $cipher,
    ) {}

    /** @param array<string, mixed> $input */
    public function submitPersonal(int $memberId, array $input): int
    {
        $this->requireSubmittable($memberId, 'personal');
        $number = self::normalizeIdentifier((string) $input['id_number']);
        return $this->records->create([
            'member_id' => $memberId, 'authentication_type' => 'personal', 'status' => 'pending',
            'subject_name' => trim((string) $input['real_name']), 'id_type' => (string) $input['id_type'],
            'id_number_ciphertext' => $this->cipher->encrypt($number), 'id_number_hash' => $this->cipher->fingerprint($number),
            'id_number_masked' => self::mask($number), 'company_name' => null,
            'registration_code_ciphertext' => null, 'registration_code_hash' => null,
            'registration_code_masked' => null, 'legal_representative' => null, 'upgrade_from_id' => null,
        ]);
    }

    /** @param array<string, mixed> $input */
    public function submitEnterprise(int $memberId, array $input): int
    {
        $this->requireSubmittable($memberId, 'enterprise');
        $representativeNumber = self::normalizeIdentifier((string) $input['legal_representative_id_number']);
        $registrationCode = self::normalizeIdentifier((string) $input['registration_code']);
        $personal = $this->records->latestApproved($memberId, 'personal');
        $upgradeFrom = is_array($personal) && hash_equals((string) $personal['id_number_hash'], $this->cipher->fingerprint($representativeNumber))
            ? (int) $personal['id'] : null;
        return $this->records->create([
            'member_id' => $memberId, 'authentication_type' => 'enterprise', 'status' => 'pending',
            'subject_name' => trim((string) $input['legal_representative']), 'id_type' => 'identity_card',
            'id_number_ciphertext' => $this->cipher->encrypt($representativeNumber),
            'id_number_hash' => $this->cipher->fingerprint($representativeNumber), 'id_number_masked' => self::mask($representativeNumber),
            'company_name' => trim((string) $input['company_name']),
            'registration_code_ciphertext' => $this->cipher->encrypt($registrationCode),
            'registration_code_hash' => $this->cipher->fingerprint($registrationCode),
            'registration_code_masked' => self::mask($registrationCode, 4, 4),
            'legal_representative' => trim((string) $input['legal_representative']), 'upgrade_from_id' => $upgradeFrom,
        ]);
    }

    private function requireSubmittable(int $memberId, string $type): void
    {
        if ($this->members->find($memberId) === null) throw new BusinessException('会员不存在', ApiErrorCode::NOT_FOUND, 404);
        if ($this->records->hasPending($memberId)) throw new BusinessException('已有实名认证申请正在审核', ApiErrorCode::CONFLICT, 409);
        if ($this->records->latestApproved($memberId, $type) !== null) throw new BusinessException('该类型实名认证已经通过', ApiErrorCode::CONFLICT, 409);
    }

    private static function normalizeIdentifier(string $value): string { return strtoupper(preg_replace('/\s+/', '', trim($value)) ?? ''); }

    private static function mask(string $value, int $prefix = 3, int $suffix = 4): string
    {
        $length = strlen($value);
        if ($length <= $prefix + $suffix) return str_repeat('*', $length);
        return substr($value, 0, $prefix) . str_repeat('*', $length - $prefix - $suffix) . substr($value, -$suffix);
    }
}
