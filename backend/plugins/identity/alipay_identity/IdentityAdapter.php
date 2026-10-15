<?php

declare(strict_types=1);

namespace plugins\identity\alipay_identity;

use app\shared\plugin\contract\HttpClientInterface;
use app\shared\plugin\contract\IdentityAdapterInterface;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\identity\IdentityReference;
use app\shared\plugin\dto\identity\IdentityRequest;
use app\shared\plugin\dto\identity\IdentityResult;
use app\shared\plugin\exception\PluginException;
use app\shared\plugin\http\HttpClient;
use plugins\identity\alipay_identity\app\AlipayConfiguration;
use plugins\identity\alipay_identity\app\AlipayGateway;

/** 支付宝个人身份验证：初始化、签名认证链接及主动查验。 */
final readonly class IdentityAdapter implements IdentityAdapterInterface
{
    /** @param HttpClientInterface $http 公共 HTTP 能力，测试可注入 */
    public function __construct(private HttpClientInterface $http = new HttpClient())
    {
    }

    /** @inheritDoc */
    public function validateConfiguration(Configuration $configuration): Configuration
    {
        return AlipayConfiguration::normalize($configuration);
    }

    /** @inheritDoc */
    public function initialize(IdentityRequest $request, Configuration $configuration): IdentityResult
    {
        $configuration = $this->validateConfiguration($configuration);
        $gateway = new AlipayGateway($this->http);
        try {
            $response = $gateway->execute($configuration, 'alipay.user.certify.open.initialize', [
                'outer_order_no' => $request->requestNo,
                'biz_code' => 'FACE',
                'identity_param' => [
                    'identity_type' => 'CERT_INFO',
                    'cert_type' => 'IDENTITY_CARD',
                    'cert_name' => $request->name,
                    'cert_no' => $request->identityNumber,
                ],
                'merchant_config' => ['return_url' => $request->returnUrl],
            ]);
            if ($response['code'] !== '10000') {
                return new IdentityResult($response['code'] === '20000' ? 'unknown' : 'failed', $request->requestNo);
            }
            $certifyId = $response['certify_id'] ?? '';
            if (!is_string($certifyId) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $certifyId)) {
                throw new PluginException('支付宝认证流水无效', 'invalid_response');
            }
            return new IdentityResult('pending', $request->requestNo, $certifyId, $gateway->certificationUrl($configuration, $certifyId));
        } catch (PluginException $error) {
            if (!in_array($error->reason, ['request_unknown', 'invalid_response'], true)) {
                throw $error;
            }
            return new IdentityResult('unknown', $request->requestNo);
        }
    }

    /** @inheritDoc */
    public function query(IdentityReference $reference, Configuration $configuration): IdentityResult
    {
        $configuration = $this->validateConfiguration($configuration);
        $response = (new AlipayGateway($this->http))->execute($configuration, 'alipay.user.certify.open.query', [
            'certify_id' => $reference->certifyId,
        ]);
        $status = $response['code'] === '10000' ? match ($response['passed'] ?? '') {
            'T' => 'verified',
            'F' => 'rejected',
            default => 'unknown',
        } : 'unknown';
        return new IdentityResult($status, $reference->requestNo, $reference->certifyId);
    }
}
