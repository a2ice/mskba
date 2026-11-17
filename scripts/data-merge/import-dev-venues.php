<?php

/**
 * Task 325: venue/court/catalogue migration in an OFFLINE candidate.
 * Source and candidate PostgreSQL DBs are disposable restored copies.
 *
 * Never infer venue identity from same name alone. Never overwrite historical
 * prod ownership, publication state, booking conditions, aliases or IDs.
 */
declare(strict_types=1);

function blocked(string $reason): never { throw new RuntimeException($reason); }

if (getenv('MSKBA_MERGE_ISOLATED') !== 'YES'
    || getenv('MSKBA_MERGE_DB_HOST') !== 'candidate-db'
    || getenv('MSKBA_MERGE_DB_USER') !== 'postgres'
    || getenv('MSKBA_MERGE_DB_PASSWORD') === false) {
    blocked('Isolated-only guard failed');
}
$apply = in_array('--apply', $argv, true);
$pw = (string) getenv('MSKBA_MERGE_DB_PASSWORD');
$opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$source = new PDO('pgsql:host=candidate-db;port=5432;dbname=dev_snapshot', 'postgres', $pw, $opts);
$target = new PDO('pgsql:host=candidate-db;port=5432;dbname=candidate', 'postgres', $pw, $opts);
$source->exec('SET default_transaction_read_only=on');
$target->beginTransaction();

function queryRows(PDO $db, string $sql, array $bind = []): array {
    $q = $db->prepare($sql);
    $q->execute($bind);
    return $q->fetchAll();
}

function writeRow(PDO $db, string $table, array $values): int {
    if (preg_match('/^[a-z][a-z_0-9]*$/', $table) !== 1) blocked('Unsafe table');
    foreach (array_keys($values) as $column) {
        if (preg_match('/^[a-z][a-z_0-9]*$/', $column) !== 1) blocked('Unsafe column');
    }
    $cols = array_keys($values);
    $sql = 'INSERT INTO "'.$table.'" ('.implode(',', array_map(static fn($c)=>'"'.$c.'"', $cols))
        .') VALUES ('.implode(',', array_fill(0,count($cols),'?')).') RETURNING id';
    $q = $db->prepare($sql);
    foreach (array_values($values) as $i=>$v) {
        $q->bindValue($i+1,$v,match(true) {
            $v === null => PDO::PARAM_NULL,
            is_bool($v) => PDO::PARAM_BOOL,
            is_int($v) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        });
    }
    $q->execute();
    return (int) $q->fetchColumn();
}

function updateSafe(PDO $db, string $table, int $id, array $data): void {
    if ($data === []) return;
    if (preg_match('/^[a-z_0-9]+$/', $table) !== 1) blocked('Unsafe update table');
    foreach (array_keys($data) as $col) {
        if (preg_match('/^[a-z_0-9]+$/', $col) !== 1) blocked('Unsafe update column');
    }
    $sql='UPDATE "'.$table.'" SET '.implode(',',array_map(static fn($col)=>'"'.$col.'"=?', array_keys($data))).' WHERE id=?';
    $q=$db->prepare($sql);
    $args=array_values($data);
    $args[]=$id;
    foreach ($args as $i=>$v) {
        $q->bindValue($i+1,$v,match(true){
            $v===null=>PDO::PARAM_NULL,is_bool($v)=>PDO::PARAM_BOOL,
            is_int($v)=>PDO::PARAM_INT,default=>PDO::PARAM_STR
        });
    }
    $q->execute();
}

function meters(?float $aLat,?float $aLon,?float $bLat,?float $bLon): ?float {
    if ($aLat===null||$aLon===null||$bLat===null||$bLon===null) return null;
    $lat=deg2rad($bLat-$aLat);
    $lon=deg2rad($bLon-$aLon);
    $s=sin($lat/2)**2+cos(deg2rad($aLat))*cos(deg2rad($bLat))*sin($lon/2)**2;
    return 6371000*2*atan2(sqrt(min(1,max(0,$s))),sqrt(max(0,1-$s)));
}

function coords(PDO $db): array {
    $out=[];
    foreach(queryRows($db,'SELECT v.id,v.name,v.alias,v.type,a.latitude,a.longitude
        FROM venues v LEFT JOIN locations l ON l.id=v.location_id
        LEFT JOIN addresses a ON a.id=l.address_id WHERE v.deleted_at IS NULL') as $v) {
        $out[(int)$v['id']]=[
            'name'=>mb_strtolower(trim((string)$v['name'])),
            'alias'=>(string)$v['alias'],
            'type'=>(string)$v['type'],
            'lat'=>$v['latitude']===null?null:(float)$v['latitude'],
            'lon'=>$v['longitude']===null?null:(float)$v['longitude'],
        ];
    }
    return $out;
}

try {
    foreach (['data_merge_user_map','data_merge_actor_map'] as $table) {
        if (queryRows($target, 'SELECT to_regclass(?) AS table_name', ['public.'.$table])[0]['table_name'] === null) {
            blocked('Identity mapping phase must run before venue import');
        }
    }
    $userMap=[];
    foreach(queryRows($target,'SELECT source_user_id,target_user_id FROM data_merge_user_map') as $m){
        $userMap[(int)$m['source_user_id']]=$m['target_user_id']===null?null:(int)$m['target_user_id'];
    }
    $actorMap=[];
    foreach(queryRows($target,'SELECT source_actor_id,target_actor_id FROM data_merge_actor_map') as $m){
        $actorMap[(int)$m['source_actor_id']]=$m['target_actor_id']===null?null:(int)$m['target_actor_id'];
    }
    $target->exec("CREATE TABLE IF NOT EXISTS data_merge_venue_map (
        source_venue_id bigint PRIMARY KEY,
        target_venue_id bigint NOT NULL REFERENCES venues(id),
        resolution varchar(40) NOT NULL,
        created_at timestamptz NOT NULL DEFAULT now()
    )");
    $target->exec("CREATE TABLE IF NOT EXISTS data_merge_court_map (
        source_court_id bigint PRIMARY KEY,
        target_court_id bigint NOT NULL REFERENCES venue_courts(id),
        resolution varchar(40) NOT NULL,
        created_at timestamptz NOT NULL DEFAULT now()
    )");
    $candidate=coords($target);
    $dev=coords($source);
    $sourceVenues=queryRows($source,'SELECT * FROM venues WHERE deleted_at IS NULL ORDER BY id');
    $createdVenue=[];
    $venueMap=[];
    $stats=['matched_venues'=>0,'new_venues'=>0,'matched_courts'=>0,'new_courts'=>0,
        'source_courts'=>0,'mapped_owners'=>0,'unknown_owners'=>0,
        'calendar_prices'=>0,'court_schedule'=>0,'amenities'=>0,'tags'=>0];
    foreach($sourceVenues as $venue){
        $sid=(int)$venue['id'];
        $s=$dev[$sid];
        $near=[];
        foreach($candidate as $tid=>$v){
            if($v['type']!==$s['type']) continue;
            $distance=meters($s['lat'],$s['lon'],$v['lat'],$v['lon']);
            if($distance!==null && $distance<=50.0) $near[$tid]=$distance;
        }
        $exact=[];
        foreach($near as $tid=>$distance){
            $v=$candidate[$tid];
            if($v['name']===$s['name'] && $v['alias']===$s['alias'])$exact[]=$tid;
        }
        if(count($exact)>1 || ($near!==[] && count($exact)!==1)){
            blocked('Ambiguous 50m venue match; manual review required');
        }
        if(count($exact)===1){
            $id=$exact[0];
            $kind='strong_geo';
            // Preserve production slug, ownership, publication, operational
            // and payment status. Only improve editorial fields in candidate.
            $allowed=[];
            foreach(['short_description','full_description'] as $field){
                if(isset($venue[$field]) && trim((string)$venue[$field])!=='') {
                    $allowed[$field]=$venue[$field];
                }
            }
            updateSafe($target,'venues',$id,$allowed);
            $stats['matched_venues']++;
        }else{
            // A distant same-name venue is a DIFFERENT physical location.
            // Still refuse a global route alias collision.
            if(queryRows($target,'SELECT id FROM venues WHERE alias = ?',[$venue['alias']])!==[]){
                blocked('Duplicate alias for a new physical venue');
            }
            $data=$venue;
            unset($data['id']);
            $a=(int)($data['created_by_actor_id']??0);
            $data['created_by_actor_id']=$a>0?($actorMap[$a]??null):null;
            if($a>0 && $data['created_by_actor_id']===null)$stats['unknown_owners']++;
            elseif($a>0)$stats['mapped_owners']++;
            $data['canonical_venue_id']=null;
            $sourceLoc=$data['location_id']??null;
            $data['location_id']=null;
            if($sourceLoc!==null){
                $locs=queryRows($source,'SELECT * FROM locations WHERE id = ?',[(int)$sourceLoc]);
                if(count($locs)!==1)blocked('Missing source location');
                $loc=$locs[0];
                $addressId=null;
                if($loc['address_id']!==null){
                    $addr=queryRows($source,'SELECT * FROM addresses WHERE id = ?',[(int)$loc['address_id']]);
                    if(count($addr)!==1)blocked('Missing source address');
                    $address=$addr[0];unset($address['id']);
                    $addressId=writeRow($target,'addresses',$address);
                }
                $loc['address_id']=$addressId;unset($loc['id']);
                $data['location_id']=writeRow($target,'locations',$loc);
            }
            $id=writeRow($target,'venues',$data);
            $stats['new_venues']++;
            $kind='new_venue';
            $createdVenue[$sid]=true;
        }
        $venueMap[$sid]=$id;
        $target->prepare('INSERT INTO data_merge_venue_map (source_venue_id,target_venue_id,resolution)
            VALUES (?,?,?) ON CONFLICT (source_venue_id) DO UPDATE
            SET target_venue_id=excluded.target_venue_id,resolution=excluded.resolution')
            ->execute([$sid,$id,$kind]);
    }

    // Current dataset: 5 same-geometry venues + 4 physically new ones.
    if ($stats['matched_venues']!==5 || $stats['new_venues']!==4){
        blocked('Venue baseline has changed since the approved geo audit');
    }
    $targetCourts=queryRows($target,'SELECT * FROM venue_courts ORDER BY id');
    $courtsByVenue=[];
    foreach($targetCourts as $court){
        $courtsByVenue[(int)$court['venue_id']][(string)$court['alias']]=$court;
    }
    $courtMap=[];
    foreach(queryRows($source,'SELECT * FROM venue_courts WHERE deleted_at IS NULL ORDER BY id') as $s){
        $sourceVenue=(int)$s['venue_id'];
        $targetVenue=$venueMap[$sourceVenue]??null;
        if($targetVenue===null)blocked('Court venue is not mapped');
        $existing=$courtsByVenue[$targetVenue][$s['alias']]??null;
        if($existing!==null){
            $id=(int)$existing['id'];
            $safe=[];
            foreach(['short_description','full_description','environment',
                     'hoops_count','surface_type'] as $field){
                if(array_key_exists($field,$s) && $s[$field]!==null)$safe[$field]=$s[$field];
            }
            updateSafe($target,'venue_courts',$id,$safe);
            $stats['matched_courts']++;
            $kind='alias_existing';
        }else{
            $data=$s;
            unset($data['id']);
            $data['venue_id']=$targetVenue;
            $hasPrimary=queryRows($target,'SELECT id FROM venue_courts WHERE venue_id=? AND is_primary=true AND deleted_at IS NULL LIMIT 1',[$targetVenue])!==[];
            if($hasPrimary)$data['is_primary']=false;
            $id=writeRow($target,'venue_courts',$data);
            $courtsByVenue[$targetVenue][(string)$s['alias']]=['id'=>$id,'alias'=>$s['alias']];
            $stats['new_courts']++;
            $kind='new_court';
        }
        $courtMap[(int)$s['id']]=$id;
        $stats['source_courts']++;
        $target->prepare('INSERT INTO data_merge_court_map (source_court_id,target_court_id,resolution)
            VALUES(?,?,?) ON CONFLICT(source_court_id) DO UPDATE
            SET target_court_id=excluded.target_court_id,resolution=excluded.resolution')
            ->execute([(int)$s['id'],$id,$kind]);
    }

    // Bring modern per-court weekly schedules and calendar prices into the
    // candidate. They are catalogue terms; financial transactions excluded.
    foreach(['venue_court_schedules','venue_court_calendar_slot_prices',
             'venue_court_slot_prices'] as $table){
        foreach(queryRows($source,'SELECT * FROM '.$table.' ORDER BY id') as $s){
            $sid=(int)$s['venue_court_id'];
            $tid=$courtMap[$sid]??null;
            if($tid===null)blocked('Orphan court schedule/price');
            $existingRows=queryRows($target,'SELECT * FROM '.$table.' WHERE venue_court_id=?',[$tid]);
            if($existingRows!==[]){
                // Production rows take precedence unless no conflicting
                // catalogue row exists. No silent pricing override.
                continue;
            }
            unset($s['id']);
            $s['venue_court_id']=$tid;
            writeRow($target,$table,$s);
            if($table==='venue_court_schedules')$stats['court_schedule']++;
            if($table==='venue_court_calendar_slot_prices')$stats['calendar_prices']++;
        }
    }

    $amenities=[];
    foreach(queryRows($target,'SELECT id,alias FROM amenities') as $a){
        $amenities[$a['alias']]=(int)$a['id'];
    }
    $devAmenities=[];
    foreach(queryRows($source,'SELECT id,alias FROM amenities') as $a){
        $devAmenities[(int)$a['id']]=$a['alias'];
    }
    foreach(['venue_amenities','venue_court_amenities'] as $table){
        $column=$table==='venue_amenities'?'venue_id':'venue_court_id';
        foreach(queryRows($source,'SELECT * FROM '.$table.' ORDER BY id') as $row){
            $mapped=$table==='venue_amenities'
                ?($venueMap[(int)$row[$column]]??null)
                :($courtMap[(int)$row[$column]]??null);
            $name=$devAmenities[(int)$row['amenity_id']]??null;
            $aid=$name===null?null:($amenities[$name]??null);
            if($mapped===null||$aid===null)blocked('Missing amenity or venue/court map');
            if(queryRows($target,'SELECT id FROM '.$table.' WHERE '.$column.'=? AND amenity_id=?',[$mapped,$aid])!==[])continue;
            unset($row['id']);
            $row[$column]=$mapped;
            $row['amenity_id']=$aid;
            writeRow($target,$table,$row);
            $stats['amenities']++;
        }
    }
    foreach(queryRows($source,'SELECT * FROM venue_tags ORDER BY id') as $row){
        $v=$venueMap[(int)$row['venue_id']]??null;
        if($v===null)blocked('Unmapped venue tag');
        if(queryRows($target,'SELECT id FROM venue_tags WHERE venue_id=? AND slug=?',[$v,$row['slug']])!==[])continue;
        unset($row['id']);
        $row['venue_id']=$v;
        writeRow($target,'venue_tags',$row);
        $stats['tags']++;
    }

    foreach(queryRows($source,'SELECT * FROM venue_characteristics ORDER BY id') as $row){
        $sid=(int)$row['venue_id'];
        if(!isset($createdVenue[$sid])) continue; // prod characteristics preserved
        $row['venue_id']=$venueMap[$sid];unset($row['id']);
        if(queryRows($target,'SELECT id FROM venue_characteristics WHERE venue_id=?',[$row['venue_id']])===[]){
            writeRow($target,'venue_characteristics',$row);
        }
    }

    // Preserve production primary keys and original venue counts; only add.
    foreach(queryRows($target,'SELECT source_venue_id,target_venue_id FROM data_merge_venue_map') as $m){
        if(!isset($venueMap[(int)$m['source_venue_id']]) || $venueMap[(int)$m['source_venue_id']]!==(int)$m['target_venue_id']){
            blocked('Venue mapping integrity mismatch');
        }
    }
    if($apply)$target->commit();else $target->rollBack();
    echo 'VENUE_IMPORT_ISOLATED=true'.PHP_EOL;
    echo 'venue_import_apply='.($apply?'yes':'no').PHP_EOL;
    foreach($stats as $k=>$n)echo $k.'='.$n.PHP_EOL;
    echo 'PROD_VENUE_IDENTITIES_AND_SLUGS_PRESERVED=true'.PHP_EOL;
} catch(Throwable $ex){
    if($target->inTransaction())$target->rollBack();
    fwrite(STDERR,'VENUE_IMPORT_BLOCKED: '.get_class($ex).PHP_EOL);
    exit(1);
}
