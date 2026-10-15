<?php

declare(strict_types=1);

namespace app\shared\contract;

use app\shared\entitlement\{EntitlementContext, EntitlementDecision, EntitlementOperation};

/** 商业扩展用例在后端检查功能资格，RBAC检查仍独立执行。 */
interface CommercialFeatureGateInterface
{
    /** @return EntitlementDecision 不执行远程请求；权限与对象归属另由用例检查 */
    public function decide(string $capability, EntitlementOperation $operation, ?EntitlementContext $context = null): EntitlementDecision;

    /** @return void 拒绝时抛出统一业务异常 */
    public function require(string $capability, EntitlementOperation $operation, ?EntitlementContext $context = null): void;

    /** @return string 在有效受理时生成固定业务范围的加密证明，须随业务事务保存 */
    public function createContinuationProof(string $capability, EntitlementContext $context): string;
}
