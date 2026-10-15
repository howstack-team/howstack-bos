<?php

declare(strict_types=1);

/** 本地临时商户密钥的真实HTTP验签/账务验收；不调用真实支付创建、查单或扣款。 */
if (getenv('HOWSTACK_RECHARGE_HTTP_ACCEPTANCE') !== '1') exit('请显式启用本机支付验收。');
$backend = dirname(__DIR__, 2); require $backend . '/vendor/autoload.php';
Dotenv\Dotenv::createUnsafeImmutable($backend)->safeLoad(); support\App::loadAllConfig(['route']);
date_default_timezone_set((string) config('app.default_timezone', 'Asia/Shanghai'));
if (in_array(strtolower((string) getenv('APP_ENV')), ['prod', 'production'], true) || !in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1'], true)) exit('仅限本机开发数据库。');

use app\modules\finance\infrastructure\DatabaseOnlinePaymentRepository;
use app\modules\finance\infrastructure\model\{OnlinePaymentAttemptModel, PaymentTransactionModel, RechargeModel, PaymentNotificationModel, WalletEntryModel, WalletFundingLotModel, WalletAccountModel};
use app\modules\member\infrastructure\model\{MemberModel, MemberSessionModel};
use app\modules\member\domain\PublicMemberId;
use app\modules\channel\contract\ChannelPlatformRegistryInterface;
use app\modules\plugin\infrastructure\PluginDependencyLoader;
use app\shared\plugin\dto\Configuration;
use support\Container;

function proof(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function pair(): array {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]); openssl_pkey_export($key, $private);
    return [$private, openssl_pkey_get_details($key)['key']];
}
$member = null; $paymentIds = []; $rechargeIds = [];
try {
    // 无联系方式的临时测试会员，不向随机手机号或邮箱发送收款通知。
    $member = MemberModel::query()->create(['public_id' => PublicMemberId::generate(), 'mobile' => null, 'email' => null,
        'nickname' => '在线支付HTTP临时会员', 'password_hash' => password_hash('Fixture@123456', PASSWORD_DEFAULT), 'status' => 1]);
    $registry = Container::get(ChannelPlatformRegistryInterface::class); $repo = Container::get(\app\modules\finance\contract\OnlinePaymentRepositoryInterface::class);
    foreach (['alipay_order_code', 'wechat_native'] as $plugin) {
        [$merchantPrivate] = pair(); [$platformPrivate, $platformPublic] = pair();
        $definition = $registry->definition('payment', $plugin); proof($definition !== null, '测试支付插件必须启用');
        $config = $plugin === 'alipay_order_code' ? ['app_id' => '2021000000000001', 'seller_id' => '2088123456789012', 'sandbox' => true, 'private_key' => $merchantPrivate, 'alipay_public_key' => $platformPublic]
            : ['app_id' => 'wx1234567890abcdef', 'mch_id' => '1234567890', 'merchant_serial' => str_repeat('A', 40), 'platform_serial' => 'PUB_KEY_ID_TEST', 'private_key' => $merchantPrivate, 'platform_public_key' => $platformPublic, 'api_v3_key' => str_repeat('k', 32)];
        $token = bin2hex(random_bytes(32));
        $row = $repo->reserve(['member_id' => $member->id, 'channel_id' => 987654, 'amount_minor' => 1001,
            'request_key' => bin2hex(random_bytes(16)), 'request_hash' => hash('sha256', $token), 'notify_token' => $token,
            'notify_url' => 'https://merchant.example.test/payment-notify/' . $token, 'origin' => ['client_ip' => '127.0.0.1', 'client_ip_location' => '本机']],
            ['plugin_id' => $plugin, 'package_hash' => $definition['package_hash'], 'channel_revision' => 1, 'configuration' => new Configuration($config)]);
        $paymentIds[] = $row['payment_id']; $rechargeIds[] = $row['recharge_id'];
        if ($plugin === 'alipay_order_code') {
            $p = ['app_id' => $config['app_id'], 'seller_id' => $config['seller_id'], 'out_trade_no' => $row['payment_no'],
                'total_amount' => '10.01', 'trade_status' => 'TRADE_SUCCESS', 'trade_no' => 'FIX_' . bin2hex(random_bytes(8)), 'notify_id' => 'NOTICE_' . bin2hex(random_bytes(8))];
            ksort($p); $content = implode('&', array_map(fn ($k) => $k . '=' . $p[$k], array_keys($p)));
            openssl_sign($content, $sign, $platformPrivate, OPENSSL_ALGO_SHA256); $p['sign_type'] = 'RSA2'; $p['sign'] = base64_encode($sign);
            $body = http_build_query($p); $headers = ['Content-Type: application/x-www-form-urlencoded'];
        } else {
            PluginDependencyLoader::load($backend . '/plugins/payment/wechat_native', 'plugins\\payment\\wechat_native\\');
            $trade = ['appid' => $config['app_id'], 'mchid' => $config['mch_id'], 'out_trade_no' => $row['payment_no'], 'amount' => ['total' => 1001, 'currency' => 'CNY'], 'trade_state' => 'SUCCESS', 'transaction_id' => 'WX_' . bin2hex(random_bytes(8))];
            $nonce = str_repeat('n', 12); $aad = 'transaction';
            $cipher = \WeChatPay\Crypto\AesGcm::encrypt(json_encode($trade, JSON_THROW_ON_ERROR), $config['api_v3_key'], $nonce, $aad);
            $body = json_encode(['id' => 'NOTICE_' . bin2hex(random_bytes(8)), 'event_type' => 'TRANSACTION.SUCCESS', 'resource' => ['original_type' => 'transaction', 'algorithm' => 'AEAD_AES_256_GCM', 'nonce' => $nonce, 'associated_data' => $aad, 'ciphertext' => $cipher]], JSON_THROW_ON_ERROR);
            $timestamp = (string) time(); $responseNonce = 'http-test';
            $signature = \WeChatPay\Crypto\Rsa::sign(\WeChatPay\Formatter::response($timestamp, $responseNonce, $body), \WeChatPay\Crypto\Rsa::from($platformPrivate, \WeChatPay\Crypto\Rsa::KEY_TYPE_PRIVATE));
            $headers = ['Content-Type: application/json', 'Wechatpay-Timestamp: ' . $timestamp, 'Wechatpay-Nonce: ' . $responseNonce, 'Wechatpay-Serial: PUB_KEY_ID_TEST', 'Wechatpay-Signature: ' . $signature];
        }
        $curl = curl_init('http://127.0.0.1:8989/payment-notify/' . $token);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15]);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body . 'tampered'); curl_exec($curl);
        proof(curl_getinfo($curl, CURLINFO_RESPONSE_CODE) >= 400, '篡改通知不能成功应答');
        proof(PaymentTransactionModel::query()->whereKey($row['payment_id'])->value('status') === 'creating', '非法签名不能入账');
        for ($i = 0; $i < 2; $i++) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body); $reply = curl_exec($curl);
            $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            proof($code === ($plugin === 'wechat_native' ? 204 : 200), '合法通知应答失败，HTTP ' . $code);
            if ($plugin === 'alipay_order_code') proof($reply === 'success', '支付宝ACK必须是纯文本success');
        }
        curl_close($curl);
        proof(RechargeModel::query()->whereKey($row['recharge_id'])->value('status') === 'credited', '充值未原子入账');
    }
    proof(WalletAccountModel::query()->where('member_id', $member->id)->value('available_minor') === 2002, '两笔回调应各到账一次');
    proof(WalletEntryModel::query()->where('member_id', $member->id)->count() === 2, '重复通知产生重复流水');
    proof(PaymentNotificationModel::query()->whereIn('payment_id', $paymentIds)->count() === 2, '回调去重错误');
    echo "真实HTTP支付宝RSA2和微信RSA/AES通知、篡改拒绝、重复ACK及原子入账通过；支付上游调用0次。\n";
} finally {
    if ($member !== null) {
        WalletEntryModel::query()->where('member_id', $member->id)->delete();
        WalletFundingLotModel::query()->whereIn('recharge_id', $rechargeIds)->delete(); WalletAccountModel::query()->where('member_id', $member->id)->delete();
        PaymentNotificationModel::query()->whereIn('payment_id', $paymentIds)->delete(); OnlinePaymentAttemptModel::query()->whereIn('payment_id', $paymentIds)->delete();
        PaymentTransactionModel::query()->whereIn('id', $paymentIds)->delete(); RechargeModel::query()->whereIn('id', $rechargeIds)->delete();
        MemberSessionModel::query()->where('member_id', $member->id)->delete();
        // 同事务生成的测试站内消息可能引用会员，清理由测试来源标识限定。
        \support\Db::table('messages')->where('business_type', 'recharge')->whereIn('business_id', $rechargeIds)->delete();
        MemberModel::query()->whereKey($member->id)->delete();
    }
}
