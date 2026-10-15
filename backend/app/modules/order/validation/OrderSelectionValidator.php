<?php

declare(strict_types=1);

namespace app\modules\order\validation;

use support\validation\Validator;

/** 公共订单仅验证传输结构；类型专有白名单由产品购买输入契约处理。 */
final class OrderSelectionValidator
{
    public static function clean(mixed $input): array
    {
        return Validator::make(['selection' => $input], ['selection' => 'present|array|max:100'])->validate()['selection'];
    }
}
