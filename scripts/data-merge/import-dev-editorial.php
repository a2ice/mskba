<?php
/**
 * Task 325: copy DEV design/theme and Page Builder editorial data into an
 * isolated restored production candidate. Never connects to either live DB.
 * All source->target ID remapping and existing-prod preimages stay private.
 */
declare(strict_types=1);
if (getenv('MSKBA_MERGE_ISOLATED') !== 'YES'
    || getenv('MSKBA_MERGE_DB_HOST') !== 'candidate-db'
    || getenv('MSKBA_MERGE_DB_USER') !== 'postgres'
    || !getenv('MSKBA_MERGE_DB_PASSWORD')) {
    throw new RuntimeException('Only an isolated migration candidate is permitted');
}
$apply = in_array('--apply', $argv, true);
$pw = getenv('MSKBA_MERGE_DB_PASSWORD');
$opts = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false];
$src = new PDO('pgsql:host=candidate-db;dbname=dev_snapshot','postgres',$pw,$opts);
$dst = new PDO('pgsql:host=candidate-db;dbname=candidate','postgres',$pw,$opts);
$src->exec('SET default_transaction_read_only=on');
$dst->beginTransaction();
function qid(string $s):string {
    if (!preg_match('/^[a-z][a-z_0-9]*$/',$s)) throw new RuntimeException('Invalid identifier');
    return '"'.$s.'"';
}
function selectRows(PDO $db,string $sql,array $bind=[]):array {
    $q=$db->prepare($sql);$q->execute($bind);return $q->fetchAll();
}
function runStmt(PDO $db,string $sql,array $bind=[]):void {
    $q=$db->prepare($sql);
    foreach(array_values($bind) as $i=>$v){
        $q->bindValue($i+1,$v,match(true) {
            $v===null=>PDO::PARAM_NULL,is_bool($v)=>PDO::PARAM_BOOL,
            is_int($v)=>PDO::PARAM_INT,default=>PDO::PARAM_STR});
    }
    $q->execute();
}
function saveRow(PDO $db,string $table,array $row,?int $targetId=null):int {
    unset($row['id']);
    if($row===[])throw new RuntimeException('No fields');
    $columns=array_keys($row);
    $fields=implode(',',array_map('qid',$columns));
    $params=implode(',',array_fill(0,count($columns),'?'));
    if($targetId===null){
        $sql='INSERT INTO '.qid($table).' ('.$fields.') VALUES ('.$params.') RETURNING id';
        $q=$db->prepare($sql);
    }else{
        unset($row['created_at']); // keep production audit provenance
        $columns=array_keys($row);
        $sql='UPDATE '.qid($table).' SET '.implode(',',array_map(fn($col)=>qid($col).'=?',$columns)).' WHERE id=? RETURNING id';
        $q=$db->prepare($sql);
        $row['__where_id__']=$targetId;
    }
    foreach(array_values($row) as $i=>$v){
        $q->bindValue($i+1,$v,match(true){
            $v===null=>PDO::PARAM_NULL,is_bool($v)=>PDO::PARAM_BOOL,
            is_int($v)=>PDO::PARAM_INT,default=>PDO::PARAM_STR
        });
    }
    $q->execute();
    return (int)$q->fetchColumn();
}
function mapped(array $mapping,?int $sourceId,string $kind,bool $nullable=false):?int {
    if($sourceId===null)return null;
    if(!array_key_exists($sourceId,$mapping)){
        if($nullable)return null;
        throw new RuntimeException('Missing '.$kind.' mapping');
    }
    return $mapping[$sourceId];
}
function targetFor(PDO $db,string $table,array $key):?int {
    $clauses=[];$args=[];
    foreach($key as $column=>$value){
        $clauses[]=qid($column).($value===null?' IS NULL':'=?');
        if($value!==null)$args[]=$value;
    }
    $found=selectRows($db,'SELECT id FROM '.qid($table).' WHERE '.implode(' AND ',$clauses).' ORDER BY id LIMIT 2',$args);
    if(count($found)>1)throw new RuntimeException('Ambiguous target for '.$table);
    return $found===[]?null:(int)$found[0]['id'];
}
function track(PDO $db,string $type,int $sourceId,int $targetId):void {
    runStmt($db,'INSERT INTO data_merge_editorial_map (kind,source_id,target_id)
       VALUES (?,?,?) ON CONFLICT(kind,source_id)
       DO UPDATE SET target_id=EXCLUDED.target_id',[$type,$sourceId,$targetId]);
}
function newOnly(PDO $db,string $type,int $srcId):?int {
    $r=selectRows($db,'SELECT target_id FROM data_merge_editorial_map WHERE kind=? AND source_id=?',[$type,$srcId]);
    return $r===[]?null:(int)$r[0]['target_id'];
}
try{
    $exists=selectRows($dst,"SELECT to_regclass('public.data_merge_user_map') AS t");
    if($exists[0]['t']===null)throw new RuntimeException('Identity map must be applied first');
    $users=[];
    foreach(selectRows($dst,'SELECT source_user_id,target_user_id FROM data_merge_user_map') as $row){
        $users[(int)$row['source_user_id']]=$row['target_user_id']===null?null:(int)$row['target_user_id'];
    }
    $dst->exec("CREATE TABLE IF NOT EXISTS data_merge_editorial_map(
       kind varchar(64) NOT NULL,source_id bigint NOT NULL,target_id bigint NOT NULL,
       PRIMARY KEY(kind,source_id))");
    $dst->exec("CREATE TABLE IF NOT EXISTS data_merge_editorial_preimage(
       kind varchar(64) NOT NULL,target_id bigint NOT NULL,original jsonb NOT NULL,
       PRIMARY KEY(kind,target_id))");
    $stats=[];
    $themes=[];
    foreach(selectRows($src,'SELECT * FROM design_themes ORDER BY id') as $row){
        $sid=(int)$row['id'];
        $existing=targetFor($dst,'design_themes',['slug'=>$row['slug']]);
        if($existing!==null){
            $prior=selectRows($dst,'SELECT * FROM design_themes WHERE id=?',[$existing])[0];
            runStmt($dst,'INSERT INTO data_merge_editorial_preimage(kind,target_id,original)
              VALUES(?,?,?::jsonb) ON CONFLICT DO NOTHING',
              ['theme',$existing,json_encode($prior,JSON_THROW_ON_ERROR)]);
        }
        $id=saveRow($dst,'design_themes',$row,$existing);
        $themes[$sid]=$id;track($dst,'theme',$sid,$id);
        $stats['themes']=($stats['themes']??0)+1;
    }
    foreach(selectRows($src,'SELECT * FROM design_theme_revisions ORDER BY id') as $row){
        $old=(int)$row['id'];$row['design_theme_id']=mapped($themes,(int)$row['design_theme_id'],'theme');
        if(($id=newOnly($dst,'theme_revision',$old))===null)$id=saveRow($dst,'design_theme_revisions',$row);
        track($dst,'theme_revision',$old,$id);
        $stats['theme_revisions']=($stats['theme_revisions']??0)+1;
    }
    $assets=[];
    foreach(selectRows($src,'SELECT * FROM design_theme_code_assets ORDER BY id') as $row){
        $old=(int)$row['id'];$row['design_theme_id']=mapped($themes,(int)$row['design_theme_id'],'theme');
        $previous=targetFor($dst,'design_theme_code_assets',['design_theme_id'=>$row['design_theme_id'],'kind'=>$row['kind']]);
        $id=saveRow($dst,'design_theme_code_assets',$row,$previous);
        $assets[$old]=$id;track($dst,'theme_asset',$old,$id);
        $stats['theme_code_assets']=($stats['theme_code_assets']??0)+1;
    }
    foreach(selectRows($src,'SELECT * FROM design_theme_code_revisions ORDER BY id') as $row){
        $old=(int)$row['id'];$row['design_theme_code_asset_id']=mapped($assets,(int)$row['design_theme_code_asset_id'],'theme asset');
        $actor=$row['actor_id'];$row['actor_id']=$actor===null?null:($users[(int)$actor]??null);
        if(($id=newOnly($dst,'theme_code_revision',$old))===null)$id=saveRow($dst,'design_theme_code_revisions',$row);
        track($dst,'theme_code_revision',$old,$id);
        $stats['theme_code_revisions']=($stats['theme_code_revisions']??0)+1;
    }
    $settings=selectRows($src,'SELECT * FROM design_theme_settings WHERE id=1');
    if(count($settings)!==1)throw new RuntimeException('No dev current design theme');
    $chosen=mapped($themes,(int)$settings[0]['theme_id'],'selected theme');
    runStmt($dst,'UPDATE design_theme_settings SET theme_id=?,updated_at=now() WHERE id=1',[$chosen]);

    $objects=[];
    foreach(['template'=>['page_builder_templates','slug'],
             'component'=>['page_builder_components','slug']] as $type=>$cfg){
        [$table,$key]=$cfg;
        $objects[$type]=[];
        foreach(selectRows($src,'SELECT * FROM '.qid($table).' ORDER BY id') as $row){
            $old=(int)$row['id'];$existing=targetFor($dst,$table,[$key=>$row[$key]]);
            if($existing!==null){
                $prior=selectRows($dst,'SELECT * FROM '.qid($table).' WHERE id=?',[$existing])[0];
                runStmt($dst,'INSERT INTO data_merge_editorial_preimage(kind,target_id,original)
                VALUES(?,?,?::jsonb) ON CONFLICT DO NOTHING',
                [$type,$existing,json_encode($prior,JSON_THROW_ON_ERROR)]);
            }
            $id=saveRow($dst,$table,$row,$existing);
            $objects[$type][$old]=$id;track($dst,$type,$old,$id);
            $stats[$type.'s']=($stats[$type.'s']??0)+1;
        }
    }
    $objects['page']=[];
    foreach(selectRows($src,'SELECT * FROM page_builder_pages ORDER BY id') as $row){
        $old=(int)$row['id'];
        foreach(['design_theme_id','published_design_theme_id'] as $field){
            $row[$field]=$row[$field]===null?null:mapped($themes,(int)$row[$field],'page theme');
        }
        foreach(['template_id','published_template_id'] as $field){
            $row[$field]=$row[$field]===null?null:mapped($objects['template'],(int)$row[$field],'page template');
        }
        $existing=targetFor($dst,'page_builder_pages',['path'=>$row['path'],'design_theme_id'=>$row['design_theme_id']]);
        if($existing!==null){
            $prior=selectRows($dst,'SELECT * FROM page_builder_pages WHERE id=?',[$existing])[0];
            runStmt($dst,'INSERT INTO data_merge_editorial_preimage(kind,target_id,original)
                VALUES(?,?,?::jsonb) ON CONFLICT DO NOTHING',
                ['page',$existing,json_encode($prior,JSON_THROW_ON_ERROR)]);
        }
        $id=saveRow($dst,'page_builder_pages',$row,$existing);
        $objects['page'][$old]=$id;track($dst,'page',$old,$id);
        $stats['pages']=($stats['pages']??0)+1;
    }
    foreach(selectRows($src,'SELECT * FROM page_builder_revisions ORDER BY id') as $row){
        $old=(int)$row['id'];$type=(string)$row['entity_type'];
        if(!isset($objects[$type]))throw new RuntimeException('Unrecognized revision owner');
        $row['entity_id']=mapped($objects[$type],(int)$row['entity_id'],'revision owner');
        if($row['user_id']!==null)$row['user_id']=$users[(int)$row['user_id']]??null;
        if($type==='page'){
            $snapshot=json_decode((string)$row['snapshot'],true,512,JSON_THROW_ON_ERROR);
            foreach(['template_id','published_template_id','design_theme_id','published_design_theme_id'] as $field){
                if(isset($snapshot[$field])){
                    $ref=str_contains($field,'theme')?$themes:$objects['template'];
                    $snapshot[$field]=mapped($ref,(int)$snapshot[$field],'snapshot '.$field);
                }
            }
            $row['snapshot']=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        }
        if(($id=newOnly($dst,'pb_revision',$old))===null)$id=saveRow($dst,'page_builder_revisions',$row);
        track($dst,'pb_revision',$old,$id);
        $stats['pb_revisions']=($stats['pb_revisions']??0)+1;
    }
    foreach(['page_builder_script_assets'=>'pb_script_asset',
              'page_builder_script_audit'=>'pb_script_audit'] as $table=>$kind){
        foreach(selectRows($src,'SELECT * FROM '.qid($table).' ORDER BY id') as $row){
            $old=(int)$row['id'];$type=(string)$row['entity_type'];
            $row['entity_id']=mapped($objects[$type]??[],(int)$row['entity_id'],'script owner');
            if(array_key_exists('user_id',$row)&&$row['user_id']!==null)$row['user_id']=$users[(int)$row['user_id']]??null;
            $prior=null;
            if($kind==='pb_script_asset'){
                $prior=targetFor($dst,$table,['entity_type'=>$type,'entity_id'=>$row['entity_id']]);
            }else{
                $prior=newOnly($dst,$kind,$old);
            }
            $id=saveRow($dst,$table,$row,$prior);
            track($dst,$kind,$old,$id);
            $stats[$kind]=($stats[$kind]??0)+1;
        }
    }
    foreach($stats as $key=>$v){
        if($v!==count(selectRows($src,'SELECT id FROM '.qid(match($key){
            'themes'=>'design_themes','theme_revisions'=>'design_theme_revisions',
            'theme_code_assets'=>'design_theme_code_assets',
            'theme_code_revisions'=>'design_theme_code_revisions',
            'templates'=>'page_builder_templates','components'=>'page_builder_components',
            'pages'=>'page_builder_pages','pb_revisions'=>'page_builder_revisions',
            'pb_script_asset'=>'page_builder_script_assets',
            'pb_script_audit'=>'page_builder_script_audit'
        }))))throw new RuntimeException('Source rows missing from '.$key);
    }
    if($apply)$dst->commit();else $dst->rollBack();
    echo 'EDITORIAL_IMPORT_ISOLATED=true'.PHP_EOL;
    echo 'editorial_apply='.($apply?'yes':'no').PHP_EOL;
    foreach($stats as $key=>$v)echo $key.'='.$v.PHP_EOL;
    echo 'DESIGN_THEME_AND_PAGE_BUILDER_IDS_MAPPED=true'.PHP_EOL;
}catch(Throwable $e){
    if($dst->inTransaction())$dst->rollBack();
    fwrite(STDERR,'EDITORIAL_IMPORT_BLOCKED: '.get_class($e).PHP_EOL);
    exit(1);
}
