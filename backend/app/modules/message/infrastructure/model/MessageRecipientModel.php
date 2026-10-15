<?php

declare(strict_types=1);

namespace app\modules\message\infrastructure\model;

use support\Model;

/** 消息模块持久化模型；接口必须通过仓储显式映射。 */
final class MessageRecipientModel extends Model
{
    protected $table = 'message_recipients';
    public $timestamps = false;
    protected $fillable = ['message_id', 'audience', 'account_id', 'target_key', 'read_at', 'group_key', 'group_name'];
    protected $hidden = ['payload_encrypted', 'lease_key'];
    protected $casts = ['message_id' => 'integer', 'audience' => 'string', 'account_id' => 'integer', 'target_key' => 'string', 'read_at' => 'string', 'group_key' => 'string', 'group_name' => 'string'];
}
