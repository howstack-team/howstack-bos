<?php

declare(strict_types=1);

namespace app\shared\plugin\dto\template;

use InvalidArgumentException;

/** 宿主绑定原通道和修订后传入的厂商申请或模板引用，不包含账号秘密。 */
final readonly class TemplateReviewReference
{
    /**
     * @param string $channel sms 或 mail，防止跨类别误调用
     * @param string $providerReference 厂商审核对象 ID；可能是申请 ID，不能默认当作发送模板 ID
     */
    public function __construct(public string $channel, public string $providerReference)
    {
        if (!in_array($channel, ['sms', 'mail'], true) || !self::validId($providerReference)) {
            throw new InvalidArgumentException('模板审核引用无效');
        }
    }

    /** @param string $id 不透明厂商引用 @return bool 有限、无控制字符的 UTF-8 标识 */
    public static function validId(string $id): bool
    {
        return trim($id) !== '' && mb_strlen($id) <= 128 && preg_match('//u', $id) === 1
            && !preg_match('/[\x00-\x1f\x7f]/', $id);
    }
}
