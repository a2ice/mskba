<?php

use App\Modules\Event\Application\Services\EventContractResponsibilityService;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\EventParticipant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->foreignId('responsibility_contract_membership_id')
                ->nullable()->constrained('contract_memberships')->nullOnDelete();
        });

        $contracts = app(EventContractResponsibilityService::class);

        // Idempotent legacy import. Ownership is created independently of
        // attendance and only when the organizer has a canonical user.
        Event::query()->withTrashed()->orderBy('id')->chunkById(200, function ($events) use ($contracts): void {
            foreach ($events as $event) {
                $contracts->ensureOwner($event);
            }
        });

        EventParticipant::query()->whereNotNull('responsibility_status')
            ->orderBy('id')->chunkById(200, function ($participants) use ($contracts): void {
                foreach ($participants as $participant) {
                    $contracts->backfillParticipant($participant);
                }
            });
    }

    public function down(): void
    {
        // Keep contract history on rollback; only remove the pointer.
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('responsibility_contract_membership_id');
        });
    }
};
