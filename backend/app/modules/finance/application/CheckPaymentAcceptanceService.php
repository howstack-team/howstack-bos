<?php

declare(strict_types=1);

namespace app\modules\finance\application;

use app\exception\BusinessException;
use app\modules\finance\contract\{OnlinePaymentGatewayInterface, PaymentAcceptanceReadRepositoryInterface};
use app\modules\finance\domain\PaymentAcceptanceAudit;
use app\modules\system_settings\application\GetSystemSettingsService;
use app\shared\http\ApiErrorCode;
use app\shared\plugin\dto\payment\PaymentRequest;

/** R05的只读前置检查，不调用创建/查单/关单，不将本地证据标记成真实平台验收。 */
final readonly class CheckPaymentAcceptanceService
{
    /**
     * 注入现有配置查询与本地证据端口。
     * @param OnlinePaymentGatewayInterface $gateway 仅调用可用通道目录
     * @param GetSystemSettingsService $settings 平台固定地址
     * @param PaymentAcceptanceReadRepositoryInterface $evidence 本地只读财务证据
     */
    public function __construct(
        private OnlinePaymentGatewayInterface $gateway,
        private GetSystemSettingsService $settings,
        private PaymentAcceptanceReadRepositoryInterface $evidence,
    ) {
    }

    /**
     * 获取前置检查和可选单笔支付的账务报告。
     * @param int|null $paymentId 后台支付内部ID，NULL只检查配置
     * @return array<string,mixed> 无商户秘密及个人信息的验收准备报告
     */
    public function check(?int $paymentId = null): array
    {
        $root = rtrim($this->settings->base()['site_url'], '/');
        $parts = parse_url($root);
        $host = rtrim(strtolower((string) ($parts['host'] ?? '')), '.');
        $validRoot = is_array($parts) && PaymentRequest::validUrl($root) && !isset($parts['query'])
            && in_array($parts['path'] ?? '', ['', '/'], true);
        $publicCandidate = $validRoot && $host !== '' && $host !== 'localhost' && !str_ends_with($host, '.localhost')
            && (str_contains($host, '.') || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) && !str_ends_with($host, '.example')
            && !str_ends_with($host, '.local') && !str_ends_with($host, '.test') && !str_ends_with($host, '.invalid')
            && !in_array($host, ['example.com', 'example.net', 'example.org'], true)
            && !str_ends_with($host, '.example.com') && !str_ends_with($host, '.example.net') && !str_ends_with($host, '.example.org');
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            $publicCandidate = $publicCandidate && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        $channels = $this->gateway->channels();
        $blockers = [];
        if (!$validRoot) {
            $blockers[] = '平台地址须为不带路径、查询参数或凭据的HTTPS根地址';
        } elseif (!$publicCandidate) {
            $blockers[] = '平台地址仍是内网或保留测试地址，真实回调需要可公网访问的HTTPS域名';
        }
        if ($channels === []) {
            $blockers[] = '尚无已启用且配置有效的支付通道';
        }
        $transaction = null;
        if ($paymentId !== null) {
            if ($paymentId < 1) {
                throw new BusinessException('支付记录ID无效', ApiErrorCode::VALIDATION_FAILED, 422);
            }
            $evidence = $this->evidence->evidence($paymentId);
            if ($evidence === null) {
                throw new BusinessException('在线充值支付记录不存在', ApiErrorCode::NOT_FOUND, 404);
            }
            $transaction = PaymentAcceptanceAudit::inspect($evidence);
        }
        return [
            'checked_at' => date('Y-m-d H:i:s'), 'scope' => 'read_only_local_preflight',
            'ready_for_external_test' => $blockers === [], 'external_verification' => 'pending',
            'callback_reachability' => 'not_tested', 'merchant_bill_match' => 'not_tested',
            'blockers' => $blockers, 'channels' => $channels, 'transaction' => $transaction,
        ];
    }
}
