<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('staff_title', 120)->nullable();
        });
        // PostgreSQL's users_system_role_check was generated from the old enum.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_system_role_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_system_role_check CHECK (system_role IS NULL OR system_role IN ('superadmin','admin','manager','moderator','editor','user','system'))");
        }

        Schema::create('system_role_permission_profiles', function (Blueprint $table): void {
            $table->string('role', 32)->primary();
            $table->json('permissions');
            $table->timestampsTz();
        });

        Schema::create('manager_specializations', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->json('permissions');
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
        });

        Schema::create('manager_specialization_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('manager_specialization_id')->constrained('manager_specializations')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['user_id', 'manager_specialization_id'], 'manager_specialization_user_unique');
        });

        $now = now();
        DB::table('system_role_permission_profiles')->insert([
            ['role' => 'user', 'permissions' => json_encode(['team.create','coordination.create']), 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'manager', 'permissions' => json_encode(['backoffice.access','team.create','coordination.create']), 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'admin', 'permissions' => json_encode(['backoffice.access','backoffice.widget.view','backoffice.widget.create','backoffice.widget.edit','content.manage','team.create','coordination.create','event.create','tournament.create']), 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'system', 'permissions' => json_encode([]), 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('manager_specializations')->insert([
            ['code' => 'moderator', 'name' => 'Модератор', 'permissions' => json_encode([]), 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'editor', 'name' => 'Редактор', 'permissions' => json_encode(['content.manage']), 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'advertiser', 'name' => 'Рекламный менеджер', 'permissions' => json_encode(['operator.presence.view','operator.conversation.handle','operator.marketing.send']), 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Migrate actual legacy staff before the new role is exposed for assignment.
        // The old enum cases remain readable to support historical audit snapshots.
        foreach (['moderator', 'editor'] as $oldRole) {
            $specializationId = DB::table('manager_specializations')->where('code', $oldRole)->value('id');
            DB::table('users')->where('system_role', $oldRole)->orderBy('id')->chunkById(200, function ($users) use ($specializationId, $now): void {
                $rows = [];
                foreach ($users as $user) {
                    $rows[] = ['user_id' => $user->id, 'manager_specialization_id' => $specializationId, 'assigned_by' => null, 'created_at' => $now, 'updated_at' => $now];
                }
                if ($rows !== []) {
                    DB::table('manager_specialization_user')->insert($rows);
                }
            });
            DB::table('users')->where('system_role', $oldRole)->update(['system_role' => 'manager']);
        }
    }

    public function down(): void
    {
        // Migration back is intentionally blocked rather than silently stripping
        // new Manager assignments or mislabeling custom-specialization accounts.
        if (DB::table('users')->where('system_role', 'manager')->exists()) {
            throw new RuntimeException('Cannot roll back staff migration while manager accounts exist. Restore from backup or perform reviewed reverse mapping.');
        }

        Schema::dropIfExists('manager_specialization_user');
        Schema::dropIfExists('manager_specializations');
        Schema::dropIfExists('system_role_permission_profiles');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('staff_title');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_system_role_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_system_role_check CHECK (system_role IS NULL OR system_role IN ('superadmin','admin','moderator','editor','user','system'))");
        }
    }
};