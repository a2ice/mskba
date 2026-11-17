<?php
/**
 * Task 325. News/FAQ/tags from dev, preserving existing prod URLs and articles.
 * Unresolvable owner/entity links are retained in private conflict provenance.
 * Rehearsal only, disposable PostgreSQL without live network access.
 */
declare(strict_types=1);
if(getenv('MSKBA_MERGE_ISOLATED')!=='YES'
||getenv('MSKBA_MERGE_DB_HOST')!=='candidate-db'
||getenv('MSKBA_MERGE_DB_USER')!=='postgres'
||!getenv('MSKBA_MERGE_DB_PASSWORD'))throw new RuntimeException('Isolated-only');
$apply=in_array('--apply',$argv,true);
$pw=getenv('MSKBA_MERGE_DB_PASSWORD');
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$s=new PDO('pgsql:host=candidate-db;dbname=dev_snapshot','postgres',$pw,$opts);
$t=new PDO('pgsql:host=candidate-db;dbname=candidate','postgres',$pw,$opts);
$s->exec('SET default_transaction_read_only=on');$t->beginTransaction();
function many(PDO $db,string $sql,array $p=[]):array{
 $q=$db->prepare($sql);$q->execute($p);return $q->fetchAll();
}
function stmt(PDO $db,string $sql,array $p=[]):void{
 $q=$db->prepare($sql);
 foreach(array_values($p) as $i=>$v)$q->bindValue($i+1,$v,match(true){$v===null=>PDO::PARAM_NULL,is_bool($v)=>PDO::PARAM_BOOL,is_int($v)=>PDO::PARAM_INT,default=>PDO::PARAM_STR});
 $q->execute();
}
function insert(PDO $db,string $table,array $row):int{
 if(!preg_match('/^[a-z_]+$/',$table))throw new RuntimeException('Unsafe table');
 unset($row['id']);$cols=array_keys($row);
 foreach($cols as $c)if(!preg_match('/^[a-z_]+$/',$c))throw new RuntimeException('Unsafe col');
 $q=$db->prepare('INSERT INTO '.$table.' ('.implode(',',$cols).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).') RETURNING id');
 foreach(array_values($row) as $i=>$v)$q->bindValue($i+1,$v,match(true){$v===null=>PDO::PARAM_NULL,is_bool($v)=>PDO::PARAM_BOOL,is_int($v)=>PDO::PARAM_INT,default=>PDO::PARAM_STR});
 $q->execute();return (int)$q->fetchColumn();
}
function sourceMap(PDO $db,string $table,string $src,string $dst):array {
 $result=[];
 foreach(many($db,'SELECT '.$src.','.$dst.' FROM '.$table) as $r){
  if($r[$dst]!==null)$result[(int)$r[$src]]=(int)$r[$dst];
 }
 return $result;
}
function need(array $map,int $src,string $purpose):int{
 if(!isset($map[$src]))throw new RuntimeException('Missing '.$purpose.' map');
 return $map[$src];
}
try{
 $users=sourceMap($t,'data_merge_user_map','source_user_id','target_user_id');
 $venues=sourceMap($t,'data_merge_venue_map','source_venue_id','target_venue_id');
 $teams=[];
 foreach(many($t,"SELECT source_id,target_id FROM data_merge_media_mappings WHERE kind='team'") as $row)
  $teams[(int)$row['source_id']]=(int)$row['target_id'];
 $t->exec("CREATE TABLE IF NOT EXISTS data_merge_content_map(
   source_item_id bigint PRIMARY KEY,
   target_item_id bigint NOT NULL REFERENCES content_items(id),
   resolution varchar(30) NOT NULL)");
 $t->exec("CREATE TABLE IF NOT EXISTS data_merge_content_conflicts(
   source_item_id bigint PRIMARY KEY,
   original jsonb NOT NULL,
   reason varchar(40) NOT NULL)");
 $t->exec("CREATE TABLE IF NOT EXISTS data_merge_content_unresolved_related(
   source_item_id bigint PRIMARY KEY,
   original_type varchar(50),original_id bigint)");
 $tagMap=[];
 foreach(many($s,'SELECT * FROM content_tags ORDER BY id') as $tag){
  $same=many($t,'SELECT id FROM content_tags WHERE normalized_name=?',[$tag['normalized_name']]);
  $id=$same!==[]?(int)$same[0]['id']:insert($t,'content_tags',$tag);
  $tagMap[(int)$tag['id']]=$id;
 }
 $sections=[];
 $targetSections=[];
 foreach(many($t,'SELECT id,code FROM faq_sections') as $x)$targetSections[$x['code']]=(int)$x['id'];
 foreach(many($s,'SELECT id,code FROM faq_sections') as $x){
  if(!isset($targetSections[$x['code']]))throw new RuntimeException('Missing FAQ section key');
  $sections[(int)$x['id']]=$targetSections[$x['code']];
 }
 $map=[];$new=0;$existing=0;$linkUnresolved=0;
 foreach(many($s,'SELECT * FROM content_items ORDER BY id') as $row){
  $sourceId=(int)$row['id'];
  $same=many($t,'SELECT id FROM content_items WHERE alias=?',[$row['alias']]);
  if(count($same)>1)throw new RuntimeException('Ambiguous content alias');
  if($same!==[]){
   $id=(int)$same[0]['id'];$existing++;
   stmt($t,'INSERT INTO data_merge_content_conflicts(source_item_id,original,reason)
     VALUES(?,CAST(? AS jsonb),?) ON CONFLICT DO NOTHING',
     [$sourceId,json_encode($row,JSON_THROW_ON_ERROR),'prod_alias_precedence']);
   $reason='preserve_prod';
  }else{
   $row['created_by_user_id']=need($users,(int)$row['created_by_user_id'],'content author');
   if($row['updated_by_user_id']!==null)
    $row['updated_by_user_id']=need($users,(int)$row['updated_by_user_id'],'content editor');
   if($row['related_id']!==null&&$row['related_type']!==null){
    $oldRelated=(int)$row['related_id'];
    $mapped=match($row['related_type']){
     'venue'=>$venues[$oldRelated]??null,
     'team'=>$teams[$oldRelated]??null,
     default=>null,
    };
    if($mapped===null){
     stmt($t,'INSERT INTO data_merge_content_unresolved_related(source_item_id,original_type,original_id)
       VALUES(?,?,?) ON CONFLICT DO NOTHING',[$sourceId,$row['related_type'],$oldRelated]);
     $row['related_id']=null;$row['related_type']=null;$linkUnresolved++;
    }else $row['related_id']=$mapped;
   }
   // Prevent a staging publication from notifying production Telegram.
   $row['publish_in_telegram']=false;
   $id=insert($t,'content_items',$row);$new++;$reason='imported';
  }
  $map[$sourceId]=$id;
  stmt($t,'INSERT INTO data_merge_content_map(source_item_id,target_item_id,resolution)
      VALUES(?,?,?) ON CONFLICT(source_item_id)
      DO UPDATE SET target_item_id=EXCLUDED.target_item_id,resolution=EXCLUDED.resolution',
      [$sourceId,$id,$reason]);
 }
 $tagsCopied=0;
 foreach(many($s,'SELECT * FROM content_item_tag') as $row){
  $item=need($map,(int)$row['content_item_id'],'content');
  $tag=need($tagMap,(int)$row['content_tag_id'],'content tag');
  if(many($t,'SELECT 1 FROM content_item_tag WHERE content_item_id=? AND content_tag_id=?',[$item,$tag])!==[])continue;
  stmt($t,'INSERT INTO content_item_tag(content_item_id,content_tag_id) VALUES (?,?)',[$item,$tag]);
  $tagsCopied++;
 }
 $faqCopied=0;
 foreach(many($s,'SELECT * FROM content_item_faq_section') as $row){
  $item=need($map,(int)$row['content_item_id'],'faq item');
  $section=need($sections,(int)$row['faq_section_id'],'faq section');
  if(many($t,'SELECT 1 FROM content_item_faq_section WHERE content_item_id=? AND faq_section_id=?',[$item,$section])!==[])continue;
  stmt($t,'INSERT INTO content_item_faq_section(content_item_id,faq_section_id) VALUES (?,?)',[$item,$section]);
  $faqCopied++;
 }
 if($apply)$t->commit();else $t->rollBack();
 echo 'CONTENT_IMPORT_ISOLATED=true'.PHP_EOL;
 echo 'content_apply='.($apply?'yes':'no').PHP_EOL;
 echo 'new_content_items='.$new.PHP_EOL;
 echo 'prod_aliases_preserved='.$existing.PHP_EOL;
 echo 'content_tags_mapped='.count($tagMap).PHP_EOL;
 echo 'item_tag_links_copied='.$tagsCopied.PHP_EOL;
 echo 'faq_links_copied='.$faqCopied.PHP_EOL;
 echo 'unresolved_related_links='.$linkUnresolved.PHP_EOL;
}catch(Throwable $error){
 if($t->inTransaction())$t->rollBack();
 fwrite(STDERR,'CONTENT_IMPORT_BLOCKED: '.get_class($error).PHP_EOL);
 exit(1);
}
