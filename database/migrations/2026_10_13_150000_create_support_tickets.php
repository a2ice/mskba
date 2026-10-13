<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('subject', 150);
            $table->string('status', 24)->default('open');
            $table->timestamps();
            $table->index(['user_id', 'id']);
            $table->index(['status', 'id']);
        });
        Schema::create('support_permission_cursors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->unsignedBigInteger('after_ticket_id')->default(0);
            $table->timestamps();
        });
        // Preserve the existing privilege of confirmed superadmins only. Staff
        // promoted or delegated later will receive a fresh monotonic cursor.
        DB::table('users')->where('system_role', 'superadmin')->where('status', 'confirmed')
            ->orderBy('id')->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    DB::table('support_permission_cursors')->insertOrIgnore([
                        'user_id' => $user->id, 'after_ticket_id' => 0,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_permission_cursors');
        Schema::dropIfExists('support_tickets');
    }
};
