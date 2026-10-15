<?php

declare(strict_types=1);

namespace app\modules\admin\auth\domain;

/**
 * 表示通过签名、用途和有效期校验的管理员令牌声明。
 */
final readonly class AccessTokenClaims
{
    /**
     * 创建管理员令牌声明。
     * @param int    $adminId   管理员标识
     * @param string $tokenId   令牌唯一标识
     * @param int    $issuedAt  签发时间戳
     * @param int    $expiresAt 过期时间戳
     * @param string $credentialProof 当前密码的不可逆会话绑定证明，不包含密码哈希
     */
    public function __construct(
        public int $adminId,
        public string $tokenId,
        public int $issuedAt,
        public int $expiresAt,
        public string $credentialProof = '',
    ) {
    }
}
