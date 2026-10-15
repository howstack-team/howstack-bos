<?php

declare(strict_types=1);

namespace app\modules\channel\contract\oauth;

/** 状态必须绑定浏览器并原子消费；不存放平台密钥或令牌。 */
interface OAuthStateStoreInterface
{
    /**
     * @param array{channel_id: int, revision: int, remember_me?: bool} $attempt 原通道快照
     * @param string $browserBinding 服务端会话随机绑定值
     * @return string 5 分钟一次性状态
     */
    public function issue(array $attempt, string $browserBinding): string;

    /**
     * @param string $state 返回状态
     * @param string $browserBinding 同一服务端会话绑定值
     * @return array{channel_id: int, revision: int, remember_me?: bool}|null 原子消费成功后的快照
     */
    public function consume(string $state, string $browserBinding): ?array;
}
