<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\identity;

use app\shared\plugin\http\HttpsUrl;
use InvalidArgumentException;

/** 不返回人脸材料、姓名或身份证原文的安全结果。 */
final readonly class IdentityResult
{
    /**
     * @param string $status pending/verified/rejected/failed/unknown
     * @param string $requestNo 宿主流水
     * @param string|null $certifyId 上游认证号
     * @param string|null $url 支付宝认证跳转链接
     */
    public function __construct(
        public string $status,
        public string $requestNo,
        public ?string $certifyId = null,
        public ?string $url = null,
    ) {
        if (!in_array($status, ['pending', 'verified', 'rejected', 'failed', 'unknown'], true)
            || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $requestNo)
            || ($certifyId !== null && !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $certifyId))
            || (in_array($status, ['pending', 'verified', 'rejected'], true) && $certifyId === null)
            || ($url !== null && (strlen($url) > 8192 || !HttpsUrl::valid($url)))) {
            throw new InvalidArgumentException('身份认证结果无效');
        }
    }
}
