<?php
/**
 * Task 325. Recreate only provable catalogue management rights for newly
 * imported DEV venues and leagues, following the existing owner backfill.
 * Does NOT claim/transfer commercial ownership or dev rental/payment rights.
 */
declare(strict_types=1);
if(getenv('MSKBA_MERGE_ISOLATED')!=='YES'
 ||getenv('MSKBA_MERGE_DB_HOST')!=='candidate-db'
 ||getenv('MSKBA_MERGE_DB_USER')!=='postgres'
 ||!getenv('MSKBA_MERGE_DB_PASSWORD')) {
 throw new RuntimeException('Must target isolated test database');
}
$apply=in_array('--apply',$argv,true);
$pdo=new PDO('pgsql:host=candidate-db;dbname=candidate','postgres',
 getenv('MSKBA_MERGE_DB_PASSWORD'),[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false,
]);
$pdo->beginTransaction();
function query(PDO $pdo,string $sql,array $bind=[]):array {
 $p=$pdo->prepare($sql);$p->execute($bind);return $p->fetchAll();
}
function execute(PDO $pdo,string $sql,array $bind=[]):void{
 $p=$pdo->prepare($sql);$p->execute($bind);
}
try{
 $items=query($pdo, "SELECT 'venue' AS scope_type,v.id AS scope_id,v.name AS title,
 a.user_id AS owner_user_id,u.status AS owner_status
 FROM data_merge_venue_map map
 JOIN venues v ON v.id=map.target_venue_id
 LEFT JOIN actors a ON a.id=v.created_by_actor_id
 LEFT JOIN users u ON u.id=a.user_id
 WHERE map.resolution='new_venue'
 UNION ALL
 SELECT 'league' AS scope_type,l.id AS scope_id,l.name AS title,
 l.owner_user_id,u.status
 FROM data_merge_media_mappings map
 JOIN leagues l ON l.id=map.target_id
 JOIN users u ON u.id=l.owner_user_id
 WHERE map.kind='league'");
 $permissions=[
  'venue'=>['view','edit','edit.schedule','remove','venue.contracts.manage'],
  'league'=>['league.edit','league.teams.manage','league.seasons.manage',
    'league.tournaments.manage','league.contracts.manage','league.archive']
 ];
 $created=['venue'=>0,'league'=>0];
 $preserved=0;$unassigned=0;
 foreach($items as $item){
   $scope=(string)$item['scope_type'];
   $id=(int)$item['scope_id'];
   $existing=query($pdo,"SELECT m.id FROM contract_memberships m
     JOIN contracts c ON c.id=m.contract_id
     WHERE m.scope_type=? AND m.scope_id=?
       AND m.access_level IN ('owner','primary_manager')
       AND c.status='active' LIMIT 1",[$scope,$id]);
   if($existing!==[]){$preserved++;continue;}
   if($item['owner_user_id']===null || $item['owner_status']!=='confirmed'){
     $unassigned++;continue;
   }
   $owner=(int)$item['owner_user_id'];
   $name=$scope==='venue'?'Каталожный ответственный за площадку':'Владелец лиги';
   $idContract=$pdo->prepare("INSERT INTO contracts
     (family,name,status,assigned_by,assigned_at,assigner,starts_at,
      created_at,updated_at)
      VALUES('membership',?,'active',?,now(),'flow',now(),now(),now())
      RETURNING id");
   $idContract->execute([$name,$owner]);
   $contractId=(int)$idContract->fetchColumn();
   execute($pdo,"INSERT INTO contract_memberships
      (contract_id,scope_type,scope_id,user_id,access_level,invitation_status,
       created_at,updated_at) VALUES(?,?,?,?,?,'accepted',now(),now())",
      [$contractId,$scope,$id,$owner,$scope==='venue'?'primary_manager':'owner']);
   foreach($permissions[$scope] as $perm){
     execute($pdo,"INSERT INTO contract_permissions
      (contract_id,permission,created_at,updated_at)
      VALUES(?,?,now(),now())",[$contractId,$perm]);
   }
   $created[$scope]++;
 }
 if($apply)$pdo->commit();else $pdo->rollBack();
 echo 'OWNER_RIGHTS_ISOLATED=true'.PHP_EOL;
 echo 'rights_apply='.($apply?'yes':'no').PHP_EOL;
 echo 'new_venue_managers_created='.$created['venue'].PHP_EOL;
 echo 'new_league_owners_created='.$created['league'].PHP_EOL;
 echo 'previous_active_management_preserved='.$preserved.PHP_EOL;
 echo 'unassignable_management_scopes='.$unassigned.PHP_EOL;
 if($unassigned>0)exit(2);
}catch(Throwable $e){
 if($pdo->inTransaction())$pdo->rollBack();
 fwrite(STDERR,'OWNER_RIGHTS_BLOCKED class='.get_class($e).PHP_EOL);
 exit(1);
}
