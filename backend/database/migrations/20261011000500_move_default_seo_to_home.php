<?php

declare(strict_types=1);
use Phinx\Migration\{AbstractMigration,IrreversibleMigrationException};

/** 默认SEO并入核心首页；保留已有逐页自定义值，去除独立默认设置字段。 */
final class MoveDefaultSeoToHome extends AbstractMigration
{
    public function up(): void
    {
        $pdo=$this->getAdapter()->getConnection();$prefix=$this->getAdapter()->getOption('table_prefix') ?? '';
        $q=fn(string $name):string=>$this->getAdapter()->quoteTableName($prefix.$name);
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $website=$pdo->query('SELECT settings FROM '.$q('website_settings').' WHERE id=1')->fetchColumn();
            $settings=json_decode($website,true,512,JSON_THROW_ON_ERROR);
            if (array_key_exists('seo_title',$settings)) {
                $statement=$pdo->prepare('SELECT * FROM '.$q('website_page_seo').' WHERE scope=? AND page_id=?');$statement->execute(['core','home']);$home=$statement->fetch(PDO::FETCH_ASSOC);$statement->closeCursor();
                $revisionDelta = $home || trim($settings['seo_title']) !== '' || trim($settings['seo_description']) !== '' || trim($settings['seo_keywords']) !== '' ? 1 : 0;
                $values=['title'=>$settings['seo_title'],'description'=>$settings['seo_description'],'keywords'=>$settings['seo_keywords'],'indexable'=>null];
                if ($home) foreach (array_keys($values) as $field) if ($field==='indexable' || trim($home[$field])!=='') $values[$field]=$home[$field];
                $now=date('Y-m-d H:i:s');
                if ($home) $pdo->prepare('UPDATE '.$q('website_page_seo').' SET title=?,description=?,keywords=?,indexable=?,updated_at=? WHERE scope=? AND page_id=?')->execute([...array_values($values),$now,'core','home']);
                else $pdo->prepare('INSERT INTO '.$q('website_page_seo').' (scope,page_id,title,description,keywords,indexable,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)')->execute(['core','home',...array_values($values),$now,$now]);
                unset($settings['seo_title'],$settings['seo_description'],$settings['seo_keywords']);
                $pdo->prepare('UPDATE '.$q('website_settings').' SET settings=?,revision=revision+' . $revisionDelta . ',updated_at=? WHERE id=1')->execute([json_encode($settings,JSON_THROW_ON_ERROR),$now]);
            }
            if ($ownTransaction) $pdo->commit();
        } catch (Throwable $error) { if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();throw $error; }
        $this->table('website_page_seo')
            ->changeColumn('title','string',['limit'=>120,'default'=>'','null'=>false,'comment'=>'页面标题正文；首页为副标题，留空使用页面默认名称'])
            ->changeColumn('description','string',['limit'=>500,'default'=>'','null'=>false,'comment'=>'逐页SEO描述，空白使用主题声明的页面描述，无值不输出'])
            ->changeColumn('keywords','string',['limit'=>255,'default'=>'','null'=>false,'comment'=>'逐页SEO关键词，空白使用主题声明关键词，无值不输出'])->update();
    }
    public function down(): void { throw new IrreversibleMigrationException('首页SEO合并后保留用户修改，请使用前向迁移恢复。'); }
}
