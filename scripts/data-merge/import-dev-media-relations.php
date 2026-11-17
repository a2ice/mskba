<?php
/**
 * Task 325. Import DEV media records, Site Media library and league/team
 * ownership references into a disposable post-shop, post-quarantine candidate.
 * File bytes are copied separately from SHA-verified storage/app union.
 */
declare(strict_types=1);
if(getenv('MSKBA_MERGE_ISOLATED')!=='YES'
  ||getenv('MSKBA_MERGE_DB_HOST')!=='candidate-db'
  ||getenv('MSKBA_MERGE_DB_USER')!=='postgres'
  ||!getenv('MSKBA_MERGE_DB_PASSWORD')) {
    throw new RuntimeException('Not an isolated candidate');
}
$apply=in_array('--apply',$argv,true);
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$src=new PDO('pgsql:host=candidate-db;dbname=dev_snapshot','postgres',getenv('MSKBA_MERGE_DB_PASSWORD'),$opts);
$dst=new PDO('pgsql:host=candidate-db;dbname=candidate','postgres',getenv('MSKBA_MERGE_DB_PASSWORD'),$opts);
$src->exec('SET default_transaction_read_only=on');
$dst->beginTransaction();
function rows(PDO $db,string $sql,array $args=[]):array {
 $s=$db->prepare($sql);$s->execute($args);return $s->fetchAll();
}
function key(string $name):string {
 if(!preg_match('/^[a-z][a-z0-9_]*$/',$name))throw new RuntimeException('Invalid SQL name');
 return '"'.$name.'"';
}
function put(PDO $db,string $table,array $row):int {
 unset($row['id']);$cols=array_keys($row);
 $q=$db->prepare('INSERT INTO '.key($table).' ('.implode(',',array_map('key',$cols)).
   ') VALUES ('.implode(',',array_fill(0,count($cols),'?')).') RETURNING id');
 foreach(array_values($row) as $i=>$value){
  $q->bindValue($i+1,$value,match(true){
    $value===null=>PDO::PARAM_NULL,is_bool($value)=>PDO::PARAM_BOOL,
    is_int($value)=>PDO::PARAM_INT,default=>PDO::PARAM_STR
  });
 }
 $q->execute();return (int)$q->fetchColumn();
}
function required(array $map,int $sourceId,string $type):int {
 if(!isset($map[$sourceId]))throw new RuntimeException('Missing '.$type.' identity map');
 return (int)$map[$sourceId];
}
function mapRows(PDO $db,string $table,string $old,string $new):array {
 $map=[];
 foreach(rows($db,'SELECT '.key($old).','.key($new).' FROM '.key($table)) as $r){
  if($r[$new]!==null)$map[(int)$r[$old]]=(int)$r[$new];
 }
 return $map;
}
function mapping(PDO $db,string $type,int $old,int $new):void {
 $stmt=$db->prepare('INSERT INTO data_merge_media_mappings (kind,source_id,target_id)
     VALUES(?,?,?) ON CONFLICT(kind,source_id) DO UPDATE SET target_id=EXCLUDED.target_id');
 $stmt->execute([$type,$old,$new]);
}
try{
 $needed=['data_merge_user_map','data_merge_actor_map','data_merge_venue_map','data_merge_court_map'];
 foreach($needed as $table){
  if(rows($dst,'SELECT to_regclass(?) AS t',['public.'.$table])[0]['t']===null)
    throw new RuntimeException('Missing required earlier map');
 }
 $userMap=mapRows($dst,'data_merge_user_map','source_user_id','target_user_id');
 $actorMap=mapRows($dst,'data_merge_actor_map','source_actor_id','target_actor_id');
 $venueMap=mapRows($dst,'data_merge_venue_map','source_venue_id','target_venue_id');
 $courtMap=mapRows($dst,'data_merge_court_map','source_court_id','target_court_id');
 $dst->exec("CREATE TABLE IF NOT EXISTS data_merge_media_mappings(
  kind varchar(40) NOT NULL,source_id bigint NOT NULL,target_id bigint NOT NULL,
  PRIMARY KEY(kind,source_id))");
 $profileMap=[];
 foreach(rows($src,'SELECT id,user_id FROM profiles') as $p){
  $user=required($userMap,(int)$p['user_id'],'profile owner');
  $matches=rows($dst,'SELECT id FROM profiles WHERE user_id=?',[$user]);
  if(count($matches)!==1)throw new RuntimeException('Missing target profile');
  $profileMap[(int)$p['id']]=(int)$matches[0]['id'];
 }
 $teamMap=[];
 foreach(rows($src,'SELECT * FROM teams WHERE deleted_at IS NULL ORDER BY id') as $t){
  $matches=rows($dst,'SELECT id,name FROM teams WHERE alias=? AND deleted_at IS NULL',[$t['alias']]);
  if(count($matches)!==1 || mb_strtolower(trim($matches[0]['name']))!==mb_strtolower(trim($t['name']))) {
    throw new RuntimeException('Team requires explicit match');
  }
  $teamMap[(int)$t['id']]=(int)$matches[0]['id'];
  mapping($dst,'team',(int)$t['id'],$teamMap[(int)$t['id']]);
 }
 $leagueMap=[];
 foreach(rows($src,'SELECT * FROM leagues ORDER BY id') as $r){
  $old=(int)$r['id'];
  $exists=rows($dst,'SELECT id FROM leagues WHERE alias=?',[$r['alias']]);
  if($exists!==[])throw new RuntimeException('League alias conflict');
  $r['owner_user_id']=required($userMap,(int)$r['owner_user_id'],'league owner');
  if($r['created_by_actor_id']!==null)$r['created_by_actor_id']=required($actorMap,(int)$r['created_by_actor_id'],'league actor');
  $id=put($dst,'leagues',$r);
  $leagueMap[$old]=$id;
  mapping($dst,'league',$old,$id);
 }
 $seasonMap=[];
 foreach(rows($src,'SELECT * FROM league_seasons ORDER BY id') as $r){
  $old=(int)$r['id'];
  $r['league_id']=required($leagueMap,(int)$r['league_id'],'league season');
  $id=put($dst,'league_seasons',$r);
  $seasonMap[$old]=$id;
  mapping($dst,'league_season',$old,$id);
 }
 foreach(['league_team'=>['league_id'=>'league','team_id'=>'team'],
          'league_season_team'=>['league_season_id'=>'season','team_id'=>'team']] as $table=>$keys){
  foreach(rows($src,'SELECT * FROM '.$table.' ORDER BY id') as $row){
   foreach($keys as $column=>$type){
    $row[$column]=required(match($type){
      'league'=>$leagueMap,'team'=>$teamMap,'season'=>$seasonMap
    },(int)$row[$column],$type);
   }
   put($dst,$table,$row);
  }
 }

 $folderMap=[];
 foreach(rows($src,'SELECT * FROM site_media_folders ORDER BY id') as $folder){
  $old=(int)$folder['id'];
  $same=rows($dst,'SELECT id FROM site_media_folders WHERE slug=?',[$folder['slug']]);
  $id=$same!==[]?(int)$same[0]['id']:put($dst,'site_media_folders',$folder);
  $folderMap[$old]=$id;mapping($dst,'site_media_folder',$old,$id);
 }
 $assetMap=[];
 $assets=rows($src,'SELECT * FROM site_media_assets ORDER BY id');
 foreach($assets as $asset){
  $old=(int)$asset['id'];
  $asset['folder_id']=$asset['folder_id']===null?null:required($folderMap,(int)$asset['folder_id'],'asset folder');
  $asset['created_by']=$asset['created_by']===null?null:required($userMap,(int)$asset['created_by'],'asset author');
  $asset['current_media_id']=null;
  $asset['previous_media_id']=null;
  if(rows($dst,'SELECT id FROM site_media_assets WHERE uuid=? OR legacy_path=?',[$asset['uuid'],$asset['legacy_path']])!==[]){
   throw new RuntimeException('Site media asset identity collision');
  }
  $id=put($dst,'site_media_assets',$asset);
  $assetMap[$old]=$id;mapping($dst,'site_media_asset',$old,$id);
 }
 $maps=[
  'profile'=>$profileMap,'site_media_asset'=>$assetMap,
  'venue'=>$venueMap,'venue_court'=>$courtMap,'team'=>$teamMap,
  'league'=>$leagueMap,'league_season'=>$seasonMap,
 ];
 $allSource=rows($src,'SELECT * FROM media ORDER BY id');
 $counts=[];$mediaMap=[];
 foreach($allSource as $m){
  $old=(int)$m['id'];
  $type=(string)$m['mediable_type'];
  if(!isset($maps[$type]))throw new RuntimeException('Media owner type not yet supported: '.$type);
  $targetOwner=required($maps[$type],(int)$m['mediable_id'],'media '.$type);
  if(!in_array((string)$m['disk'],['public','local'],true)){
    throw new RuntimeException('External media disk not in protected storage archive');
  }
  if($m['original_path']!==null && $m['original_disk']!==null &&
    !in_array((string)$m['original_disk'],['public','local'],true)){
    throw new RuntimeException('External original media source unavailable');
  }
  $m['mediable_id']=$targetOwner;
  $m['is_featured']=in_array($m['is_featured'],[true,1,'1','t','true'],true);
  $existing=rows($dst,"SELECT id FROM media
    WHERE mediable_type=? AND mediable_id=? AND disk=? AND path=? AND deleted_at IS NULL",
    [$type,$targetOwner,$m['disk'],$m['path']]);
  if(count($existing)>1)throw new RuntimeException('Duplicate target media attachment');
  if($existing!==[]){
    $id=(int)$existing[0]['id'];
  }else{
    // Production featured image for a pre-existing entity is preferred.
    if($m['is_featured']){
      $featured=rows($dst,"SELECT id FROM media WHERE mediable_type=? AND
        mediable_id=? AND collection=? AND is_featured=true AND deleted_at IS NULL",
        [$type,$targetOwner,$m['collection']]);
      if($featured!==[])$m['is_featured']=false;
    }
    $id=put($dst,'media',$m);
  }
  $mediaMap[$old]=$id;mapping($dst,'media',$old,$id);
  $counts[$type]=($counts[$type]??0)+1;
 }
 foreach($assets as $sourceAsset){
  $targetId=required($assetMap,(int)$sourceAsset['id'],'site media');
  $current=$sourceAsset['current_media_id'];
  $previous=$sourceAsset['previous_media_id'];
  $dst->prepare('UPDATE site_media_assets
   SET current_media_id=?,previous_media_id=? WHERE id=?')->execute([
   $current===null?null:required($mediaMap,(int)$current,'current media'),
   $previous===null?null:required($mediaMap,(int)$previous,'previous media'),
   $targetId,
  ]);
 }
 if(count($mediaMap)!==count($allSource) || count($assetMap)!==count($assets)){
  throw new RuntimeException('Incomplete asset or media transfer');
 }
 if($apply)$dst->commit();else $dst->rollBack();
 echo 'MEDIA_RELATIONS_ISOLATED=true'.PHP_EOL;
 echo 'media_apply='.($apply?'yes':'no').PHP_EOL;
 echo 'source_media_count='.count($allSource).PHP_EOL;
 echo 'mapped_site_media_assets='.count($assetMap).PHP_EOL;
 echo 'mapped_leagues='.count($leagueMap).PHP_EOL;
 echo 'mapped_teams='.count($teamMap).PHP_EOL;
 foreach($counts as $type=>$count)echo 'media_'.$type.'='.$count.PHP_EOL;
 echo 'PROD_FEATURED_PHOTOS_PRESERVED=true'.PHP_EOL;
}catch(Throwable $error){
 if($dst->inTransaction())$dst->rollBack();
 fwrite(STDERR,'MEDIA_RELATIONS_BLOCKED: '.get_class($error).PHP_EOL);
 exit(1);
}
