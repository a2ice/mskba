<?php
/**
 * Task 325: lossless quarantine of DEV accounts that collide with PROD
 * identity/contact keys. Operates ONLY on a restored, isolated candidate DB.
 *
 * The quarantined account is blocked and has no password/active conflicting
 * contacts until a future verified account-claim workflow resolves it.
 * The source account/contacts remain in an internal audit table.
 */
declare(strict_types=1);
if(getenv('MSKBA_MERGE_ISOLATED')!=='YES'
 || getenv('MSKBA_MERGE_DB_HOST')!=='candidate-db'
 || getenv('MSKBA_MERGE_DB_USER')!=='postgres'
 || !getenv('MSKBA_MERGE_DB_PASSWORD')){
 throw new RuntimeException('Only isolated candidate is allowed');
}
$apply=in_array('--apply',$argv,true);
$pw=getenv('MSKBA_MERGE_DB_PASSWORD');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$src=new PDO('pgsql:host=candidate-db;dbname=dev_snapshot','postgres',$pw,$options);
$dst=new PDO('pgsql:host=candidate-db;dbname=candidate','postgres',$pw,$options);
$src->exec('SET default_transaction_read_only=on');
$dst->beginTransaction();
function readMany(PDO $pdo,string $sql,array $args=[]):array{
 $stmt=$pdo->prepare($sql);$stmt->execute($args);return $stmt->fetchAll();
}
function save(PDO $db,string $table,array $data):int{
 if(!preg_match('/^[a-z_]+$/',$table))throw new RuntimeException('Invalid table');
 unset($data['id']);
 $columns=array_keys($data);
 foreach($columns as $c)if(!preg_match('/^[a-z_]+$/',$c))throw new RuntimeException('Invalid column');
 $cols=implode(',',array_map(static fn($s)=>'"'.$s.'"',$columns));
 $p=implode(',',array_fill(0,count($columns),'?'));
 $stmt=$db->prepare('INSERT INTO "'.$table.'" ('.$cols.') VALUES ('.$p.') RETURNING id');
 foreach(array_values($data) as $i=>$value){
  $type=match(true){$value===null=>PDO::PARAM_NULL,is_bool($value)=>PDO::PARAM_BOOL,is_int($value)=>PDO::PARAM_INT,default=>PDO::PARAM_STR};
  $stmt->bindValue($i+1,$value,$type);
 }
 $stmt->execute();return (int)$stmt->fetchColumn();
}
try{
 $dst->exec("CREATE TABLE IF NOT EXISTS data_merge_quarantined_user_origin (
   source_user_id bigint PRIMARY KEY,
   target_user_id bigint NOT NULL REFERENCES users(id),
   origin jsonb NOT NULL,
   created_at timestamptz DEFAULT now())");
 $dst->exec("CREATE TABLE IF NOT EXISTS data_merge_quarantined_contacts (
   source_contact_id bigint PRIMARY KEY,
   source_user_id bigint NOT NULL,
   origin jsonb NOT NULL)");
 $unresolved=readMany($dst,"SELECT source_user_id FROM data_merge_user_map
    WHERE resolution='pending_identity' AND target_user_id IS NULL ORDER BY source_user_id");
 $users=[];
 foreach($unresolved as $item){
  $sourceId=(int)$item['source_user_id'];
  $rows=readMany($src,'SELECT * FROM users WHERE id=?',[$sourceId]);
  if(count($rows)!==1)throw new RuntimeException('Missing source identity');
  $original=$rows[0];$data=$original;
  unset($data['id']);
  $data['username']='dev-import-unverified-'.$sourceId;
  $data['password']=null;
  $data['remember_token']=null;
  $data['status']='blocked';
  $data['system_role']='user';
  $data['staff_title']=null;
  $data['canonical_user_id']=null;
  $data['design_theme_id']=null;
  if(readMany($dst,'SELECT id FROM users WHERE username=?',[$data['username']])!==[]){
   throw new RuntimeException('Quarantine namespace collision');
  }
  $targetId=save($dst,'users',$data);
  $stmt=$dst->prepare('INSERT INTO data_merge_quarantined_user_origin
    (source_user_id,target_user_id,origin) VALUES(?,?,CAST(? AS jsonb))');
  $stmt->execute([$sourceId,$targetId,json_encode($original,JSON_THROW_ON_ERROR)]);
  $dst->prepare("UPDATE data_merge_user_map
    SET target_user_id=?,resolution='quarantined'
    WHERE source_user_id=?")->execute([$targetId,$sourceId]);
  $users[$sourceId]=$targetId;
 }
 $userMap=[];
 foreach(readMany($dst,'SELECT source_user_id,target_user_id FROM data_merge_user_map') as $x){
  $userMap[(int)$x['source_user_id']]=$x['target_user_id']===null?null:(int)$x['target_user_id'];
 }
 $copied=0;$deferredContacts=0;
 foreach($users as $sid=>$tid){
  foreach(['profiles','player_profiles','user_privacy_settings','user_consents','user_participation_roles'] as $table){
   foreach(readMany($src,'SELECT * FROM '.$table.' WHERE user_id=? ORDER BY id',[$sid]) as $row){
    $row['user_id']=$tid;
    if($table==='user_participation_roles' && $row['assigned_by']!==null){
     $row['assigned_by']=$userMap[(int)$row['assigned_by']]??null;
    }
    save($dst,$table,$row);$copied++;
   }
  }
  foreach(readMany($src,"SELECT * FROM contacts WHERE contactable_type='user' AND contactable_id=? ORDER BY id",[$sid]) as $contact){
   $stmt=$dst->prepare('INSERT INTO data_merge_quarantined_contacts
     (source_contact_id,source_user_id,origin) VALUES(?,?,CAST(? AS jsonb))');
   $stmt->execute([(int)$contact['id'],$sid,json_encode($contact,JSON_THROW_ON_ERROR)]);
   $deferredContacts++;
  }
 }
 $pendingActors=readMany($dst,"SELECT source_actor_id FROM data_merge_actor_map
   WHERE resolution='pending_owner' ORDER BY source_actor_id");
 $mappedActors=0;
 foreach($pendingActors as $item){
  $sourceActorId=(int)$item['source_actor_id'];
  $a=readMany($src,'SELECT * FROM actors WHERE id=?',[$sourceActorId]);
  if(count($a)!==1)throw new RuntimeException('Source actor missing');
  $actor=$a[0];$type=(string)$actor['type'];
  if($type==='user'){
    if($actor['user_id']===null)throw new RuntimeException('User actor missing principal');
    $user=$userMap[(int)$actor['user_id']]??null;
    if($user===null)throw new RuntimeException('Unresolved user actor');
    $id=save($dst,'actors',[
     'actor_key'=>'user:imported:dev:'.$sourceActorId,
     'type'=>'user','user_id'=>$user,'user_fingerprint_id'=>null,
     'created_at'=>$actor['created_at'],'updated_at'=>$actor['updated_at'],
    ]);
  }elseif($type==='system'){
    $key='system:user:none:fingerprint:none';
    $existing=readMany($dst,'SELECT id FROM actors WHERE actor_key=?',[$key]);
    $id=$existing!==[]?(int)$existing[0]['id']:save($dst,'actors',[
     'actor_key'=>$key,'type'=>'system','user_id'=>null,'user_fingerprint_id'=>null,
     'created_at'=>$actor['created_at'],'updated_at'=>$actor['updated_at'],
    ]);
  }elseif($type==='guest'){
    $id=save($dst,'actors',[
     'actor_key'=>'guest:imported:dev:'.$sourceActorId,
     'type'=>'guest','user_id'=>null,'user_fingerprint_id'=>null,
     'created_at'=>$actor['created_at'],'updated_at'=>$actor['updated_at'],
    ]);
  }else throw new RuntimeException('Unknown actor type');
  $dst->prepare("UPDATE data_merge_actor_map
   SET target_actor_id=?,resolution='quarantined_actor'
   WHERE source_actor_id=?")->execute([$id,$sourceActorId]);
  $mappedActors++;
 }
 if(readMany($dst,'SELECT source_actor_id FROM data_merge_actor_map WHERE target_actor_id IS NULL')!==[]){
  throw new RuntimeException('Unresolved actor mappings remain');
 }
 if($apply)$dst->commit();else $dst->rollBack();
 echo 'QUARANTINE_IMPORT_ISOLATED=true'.PHP_EOL;
 echo 'quarantine_apply='.($apply?'yes':'no').PHP_EOL;
 echo 'quarantined_accounts='.count($users).PHP_EOL;
 echo 'quarantined_profile_and_role_rows='.$copied.PHP_EOL;
 echo 'quarantined_contact_records='.$deferredContacts.PHP_EOL;
 echo 'resolved_pending_actor_mappings='.$mappedActors.PHP_EOL;
 echo 'PROD_CREDENTIALS_UNCHANGED=true'.PHP_EOL;
}catch(Throwable $error){
 if($dst->inTransaction())$dst->rollBack();
 fwrite(STDERR,'QUARANTINE_BLOCKED: '.get_class($error).PHP_EOL);
 exit(1);
}
