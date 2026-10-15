<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\identity;

use app\shared\plugin\http\HttpsUrl;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;

/** 单次个人身份认证输入；不允许证件信息进入调试、JSON 或任务持久化。 */
final readonly class IdentityRequest implements JsonSerializable
{
    /**
     * @param string $requestNo 宿主认证申请流水
     * @param string $name 姓名
     * @param string $identityNumber 身份证号
     * @param string $returnUrl 宿主固定 HTTPS 返回地址
     */
    public function __construct(
        public string $requestNo,
        public string $name,
        public string $identityNumber,
        public string $returnUrl,
    ) {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $requestNo) || trim($name) === '' || mb_strlen($name) > 64
            || preg_match('/[\x00-\x1f\x7f]/', $name) || !preg_match('/^[0-9]{17}[0-9X]$/D', $identityNumber)
            || !HttpsUrl::valid($returnUrl)) {
            throw new InvalidArgumentException('身份认证请求无效');
        }
    }

    /**
     * @return array{redacted: true} 不暴露姓名或证件信息
     */
    public function jsonSerialize(): array
    {
        return ['redacted' => true];
    }

    /**
     * @return array{redacted: true} 调试脱敏
     */
    public function __debugInfo(): array
    {
        return ['redacted' => true];
    }

    /**
     * @return never
     * @throws LogicException 应持久化申请引用，由宿主解密后调用
     */
    public function __serialize(): array
    {
        throw new LogicException('认证输入不能序列化，任务应保存申请引用');
    }
}
