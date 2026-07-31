<?php

use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_responsibility_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_participant_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 64);
            $table->timestamps();
            $table->unique(['event_participant_id', 'permission'], 'event_responsibility_permission_unique');
        });

        $now = now();
        DB::table('event_participants')
            ->whereIn('responsibility_status', [
                EventResponsibilityStatusEnum::PENDING->value,
                EventResponsibilityStatusEnum::ACCEPTED->value,
            ])
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($participants) use ($now): void {
                $rows = [];
                foreach ($participants as $participant) {
                    foreach (EventResponsibilityPermissionEnum::cases() as $permission) {
                        $rows[] = [
                            'event_participant_id' => $participant->id,
                            'permission' => $permission->value,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
                if ($rows !== []) {
                    DB::table('event_responsibility_permissions')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_responsibility_permissions');
    }
};
