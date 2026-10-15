<?php

declare(strict_types=1);

namespace app\shared\entitlement;

use InvalidArgumentException;
use JsonSerializable;

/** 由宿主用例核实的业务范围；不能直接由 HTTP 请求反序列化。 */
final readonly class EntitlementContext implements JsonSerializable
{
    /**
     * @param string $businessReference 已核实的业务引用，禁止包含秘密
     * @param string $configurationHash 该业务固定范围的 SHA-256
     * @param string|null $continuationProof 宿主在有效受理时生成的加密证明
     * @param int|null $releasePublishedAt 已验签目标版本的首次发布时间，Unix 秒
     */
    public function __construct(
        public string $businessReference = '',
        public string $configurationHash = '',
        public ?string $continuationProof = null,
        public ?int $releasePublishedAt = null,
    ) {
        if (strlen($businessReference) > 160 || preg_match('/[\x00-\x1f\x7f]/', $businessReference)
            || ($configurationHash !== '' && !preg_match('/^[a-f0-9]{64}$/D', $configurationHash))
            || ($continuationProof !== null && strlen($continuationProof) > 200000)
            || ($releasePublishedAt !== null && $releasePublishedAt < 1)) {
            throw new InvalidArgumentException('授权操作上下文无效');
        }
    }

    /** @return array<string, mixed> 调试时不泄露业务证明 */
    public function __debugInfo(): array
    {
        return ['businessReference' => $this->businessReference, 'hasProof' => $this->continuationProof !== null];
    }

    /** @return array<string, mixed> 普通 JSON 日志不包含可复用受理证明 */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
