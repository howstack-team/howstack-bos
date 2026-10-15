<?php

declare(strict_types=1);

namespace plugins\identity\alipay_identity\app;

use Alipay\EasySDK\Kernel\Config;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\exception\PluginException;

/** 支付宝公钥模式 RSA2 配置；每次调用独立生成，不使用 Factory 单例。 */
final class AlipayConfiguration
{
    /**
     * @param Configuration $configuration 本次商户配置
     * @return Configuration 规范化配置
     * @throws PluginException 密钥或身份无效
     */
    public static function normalize(Configuration $configuration): Configuration
    {
        $values = $configuration->values;
        if (!is_string($values['app_id'] ?? null) || !preg_match('/^[0-9]{16}$/D', $values['app_id'])
            || !is_bool($values['sandbox'] ?? null)) {
            throw new PluginException('支付宝应用或商户配置无效', 'invalid_configuration');
        }
        $private = self::readPrivate((string) ($values['private_key'] ?? ''));
        $details = openssl_pkey_get_details($private);
        $publicText = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', (string) ($values['alipay_public_key'] ?? ''));
        $public = @openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split($publicText, 64, "\n") . "-----END PUBLIC KEY-----");
        $publicDetails = $public === false ? false : openssl_pkey_get_details($public);
        if (!is_array($details) || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] !== 2048
            || !is_array($publicDetails) || $publicDetails['type'] !== OPENSSL_KEYTYPE_RSA || $publicDetails['bits'] < 2048) {
            throw new PluginException('请填写 2048 位 RSA2 应用私钥和正确的支付宝公钥', 'invalid_configuration');
        }
        // SDK Signer 接受 PKCS#1 Base64；同时兼容用户提供的 PKCS#8 私钥。
        $der = "\x02\x01\x00";
        foreach (['n', 'e', 'd', 'p', 'q', 'dmp1', 'dmq1', 'iqmp'] as $field) {
            $value = ltrim($details['rsa'][$field], "\x00");
            if (ord($value[0]) > 127) {
                $value = "\x00" . $value;
            }
            $der .= "\x02" . self::length(strlen($value)) . $value;
        }
        $values['private_key'] = base64_encode("\x30" . self::length(strlen($der)) . $der);
        $values['alipay_public_key'] = $publicText;
        return new Configuration($values);
    }

    /**
     * @param Configuration $configuration 规范化本次配置
     * @return Config SDK 配置，协议和网关不可由用户任意覆盖
     */
    public static function sdk(Configuration $configuration): Config
    {
        $config = new Config();
        $config->protocol = 'https';
        $config->gatewayHost = $configuration->values['sandbox'] ? 'openapi-sandbox.dl.alipaydev.com' : 'openapi.alipay.com';
        $config->appId = $configuration->values['app_id'];
        $config->merchantPrivateKey = $configuration->values['private_key'];
        $config->alipayPublicKey = $configuration->values['alipay_public_key'];
        $config->signType = 'RSA2';
        $config->ignoreSSL = false;
        return $config;
    }

    /**
     * @param string $text PEM 或 Base64 私钥
     * @return \OpenSSLAsymmetricKey 已解析的 RSA 私钥
     * @throws PluginException 私钥格式错误
     */
    private static function readPrivate(string $text): \OpenSSLAsymmetricKey
    {
        $base64 = preg_replace('/-----BEGIN (?:RSA )?PRIVATE KEY-----|-----END (?:RSA )?PRIVATE KEY-----|\s+/', '', $text);
        foreach (['PRIVATE KEY', 'RSA PRIVATE KEY'] as $label) {
            $key = @openssl_pkey_get_private('-----BEGIN ' . $label . "-----\n" . chunk_split($base64, 64, "\n") . '-----END ' . $label . '-----');
            if ($key !== false) {
                return $key;
            }
        }
        throw new PluginException('应用私钥格式无效', 'invalid_configuration');
    }

    /**
     * @param int $length DER 内容长度
     * @return string ASN.1 长度字节
     */
    private static function length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");
        return chr(128 + strlen($bytes)) . $bytes;
    }
}
