<?php

/**
 * Task 325: import independent DEV identities only into an isolated, restored
 * PostgreSQL candidate. No live target is reachable from the private Docker
 * network. Never auto-merge a person's accounts from contact/username alone.
 *
 * CLI default is rollback/dry-run. --apply is allowed only with an explicit
 * isolation guard and only when source and target DB names match the rehearsal.
 */
declare(strict_types=1);

function failClosed(string $reason): never
{
    throw new RuntimeException($reason);
}

if (getenv('MSKBA_MERGE_ISOLATED') !== 'YES') {
    failClosed('Missing isolated candidate guard');
}
$apply = in_array('--apply', $argv, true);
$user = (string) getenv('MSKBA_MERGE_DB_USER');
$password = (string) getenv('MSKBA_MERGE_DB_PASSWORD');
$host = (string) getenv('MSKBA_MERGE_DB_HOST');
if ($host !== 'candidate-db' || $user !== 'postgres' || $password === '') {
    failClosed('Unexpected database target');
}

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$src = new PDO('pgsql:host='.$host.';port=5432;dbname=dev_snapshot', $user, $password, $options);
$dst = new PDO('pgsql:host='.$host.';port=5432;dbname=candidate', $user, $password, $options);
$src->exec('SET default_transaction_read_only = on');
$dst->beginTransaction();

function rows(PDO $db, string $sql, array $params = []): array
{
    $s = $db->prepare($sql);
    $s->execute($params);

    return $s->fetchAll();
}

function insertRow(PDO $db, string $table, array $data): int
{
    if (preg_match('/^[a-z_]+$/', $table) !== 1 || $data === []) {
        failClosed('Unrecognized insert table');
    }
    $fields = array_keys($data);
    foreach ($fields as $field) {
        if (preg_match('/^[a-z_]+$/', $field) !== 1) {
            failClosed('Unrecognized insert field');
        }
    }
    $quoted = array_map(static fn (string $f): string => '"'.$f.'"', $fields);
    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $s = $db->prepare('INSERT INTO "'.$table.'" ('.implode(',', $quoted).') VALUES ('.$placeholders.') RETURNING id');
    $s->execute(array_values($data));

    return (int) $s->fetchColumn();
}

function normalized(string $type, string $value): string
{
    // Source and target store normalized contact values. A phone number
    // is never matched by last digits, or by guessed country code.
    return strtolower(trim($type)).'|'.strtolower(trim($value));
}

try {
    $dst->exec("CREATE TABLE IF NOT EXISTS data_merge_user_map (
        source_user_id bigint PRIMARY KEY,
        target_user_id bigint NULL REFERENCES users(id),
        resolution varchar(40) NOT NULL,
        original_system_role varchar(30),
        created_at timestamptz NOT NULL DEFAULT now()
    )");
    $dst->exec("CREATE TABLE IF NOT EXISTS data_merge_actor_map (
        source_actor_id bigint PRIMARY KEY,
        target_actor_id bigint NULL REFERENCES actors(id),
        resolution varchar(40) NOT NULL,
        created_at timestamptz NOT NULL DEFAULT now()
    )");

    $sourceUsers = rows($src, 'SELECT * FROM users ORDER BY id');
    $targetUsers = rows($dst, 'SELECT * FROM users ORDER BY id');
    $sourceContacts = rows($src, "SELECT * FROM contacts WHERE contactable_type = 'user' AND deleted_at IS NULL");
    $targetContacts = rows($dst, "SELECT * FROM contacts WHERE contactable_type = 'user' AND deleted_at IS NULL");
    $srcTg = rows($src, 'SELECT * FROM telegram_accounts');
    $dstTg = rows($dst, 'SELECT * FROM telegram_accounts');

    $prodById = [];
    $prodByUsername = [];
    foreach ($targetUsers as $p) {
        $pid = (int) $p['id'];
        $prodById[$pid] = $p;
        if (($p['username'] ?? '') !== '') {
            $prodByUsername[strtolower(trim($p['username']))] = $pid;
        }
    }

    $verifiedProdByKey = [];
    foreach ($targetContacts as $c) {
        if ($c['verified_at'] !== null) {
            $key = normalized((string) $c['type'], (string) $c['value']);
            $verifiedProdByKey[$key][(int) $c['contactable_id']] = true;
        }
    }
    $verifiedSourceById = [];
    foreach ($sourceContacts as $c) {
        if ($c['verified_at'] !== null) {
            $verifiedSourceById[(int) $c['contactable_id']][] = normalized((string) $c['type'], (string) $c['value']);
        }
    }
    $tgTargetByPlatformId = [];
    foreach ($dstTg as $account) {
        $tgTargetByPlatformId[(string) $account['telegram_user_id']] = (int) $account['user_id'];
    }
    $tgSourceByUser = [];
    foreach ($srcTg as $account) {
        $tgSourceByUser[(int) $account['user_id']][] = (string) $account['telegram_user_id'];
    }

    $sourceById = [];
    $userMap = [];
    $resolution = [];
    $counters = ['new' => 0, 'system_principal' => 0, 'pending_identity' => 0, 'privilege_downgrades' => 0];

    foreach ($sourceUsers as $s) {
        $sid = (int) $s['id'];
        $sourceById[$sid] = $s;
        $verifiedCandidates = [];
        foreach ($verifiedSourceById[$sid] ?? [] as $key) {
            foreach (array_keys($verifiedProdByKey[$key] ?? []) as $target) {
                $verifiedCandidates[$target] = true;
            }
        }
        foreach ($tgSourceByUser[$sid] ?? [] as $platformId) {
            if (isset($tgTargetByPlatformId[$platformId])) {
                $verifiedCandidates[$tgTargetByPlatformId[$platformId]] = true;
            }
        }
        $sourceUsername = strtolower(trim((string) ($s['username'] ?? '')));
        $usernameTarget = $sourceUsername !== '' ? ($prodByUsername[$sourceUsername] ?? null) : null;
        $systemPrincipal = $usernameTarget !== null
            && in_array($sourceUsername, ['superadmin', 'system'], true)
            && $prodById[$usernameTarget]['system_role'] === $s['system_role']
            && in_array($s['system_role'], ['superadmin', 'system'], true);
        $targetId = null;
        $kind = 'new';

        if (count($verifiedCandidates) > 1
            || ($usernameTarget !== null && ! $systemPrincipal)
            || ($verifiedCandidates !== [] && ! $systemPrincipal)
            || ($systemPrincipal && $verifiedCandidates !== []
                && ! isset($verifiedCandidates[$usernameTarget]))) {
            // Only the user or an authorized explicit identity-resolution
            // workflow can approve connecting two real account histories.
            $kind = 'pending_identity';
            $counters[$kind]++;
        } elseif ($systemPrincipal) {
            // Seeded operational principal, NOT a merge of user credentials.
            $kind = 'system_principal';
            $targetId = $usernameTarget;
            $counters[$kind]++;
        } else {
            $data = $s;
            unset($data['id'], $data['remember_token'], $data['canonical_user_id']);
            $data['design_theme_id'] = null; // theme IDs are source-local
            $role = (string) ($data['system_role'] ?? 'user');
            if ($role !== 'user') {
                $data['system_role'] = 'user'; // never grant dev admin privileges in prod
                $data['staff_title'] = null;
                $counters['privilege_downgrades']++;
            }
            $targetId = insertRow($dst, 'users', $data);
            $kind = 'new';
            $counters[$kind]++;
        }

        $dst->prepare('INSERT INTO data_merge_user_map (source_user_id,target_user_id,resolution,original_system_role)
            VALUES (?,?,?,?) ON CONFLICT (source_user_id)
            DO UPDATE SET target_user_id=excluded.target_user_id,resolution=excluded.resolution')
            ->execute([$sid, $targetId, $kind, $s['system_role']]);
        $userMap[$sid] = $targetId;
        $resolution[$sid] = $kind;
    }

    // Restore canonical links only for fresh, fully mapped source accounts.
    foreach ($sourceUsers as $s) {
        $sid = (int) $s['id'];
        $parent = $s['canonical_user_id'] ?? null;
        if ($parent !== null && $resolution[$sid] === 'new' && isset($userMap[(int) $parent])
            && $userMap[(int) $parent] !== null) {
            $dst->prepare('UPDATE users SET canonical_user_id = ? WHERE id = ?')
                ->execute([$userMap[(int) $parent], $userMap[$sid]]);
        }
    }

    // Preserve all historical profile/consent/privacy data for newly imported
    // identities, without overwriting a production user's existing details.
    $copyTables = [
        'profiles', 'player_profiles', 'user_privacy_settings', 'user_consents',
        'user_participation_roles',
    ];
    $copied = [];
    foreach ($copyTables as $table) {
        $copied[$table] = 0;
        foreach (rows($src, 'SELECT * FROM "'.$table.'" ORDER BY id') as $s) {
            $sid = (int) $s['user_id'];
            if (($resolution[$sid] ?? null) !== 'new') {
                continue;
            }
            $data = $s;
            unset($data['id']);
            $data['user_id'] = $userMap[$sid];
            if ($table === 'user_participation_roles' && array_key_exists('assigned_by', $data)) {
                $assignee = $data['assigned_by'];
                $data['assigned_by'] = $assignee === null ? null : ($userMap[(int) $assignee] ?? null);
            }
            insertRow($dst, $table, $data);
            $copied[$table]++;
        }
    }

    $copied['contacts'] = 0;
    foreach ($sourceContacts as $c) {
        $sid = (int) $c['contactable_id'];
        if (($resolution[$sid] ?? null) !== 'new') {
            continue;
        }
        $data = $c;
        unset($data['id'], $data['user_email_key']);
        $data['contactable_id'] = $userMap[$sid];
        insertRow($dst, 'contacts', $data);
        $copied['contacts']++;
    }

    $copied['telegram_accounts'] = 0;
    foreach ($srcTg as $account) {
        $sid = (int) $account['user_id'];
        if (($resolution[$sid] ?? null) !== 'new') {
            continue;
        }
        $data = $account;
        unset($data['id']);
        $data['user_id'] = $userMap[$sid];
        // Personal bot chat access in the dev environment does not prove that
        // the production bot has permission to send to this user.
        foreach (['private_chat_id','private_chat_started_at','private_chat_available_at',
                  'private_chat_unavailable_at','last_delivery_error'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = null;
            }
        }
        insertRow($dst, 'telegram_accounts', $data);
        $copied['telegram_accounts']++;
    }

    // Identity owner mapping for later venue/team/event import. Guest/browser
    // fingerprint ownership is kept isolated; never conflate 74 common actor
    // keys from different environments.
    $actorCounts = ['user_mapped' => 0, 'guest_isolated' => 0, 'system_mapped' => 0, 'pending_owner' => 0];
    $systemActor = $dst->query("SELECT id FROM actors WHERE type='system' AND user_id IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    foreach (rows($src, 'SELECT * FROM actors ORDER BY id') as $a) {
        $aid = (int) $a['id'];
        $targetActorId = null;
        $reason = 'pending_owner';
        $type = (string) $a['type'];

        if ($type === 'system' && $systemActor !== false) {
            $targetActorId = (int) $systemActor;
            $reason = 'system_mapped';
        } elseif ($type === 'user' && $a['user_id'] !== null) {
            $userId = $userMap[(int) $a['user_id']] ?? null;
            if ($userId !== null) {
                $key = 'user:user:'.$userId.':fingerprint:none';
                $existing = rows($dst, 'SELECT id FROM actors WHERE actor_key = ?', [$key]);
                if ($existing !== []) {
                    $targetActorId = (int) $existing[0]['id'];
                } else {
                    $targetActorId = insertRow($dst, 'actors', [
                        'actor_key' => $key,
                        'type' => 'user',
                        'user_id' => $userId,
                        'user_fingerprint_id' => null,
                        'created_at' => $a['created_at'],
                        'updated_at' => $a['updated_at'],
                    ]);
                }
                $reason = 'user_mapped';
            }
        } elseif ($type === 'guest') {
            $targetActorId = insertRow($dst, 'actors', [
                'actor_key' => 'guest:imported:dev:'.$aid,
                'type' => 'guest',
                'user_id' => null,
                'user_fingerprint_id' => null,
                'created_at' => $a['created_at'],
                'updated_at' => $a['updated_at'],
            ]);
            $reason = 'guest_isolated';
        }
        $dst->prepare('INSERT INTO data_merge_actor_map (source_actor_id,target_actor_id,resolution)
            VALUES (?,?,?) ON CONFLICT(source_actor_id) DO UPDATE
            SET target_actor_id=excluded.target_actor_id,resolution=excluded.resolution')
            ->execute([$aid, $targetActorId, $reason]);
        $actorCounts[$reason]++;
    }

    // Critical gate: all original prod IDs and password hashes must survive.
    foreach ($targetUsers as $p) {
        $existing = rows($dst, 'SELECT password FROM users WHERE id = ?', [(int) $p['id']]);
        if ($existing === [] || $existing[0]['password'] !== $p['password']) {
            failClosed('Original production identity/password changed');
        }
    }

    $hasPending = $counters['pending_identity'] > 0 || $actorCounts['pending_owner'] > 0;
    if ($apply) {
        $dst->commit();
    } else {
        $dst->rollBack();
    }
    echo 'IDENTITY_IMPORT_ISOLATED=true'.PHP_EOL;
    echo 'identity_apply='.($apply ? 'yes' : 'no').PHP_EOL;
    foreach ($counters as $name => $count) {
        echo 'users_'.$name.'='.$count.PHP_EOL;
    }
    foreach ($copied as $name => $count) {
        echo 'copied_'.$name.'='.$count.PHP_EOL;
    }
    foreach ($actorCounts as $name => $count) {
        echo 'actors_'.$name.'='.$count.PHP_EOL;
    }
    echo 'manual_resolution_required='.($hasPending ? 'true' : 'false').PHP_EOL;
    echo 'PROD_PASSWORDS_PRESERVED=true'.PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    if ($dst->inTransaction()) {
        $dst->rollBack();
    }
    // Keep contact/account data out of GitHub logs. Detailed stack traces
    // belong only in the private server report, not stdout/stderr.
    fwrite(STDERR, 'IDENTITY_IMPORT_BLOCKED: '.get_class($exception).PHP_EOL);
    exit(1);
}
