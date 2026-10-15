<?php

declare(strict_types=1);
use Phinx\Migration\{AbstractMigration,IrreversibleMigrationException};

/** 统一已登记上传，不扫描存储桶；迁入后原地址仍可访问。 */
final class CreateFileLibrary extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('file_assets')) {
            $table=$this->table('file_assets',['id'=>false,'primary_key'=>['id'],'comment'=>'统一上传文件元数据及存储生命周期']);
            foreach (['id'=>[32,'公开文件随机标识'], 'original_name'=>[200,'原始文件名；历史未记录时为NULL'], 'extension'=>[20,'文件扩展名，不含点'], 'type'=>[16,'文件分类：image图片document文档video视频audio音频other其他'], 'namespace'=>[16,'稳定访问地址归属：website/content/files'], 'visibility'=>[16,'公开范围：public公开素材private私密附件'], 'origin'=>[16,'上传来源：website官网help帮助news公告content历史正文library文件库'], 'mime_type'=>[128,'服务端确认的MIME类型'], 'object_key'=>[512,'存储对象键，禁止客户端指定或公开返回'], 'status'=>[20,'状态：pending/stored/failed/unknown/deleting/delete_failed/deleted']] as $field=>[$limit,$comment]) $table->addColumn($field,'string',['limit'=>$limit,'null'=>$field==='original_name','comment'=>$comment]);
            foreach (['channel_id'=>'上传时存储通道ID','channel_revision'=>'上传时存储配置修订','created_by'=>'上传管理员ID'] as $field=>$comment) $table->addColumn($field,'integer',['null'=>false,'signed'=>false,'comment'=>$comment]);
            $table->addColumn('byte_size','biginteger',['signed'=>false,'null'=>false,'comment'=>'文件大小，单位字节'])
                ->addColumn('created_at','datetime',['null'=>false,'comment'=>'上传登记时间'])
                ->addColumn('deleted_at','datetime',['null'=>true,'default'=>null,'comment'=>'对象确认删除时间，NULL表示尚未确认删除'])
                ->addIndex(['visibility','created_at'],['name'=>'idx_files_visible_time'])->addIndex(['type','status'],['name'=>'idx_files_type_state'])->create();
        }
        if (!$this->hasTable('file_references')) $this->table('file_references',['id'=>false,'primary_key'=>['file_id','scope','owner'],'comment'=>'业务对文件的引用，阻止删除仍在使用的文件'])
            ->addColumn('file_id','string',['limit'=>32,'null'=>false,'comment'=>'统一文件标识'])
            ->addColumn('scope','string',['limit'=>16,'null'=>false,'comment'=>'使用范围：website官网theme主题content正文preview独立预览'])
            ->addColumn('owner','string',['limit'=>64,'null'=>false,'comment'=>'业务对象标识或预览摘要'])
            ->addColumn('label','string',['limit'=>200,'null'=>false,'comment'=>'引用位置展示名称'])
            ->addColumn('expires_at','datetime',['null'=>true,'default'=>null,'comment'=>'临时引用过期时间；永久引用为NULL'])
            ->addIndex(['scope','owner'],['name'=>'idx_files_reference_owner'])->create();
        $pdo=$this->getAdapter()->getConnection(); $prefix=$this->getAdapter()->getOption('table_prefix') ?? '';
        $q=fn(string $name):string=>$this->getAdapter()->quoteTableName($prefix.$name);
        foreach (['website_images'=>'website','content_images'=>'content'] as $old=>$namespace) {
            if (!$this->hasTable($old)) continue;
            $rows=$pdo->query('SELECT * FROM '.$q($old));
            while ($row=$rows->fetch(PDO::FETCH_ASSOC)) {
                $existing=$pdo->prepare('SELECT object_key,namespace FROM '.$q('file_assets').' WHERE id=?');$existing->execute([$row['id']]);$prior=$existing->fetch(PDO::FETCH_ASSOC);$existing->closeCursor();
                if ($prior && ($prior['object_key']!==$row['object_key'] || $prior['namespace']!==$namespace)) throw new RuntimeException('历史文件标识冲突，保留原表并停止迁移');
                $columns=['id','original_name','extension','type','namespace','visibility','origin','mime_type','byte_size','object_key','channel_id','channel_revision','created_by','status','created_at'];
                $values=[$row['id'],null,pathinfo($row['object_key'],PATHINFO_EXTENSION),'image',$namespace,'public',$namespace,$row['mime_type'],$row['byte_size'],$row['object_key'],$row['channel_id'],$row['channel_revision'],$row['created_by'],$row['status'],$row['created_at']];
                $query=$pdo->prepare('INSERT INTO '.$q('file_assets').' ('.implode(',',$columns).') SELECT '.implode(',',array_fill(0,count($columns),'?')).' WHERE NOT EXISTS (SELECT 1 FROM '.$q('file_assets').' WHERE id=?)');$query->execute([...$values,$row['id']]);
            }
            $rows->closeCursor();
        }
        // 历史正文、官网和全部已安装主题均回填引用，停用内容也保护。
        foreach (['website_settings'=>['website','id','settings'], 'website_theme_settings'=>['theme','theme_id','settings'], 'content_entries'=>['content','id','body']] as $table=>[$scope,$key,$value]) {
            foreach ($pdo->query('SELECT * FROM '.$q($table))->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $content=$value==='body' ? $row[$value] : json_decode($row[$value],true,512,JSON_THROW_ON_ERROR);
                foreach (\app\modules\file\domain\FileReferences::collect($content) as $id) {
                    $label=$scope==='content' ? $row['title'] : ($scope==='theme' ? '主题：'.$row[$key] : '官网基础设置');
                    $sql='INSERT INTO '.$q('file_references').' (file_id,scope,owner,label) SELECT ?,?,?,? WHERE EXISTS (SELECT 1 FROM '.$q('file_assets').' WHERE id=?) AND NOT EXISTS (SELECT 1 FROM '.$q('file_references').' WHERE file_id=? AND scope=? AND owner=?)';
                    $pdo->prepare($sql)->execute([$id,$scope,(string)$row[$key],mb_substr($label,0,200),$id,$id,$scope,(string)$row[$key]]);
                }
            }
        }
        foreach (['website_images','content_images'] as $old) if ($this->hasTable($old)) $this->table($old)->drop()->save();
    }
    public function down(): void { throw new IrreversibleMigrationException('文件统一迁移不可回滚，请从备份或前向迁移恢复。'); }
}
