<?php

declare(strict_types=1);

namespace tests\unit\modules\finance;

use app\exception\BusinessException;
use app\modules\finance\validation\FinanceInputValidator;
use PHPUnit\Framework\TestCase;

/** 先校验原始类型再转换，避免金额截断及重复字段反馈。 */
final class FinanceInputValidatorTest extends TestCase
{
    public function testInvalidAmountMustNotBeSilentlyCastToInteger(): void
    {
        foreach (['1abc', '1.5', 1.5, [], false, 0] as $amount) {
            try {
                FinanceInputValidator::create('refunds', ['request_key' => str_repeat('a', 16), 'member_id' => 1,
                    'source_id' => 1, 'amount_minor' => $amount, 'reason' => '确认未创建']);
                self::fail('原始金额类型不合法不能强转整数');
            } catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        }
    }

    public function testApprovalAndPayoutProofAreRequiredAfterTrimming(): void
    {
        foreach ([['refunds', 'approve', ['review_reason' => '  ']], ['withdrawals', 'approve', []],
            ['withdrawals', 'payout', ['external_reference' => 'BANK-001', 'payout_note' => '  ']]] as [$resource, $action, $input]) {
            try {
                FinanceInputValidator::action($resource, 1, $action, ['request_key' => str_repeat('a', 16)] + $input);
                self::fail('审批和凭证不能留空');
            } catch (BusinessException $error) { self::assertSame(422, $error->httpStatus()); }
        }
        $valid = FinanceInputValidator::create('withdrawals', ['request_key' => str_repeat('a', 16), 'member_id' => '1', 'source_id' => '2',
            'amount_minor' => '100', 'reason' => '  退还未消费充值  ', 'status' => 'paid', 'operator_id' => 99]);
        self::assertSame(100, $valid['amount_minor']);
        self::assertSame('退还未消费充值', $valid['reason']);
        self::assertArrayNotHasKey('status', $valid);
        self::assertArrayNotHasKey('operator_id', $valid);
    }
}
