<?php

declare(strict_types=1);

namespace app\modules\order\domain;

use InvalidArgumentException;

/** 将订单实付金额确定性地分摊到每个可独立交付的实例。 */
final class OrderUnitAllocator
{
    /**
     * @param list<array{item_id: int, quantity: int, total_minor: int}> $items 按订单项行号排列
     * @return list<array{item_id: int, unit_index: int, allocated_minor: int}>
     */
    public function allocate(array $items, int $payableMinor): array
    {
        if ($payableMinor < 0 || $items === []) {
            throw new InvalidArgumentException('订单金额或订单项无效');
        }

        $subtotal = 0;
        $units = [];
        foreach ($items as $item) {
            if ($item['item_id'] < 1 || $item['quantity'] < 1 || $item['total_minor'] < 0) {
                throw new InvalidArgumentException('订单项金额或数量无效');
            }
            if ($item['total_minor'] > PHP_INT_MAX - $subtotal) {
                throw new InvalidArgumentException('订单总金额超出整数范围');
            }
            $subtotal += $item['total_minor'];
            $base = intdiv($item['total_minor'], $item['quantity']);
            $remainder = $item['total_minor'] % $item['quantity'];
            for ($index = 1; $index <= $item['quantity']; $index++) {
                $units[] = [
                    'item_id' => $item['item_id'],
                    'unit_index' => $index,
                    'allocated_minor' => $base + ($index <= $remainder ? 1 : 0),
                ];
            }
        }
        if ($payableMinor > $subtotal) {
            throw new InvalidArgumentException('实付金额不能大于订单项小计');
        }
        if ($subtotal === 0) {
            return $units;
        }

        // 最大余数法按实例原价分摊实付金额；同余时以订单项、实例顺序决定尾差。
        $remainders = [];
        $assigned = 0;
        foreach ($units as $index => &$unit) {
            [$part, $remainder] = self::multiplyDivide($unit['allocated_minor'], $payableMinor, $subtotal);
            $unit['allocated_minor'] = $part;
            $assigned += $part;
            $remainders[] = ['index' => $index, 'remainder' => $remainder];
        }
        unset($unit);
        usort($remainders, static fn (array $a, array $b): int =>
            ($b['remainder'] <=> $a['remainder']) ?: ($a['index'] <=> $b['index']));
        for ($index = 0, $left = $payableMinor - $assigned; $index < $left; $index++) {
            $units[$remainders[$index]['index']]['allocated_minor']++;
        }

        return $units;
    }

    /** @return array{int, int} 无溢出地计算 floor(a*b/d) 与余数。 */
    private static function multiplyDivide(int $a, int $b, int $d): array
    {
        $quotient = 0;
        $remainder = 0;
        $termQuotient = intdiv($a, $d);
        $termRemainder = $a % $d;
        while ($b > 0) {
            if (($b & 1) === 1) {
                [$quotient, $remainder] = self::addFractions(
                    $quotient, $remainder, $termQuotient, $termRemainder, $d,
                );
            }
            $b = intdiv($b, 2);
            if ($b > 0) {
                [$termQuotient, $termRemainder] = self::addFractions(
                    $termQuotient, $termRemainder, $termQuotient, $termRemainder, $d,
                );
            }
        }

        return [$quotient, $remainder];
    }

    /** @return array{int, int} */
    private static function addFractions(int $leftQ, int $leftR, int $rightQ, int $rightR, int $denominator): array
    {
        $quotient = $leftQ + $rightQ;
        if ($leftR >= $denominator - $rightR) {
            return [$quotient + 1, $leftR - ($denominator - $rightR)];
        }

        return [$quotient, $leftR + $rightR];
    }
}
