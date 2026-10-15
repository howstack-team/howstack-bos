<?php

declare(strict_types=1);

namespace tests\unit\modules\message;

use app\modules\message\validation\MessageQueryValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use app\exception\BusinessException;

/** 查询字段白名单、日期范围与安全整数边界。 */
final class MessageQueryValidatorTest extends TestCase
{
    #[DataProvider('invalidQueries')]
    public function testInvalidQueriesAreRejected(array $query): void
    {
        $this->expectException(BusinessException::class);
        (new MessageQueryValidator())->query($query);
    }

    public static function invalidQueries(): array
    {
        return [
            [['created_from' => '2026-10-08', 'created_to' => '2026-10-07']],
            [['created_from' => '2026-02-30']],
            [['business_id' => 0]],
            [['business_id' => '9007199254740992']],
            [['business_id' => 'abc']],
            [['status' => 'success']],
            [['audience' => 'all']],
        ];
    }

    public function testOptionalBoundsAndUnknownFieldsDoNotBecomeQueries(): void
    {
        $validator = new MessageQueryValidator();
        self::assertSame('2026-10-07', $validator->query(['created_to' => '2026-10-07'])['created_to']);
        self::assertSame('2026-10-07', $validator->query(['created_from' => '2026-10-07'])['created_from']);
        self::assertSame(7, $validator->query(['business_id' => '7'])['business_id']);
        self::assertArrayNotHasKey('payload_encrypted', $validator->query(['payload_encrypted' => 'private']));
    }
}
