<?php

declare(strict_types=1);

namespace app\modules\product\contract;

/** 公开购买的类型准入与展示投影；公共层不解释任何类型专属配置。 */
interface ProductPublicPurchaseInterface
{
    /** @param array<string, mixed> $snapshot 已封存销售配置 */
    public function supportsPublic(string $type, array $snapshot): bool;

    /** @param array<string, mixed> $choice 目录项 @return array<string, mixed> 类型购买入口及描述 */
    public function publicProduct(string $type, array $choice): array;

    /** @param array<string, mixed> $resolved 类型解析结果 @return array<string, mixed> 白名单公开选项 */
    public function publicOptions(string $type, array $resolved): array;
}
