<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\provider;

/** 单实例运维请求；密码只存在于首次外呼内存，不进入日志与序列化。 */
final readonly class InstanceOperationRequest implements \JsonSerializable
{
    /**
     * @param string $requestKey 固定请求标识
     * @param string $externalId 原供应实例标识
     * @param string $hostname 原创建主机名
     * @param string $action power_on/power_off/reboot/reinstall/reset_password
     * @param array<string,mixed> $options 核心核定的镜像与端口
     * @param string|null $password 首次提交时短期解密的密码，查单为空
     * @param string|null $providerTaskId 已受理的供应任务标识
     */
    public function __construct(
        public string $requestKey,
        public string $externalId,
        public string $hostname,
        public string $action,
        public array $options = [],
        #[\SensitiveParameter] public ?string $password = null,
        public ?string $providerTaskId = null,
    ) {
        if (!in_array($action, ['power_on', 'power_off', 'reboot', 'reinstall', 'reset_password'], true) || $externalId === '') {
            throw new \InvalidArgumentException('无效实例运维请求');
        }
    }

    /**
     * @return array<string,string> 安全日志投影
     */
    public function jsonSerialize(): array
    {
        return ['request_key' => $this->requestKey, 'action' => $this->action, 'credentials' => '[redacted]'];
    }

    /**
     * @return array<string,string> 安全调试投影
     */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
