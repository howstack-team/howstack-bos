<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\oauth;

use app\shared\plugin\http\HttpsUrl;
use InvalidArgumentException;

/** 经过平台验证的外部身份；核心按 provider + appId + subject 绑定，不按昵称绑定。 */
final readonly class OAuthIdentity
{
    /**
     * @param string $provider 插件身份
     * @param string $appId 应用身份
     * @param string $subject OpenID
     * @param string|null $unionId 跨应用身份
     * @param string $nickname 显示名称
     * @param string|null $avatar HTTPS 头像
     */
    public function __construct(
        public string $provider,
        public string $appId,
        public string $subject,
        public ?string $unionId = null,
        public string $nickname = '',
        public ?string $avatar = null,
    ) {
        foreach ([$provider, $appId, $subject] as $identifier) {
            if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $identifier)) {
                throw new InvalidArgumentException('第三方身份无效');
            }
        }
        if (($unionId !== null && !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $unionId))
            || mb_strlen($nickname) > 128 || preg_match('/[\x00-\x1f\x7f]/', $nickname)
            || ($avatar !== null && (strlen($avatar) > 2048 || !HttpsUrl::valid($avatar)))) {
            throw new InvalidArgumentException('第三方用户资料无效');
        }
    }
}
