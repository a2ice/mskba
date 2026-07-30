<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $assignments = DB::table('event_participants as child_participant')
                ->join('events as child_event', 'child_event.id', '=', 'child_participant.event_id')
                ->join('event_participants as parent_participant', function ($join): void {
                    $join->on('parent_participant.event_id', '=', 'child_event.parent_event_id')
                        ->on('parent_participant.user_id', '=', 'child_participant.user_id');
                })
                ->whereNotNull('child_event.parent_event_id')
                ->whereNotNull('child_participant.responsibility_status')
                ->select([
                    'child_participant.id as child_participant_id',
                    'child_participant.responsibility_status',
                    'child_participant.responsibility_requested_by_user_id',
                    'child_participant.responsibility_requested_at',
                    'child_participant.responsibility_responded_at',
                    'parent_participant.id as parent_participant_id',
                ])
                ->orderBy('child_participant.id')
                ->get();

            $priority = [
                'declined' => 1,
                'pending' => 2,
                'accepted' => 3,
            ];

            foreach ($assignments as $assignment) {
                $parent = DB::table('event_participants')
                    ->where('id', $assignment->parent_participant_id)
                    ->lockForUpdate()
                    ->first();

                if ($parent === null) {
                    continue;
                }

                $currentPriority = $priority[$parent->responsibility_status] ?? 0;
                $incomingPriority = $priority[$assignment->responsibility_status] ?? 0;

                if ($incomingPriority >= $currentPriority) {
                    DB::table('event_participants')
                        ->where('id', $assignment->parent_participant_id)
                        ->update([
                            'responsibility_status' => $assignment->responsibility_status,
                            'responsibility_requested_by_user_id' => $assignment->responsibility_requested_by_user_id,
                            'responsibility_requested_at' => $assignment->responsibility_requested_at,
                            'responsibility_responded_at' => $assignment->responsibility_responded_at,
                            'updated_at' => now(),
                        ]);
                }

                DB::table('event_participants')
                    ->where('id', $assignment->child_participant_id)
                    ->update([
                        'responsibility_status' => null,
                        'responsibility_requested_by_user_id' => null,
                        'responsibility_requested_at' => null,
                        'responsibility_responded_at' => null,
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function down(): void
    {
        // This is a one-way normalization: a responsibility belongs to the
        // parent event and cannot be reliably assigned back to one mini-game.
    }
};
