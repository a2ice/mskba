<?php

declare(strict_types=1);

/**
 * Disposable PostgreSQL CI only. Do not run this on staging or production.
 */

use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$stage = $argv[1] ?? '';
if (! in_array($stage, ['seed', 'verify'], true)
    || config('app.env') !== 'testing'
    || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'mskba_rbac_ci'
    || config('database.connections.pgsql.host') !== '127.0.0.1') {
    throw new RuntimeException('Refusing to run outside disposable CI PostgreSQL.');
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException('RBAC PostgreSQL migration failed: '.$message);
    }
};

if ($stage === 'seed') {
    $assert(! Schema::hasTable('manager_specializations'), 'database already has manager profiles');
    $assert(! Schema::hasColumn('users', 'staff_title'), 'old schema has staff_title');

    DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_system_role_check');
    DB::statement("ALTER TABLE users ADD CONSTRAINT users_system_role_check CHECK (system_role IS NULL OR system_role IN ('superadmin','admin','moderator','editor','user','system'))");

    $now = now();
    DB::table('users')->insert([
        ['username' => 'ci-rbac-moderator', 'system_role' => 'moderator', 'status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now],
        ['username' => 'ci-rbac-editor', 'system_role' => 'editor', 'status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now],
        ['username' => 'ci-rbac-admin', 'system_role' => 'admin', 'status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now],
        ['username' => 'ci-rbac-user', 'system_role' => 'user', 'status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $ids = DB::table('users')->where('username', 'like', 'ci-rbac-%')->pluck('id', 'username');
    $assert($ids->count() === 4, 'legacy fixtures missing');
    DB::table('user_operational_permissions')->insert([
        ['user_id' => $ids['ci-rbac-moderator'], 'permission' => 'event.create', 'is_allowed' => true, 'created_at' => $now, 'updated_at' => $now],
        ['user_id' => $ids['ci-rbac-editor'], 'permission' => 'content.manage', 'is_allowed' => false, 'created_at' => $now, 'updated_at' => $now],
        ['user_id' => $ids['ci-rbac-user'], 'permission' => 'backoffice.access', 'is_allowed' => true, 'created_at' => $now, 'updated_at' => $now],
    ]);
    echo "Pre-RBAC PostgreSQL fixtures created.\n";
    exit(0);
}

$assert(Schema::hasColumn('users', 'staff_title'), 'staff title missing');
foreach (['system_role_permission_profiles', 'manager_specializations', 'manager_specialization_user'] as $table) {
    $assert(Schema::hasTable($table), $table.' missing');
}
$expected = [
    'ci-rbac-moderator' => ['manager', 'moderator'],
    'ci-rbac-editor' => ['manager', 'editor'],
    'ci-rbac-admin' => ['admin', null],
    'ci-rbac-user' => ['user', null],
];
$checker = app(UserOperationalPermissionChecker::class);
foreach ($expected as $username => [$role, $specialization]) {
    $record = User::query()->where('username', $username)->firstOrFail();
    $assert($record->system_role->value === $role, $username.' wrong role');
    $codes = $record->managerSpecializations()->pluck('code')->all();
    $assert($codes === ($specialization ? [$specialization] : []), $username.' wrong specialization');
}
$moderator = User::query()->where('username', 'ci-rbac-moderator')->firstOrFail();
$editor = User::query()->where('username', 'ci-rbac-editor')->firstOrFail();
$regular = User::query()->where('username', 'ci-rbac-user')->firstOrFail();
$assert($checker->allows($moderator, Permission::CONTENT_MANAGE), 'moderation baseline missing');
$assert($checker->allows($editor, Permission::CONTENT_MANAGE), 'editor baseline missing');
$assert($checker->allows($editor, Permission::BACKOFFICE_ACCESS), 'editor Filament missing');
$assert(! $checker->allows($regular, Permission::BACKOFFICE_ACCESS), 'user gained Filament');
$assert(DB::table('user_operational_permissions')->where('user_id', $regular->id)->where('permission', 'backoffice.access')->where('is_allowed', true)->exists(), 'snapshot lost for user');
$assert(DB::table('user_operational_permissions')->where('user_id', $moderator->id)->where('permission', 'event.create')->where('is_allowed', true)->exists(), 'snapshot lost for moderator');
$assert(DB::table('user_operational_permissions')->where('user_id', $editor->id)->where('permission', 'content.manage')->where('is_allowed', false)->exists(), 'snapshot lost for editor');
$assert(DB::table('manager_specialization_user')->count() === 2, 'assignment count mismatch');
$assert(DB::table('manager_specializations')->count() === 3, 'preset count mismatch');
$assert(DB::table('system_role_permission_profiles')->count() === 4, 'system role profiles missing');
$constraint = DB::selectOne("SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = 'users'::regclass AND conname = 'users_system_role_check'");
$assert($constraint !== null && str_contains($constraint->definition, 'manager'), 'PostgreSQL CHECK lacks manager');

echo "RBAC PostgreSQL migration succeeded: roles, specializations, grants and CHECK constraint.\n";
