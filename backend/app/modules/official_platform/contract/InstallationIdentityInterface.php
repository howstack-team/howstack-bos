<?php

declare(strict_types=1);

namespace app\modules\official_platform\contract;

/** 稳定实例身份；不提供私钥读取接口。 */
interface InstallationIdentityInterface
{
    /** @return string 小写RFC4122 UUID */
    public function id(): string;

    /** @return string Base64 Ed25519公钥 */
    public function publicKey(): string;

    /**
     * @param string $bytes 原始协议字节
     * @return string Base64分离签名
     */
    public function sign(string $bytes): string;
}
