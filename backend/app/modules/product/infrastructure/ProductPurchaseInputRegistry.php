<?php

declare(strict_types=1);

namespace app\modules\product\infrastructure;

use app\modules\product\contract\ProductPurchaseInputInterface;
use app\modules\compute\validation\ComputePurchaseValidator;
use app\exception\BusinessException;
use app\shared\http\ApiErrorCode;

final class ProductPurchaseInputRegistry implements ProductPurchaseInputInterface
{
    public function clean(string $type, array $input): array
    {
        $handler = ['cloud_server' => ComputePurchaseValidator::class][$type]
            ?? throw new BusinessException('该产品尚未接入购买配置校验', ApiErrorCode::CONFLICT, 409);
        return $handler::clean($input);
    }
}
