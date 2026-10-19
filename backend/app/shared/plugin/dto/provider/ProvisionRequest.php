<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\provider;

/** 仅在调用期间包含密码；标准配置及供应参数不含敏感字段，每次只创建一台。 */
final readonly class ProvisionRequest implements \JsonSerializable
{
    /** @param string $requestKey 固定幂等键及实例主机名 @param array<string,mixed> $configuration 单实例标准配置 @param array<string,mixed> $parameters 封存映射得到的参数 @param string $password 临时解密的实例密码 @param string|null $externalId 已确认的外部实例标识 */
    public function __construct(public string $requestKey, public array $configuration, public array $parameters, #[\SensitiveParameter] public string $password, public ?string $externalId = null) {}
    /** @return array{request_key:string,redacted:bool} 调试与序列化始终移除密码、参数及配置 */
    public function jsonSerialize(): array { return ['request_key' => $this->requestKey, 'redacted' => true]; }
    /** @return array{request_key:string,redacted:bool} 禁止调试输出密码 */
    public function __debugInfo(): array { return $this->jsonSerialize(); }
}
