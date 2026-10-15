<?php

declare(strict_types=1);

namespace app\modules\product\application;

use app\exception\BusinessException;
use app\modules\admin\auth\domain\AdminAccount;
use app\shared\contract\TransactionManagerInterface;

/** 社区配置只需保存；完整配置自动生效，缺项保留输入并明确提示，不覆盖历史版本。 */
final readonly class SaveCloudWorkspaceConfigurationService
{
    public function __construct(
        private ManageCloudWorkspaceConfigurationService $configuration,
        private ManageCloudProductsService $products,
        private TransactionManagerInterface $transactions,
    ) {
    }

    /** @param array<string, mixed> $input 已校验输入 @return array<string, mixed> 保存结果及生效状态 */
    public function save(AdminAccount $actor, ?int $id, array $input, ?int $expectedId): array
    {
        return $this->transactions->run(function () use ($actor, $id, $input, $expectedId): array {
            $saved = $this->configuration->save($actor, $id, $input, $expectedId);
            if (($saved['draft']['schema_version'] ?? null) !== '2.0.0') {
                return $saved + ['configuration_ready' => false, 'configuration_notice' => '请先转换并完善包年包月配置'];
            }
            try {
                $applied = $this->products->applySavedConfiguration($actor, $saved['id'], $saved['revision'], $id === null);
                return $applied + ['configuration_ready' => true, 'configuration_notice' => null];
            } catch (BusinessException $error) {
                // 仅允许配置缺项继续保存；鉴权、并发、存储及系统异常必须整笔回滚。
                if ($error->httpStatus() !== 422) throw $error;
                return $saved + ['configuration_ready' => false, 'configuration_notice' => $error->getMessage()];
            }
        });
    }
}
