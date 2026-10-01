<?php

declare(strict_types=1);

namespace tests\unit\modules\order\domain;

use app\modules\order\domain\OrderUnitAllocator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OrderUnitAllocatorTest extends TestCase
{
    public function testAllocatesDiscountAndRoundingAcrossIndividualInstances(): void
    {
        $units = (new OrderUnitAllocator())->allocate([
            ['item_id' => 1, 'quantity' => 3, 'total_minor' => 1000],
            ['item_id' => 2, 'quantity' => 1, 'total_minor' => 500],
        ], 1201);

        self::assertSame([267, 267, 267, 400], array_column($units, 'allocated_minor'));
        self::assertSame(1201, array_sum(array_column($units, 'allocated_minor')));
        self::assertSame([1, 2, 3, 1], array_column($units, 'unit_index'));
    }

    public function testSupportsZeroPricedTrialUnits(): void
    {
        self::assertSame([0, 0], array_column((new OrderUnitAllocator())->allocate([
            ['item_id' => 1, 'quantity' => 2, 'total_minor' => 0],
        ], 0), 'allocated_minor'));
    }

    public function testRejectsPayableAboveItemsTotal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new OrderUnitAllocator())->allocate([['item_id' => 1, 'quantity' => 1, 'total_minor' => 99]], 100);
    }

    public function testLargeAmountsNeverUseFloatingPointOrOverflowMultiplication(): void
    {
        $payable = PHP_INT_MAX - 1;
        $units = (new OrderUnitAllocator())->allocate([
            ['item_id' => 1, 'quantity' => 2, 'total_minor' => PHP_INT_MAX],
        ], $payable);

        self::assertSame($payable, array_sum(array_column($units, 'allocated_minor')));
        self::assertSame([intdiv(PHP_INT_MAX, 2), intdiv(PHP_INT_MAX, 2)], array_column($units, 'allocated_minor'));
    }
}
