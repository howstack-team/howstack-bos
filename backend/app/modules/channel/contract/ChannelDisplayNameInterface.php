<?php

declare(strict_types=1);

namespace app\modules\channel\contract;

/** 只读取已保存通道的公开平台名称，不解密账号或访问外部平台。 */
interface ChannelDisplayNameInterface
{
    /**
     * 读取公开名称。
     * @param string $category 邮件或短信通道类别
     * @param int    $id       已保存通道标识
     * @return string 空值表示通道不存在或历史平台名称未记录
     */
    public function name(string $category, int $id): string;
}
