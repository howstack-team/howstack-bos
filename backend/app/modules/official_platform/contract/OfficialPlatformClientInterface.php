<?php

declare(strict_types=1);

namespace app\modules\official_platform\contract;

/** 固定的官网v1能力，不提供浏览器指定任意外部地址或方法的入口。 */
interface OfficialPlatformClientInterface
{
    /** @return array<string,mixed> 本地安全连接投影，不含私钥、Bearer或device_code */
    public function localStatus(): array;

    /** @param string $code 授权码，仅本次发送，不保存 @return array<string,mixed> 已绑定安装 */
    public function activate(string $code): array;
    /** @param string $id 本机任务 @param array<string,mixed> $manifest 已验签固定目标 @return void 幂等登记系统更新 */
    public function registerSoftwareOperation(string $id, array $manifest): void;
    /** @param string $id 固定目标任务 @return array<string,mixed> 新短期签名清单 */
    public function softwareOperationManifest(string $id): array;
    /** @param string $id 更新任务 @param string $status 实际阶段 @param string $errorCode 安全失败码 @return void 只回报不重做安装 */
    public function reportSoftwareOperation(string $id, string $status, string $errorCode = ''): void;

    /** @return array<string,mixed> 实时、限定本软件与本实例的连接资料 */
    public function synchronize(): array;

    /** @return void 撤销官网连接，保留本机签名证书及业务记录 */
    public function disconnect(): void;

    /** @return array<string,mixed> 本连接购买权益，不合并其他实例 */
    public function owned(): array;

    /** @return string 固定官网商店页面地址，无需账号连接，不附带凭证 */
    public function storeUrl(): string;

    /** @return array<string,mixed> 待验证的商业签名证书信封 */
    public function certificate(): array;

    /** @return array<string,mixed> 统一软件更新结果，不把接口失败当作没有更新 */
    public function checkUpdate(): array;

    /**
     * @param int $versionId 目标版本
     * @return array<string,mixed> 待验证的应用发布信封
     */
    public function applicationManifest(int $versionId): array;

    /**
     * @param array<string,mixed> $manifest 已验签的发布元数据
     * @param string $destination 固定私有文件
     * @return void 校验大小与摘要后的文件
     */
    public function download(array $manifest, string $destination): void;

    /**
     * @param string $requestId 本机幂等UUID
     * @param int $versionId 应用版本
     * @param string $action install/update
     * @return void 官网受理，不代表本机完成
     */
    public function registerOperation(string $requestId, int $versionId, string $action): void;

    /**
     * @param string $requestId 已受理任务
     * @param string $status 固定状态
     * @param string $errorCode 安全错误码
     * @return void 幂等上报本机事实
     */
    public function reportOperation(string $requestId, string $status, string $errorCode = ''): void;
}
