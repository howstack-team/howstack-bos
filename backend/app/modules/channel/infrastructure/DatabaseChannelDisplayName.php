<?php

declare(strict_types=1);

namespace app\modules\channel\infrastructure;

use app\modules\channel\contract\ChannelDisplayNameInterface;
use app\modules\channel\infrastructure\model\ChannelConnectionModel;

/** 公开名称投影；停用或归档不改变已保存的平台身份。 */
final class DatabaseChannelDisplayName implements ChannelDisplayNameInterface
{
    /**
     * 仅从公开平台快照读取名称。
     * @param string $category 通道类别
     * @param int    $id       已保存通道标识
     * @return string 平台名称或空值
     */
    public function name(string $category, int $id): string
    {
        $row = ChannelConnectionModel::query()->where('category', $category)->whereKey($id)
            ->first(['id', 'platform_snapshot']);
        $name = $row?->platform_snapshot['name'] ?? '';

        return is_string($name) ? mb_substr($name, 0, 128) : '';
    }
}
