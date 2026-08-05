<?php

use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->string('base_name')->nullable()->after('name');
            $table->string('normalized_name')->nullable()->after('base_name');
            $table->unsignedInteger('name_sequence')->nullable()->after('normalized_name');
            $table->index(['normalized_name', 'status'], 'teams_normalized_name_status_index');
        });

        $sequences = [];
        DB::table('teams')->eachById(function (object $team) use (&$sequences): void {
            $baseName = trim(preg_replace('/\s+/u', ' ', (string) $team->name) ?? (string) $team->name);
            $normalized = str_replace('ё', 'е', Str::lower($baseName));
            $isActivePermanent = $team->temporary_for_event_id === null
                && $team->deleted_at === null
                && $team->status === TeamStatusEnum::ACTIVE->value;
            $sequence = null;
            $displayName = $baseName;

            if ($isActivePermanent) {
                $sequence = ($sequences[$normalized] ?? 0) + 1;
                $sequences[$normalized] = $sequence;
                $displayName = $sequence === 1 ? $baseName : "{$baseName} №{$sequence}";
            }

            DB::table('teams')->where('id', $team->id)->update([
                'name' => $displayName,
                'base_name' => $baseName,
                'normalized_name' => $team->temporary_for_event_id === null ? $normalized : null,
                'name_sequence' => $sequence,
            ]);
        });

        DB::statement("CREATE UNIQUE INDEX teams_active_permanent_name_sequence_unique ON teams (normalized_name, name_sequence) WHERE deleted_at IS NULL AND temporary_for_event_id IS NULL AND status = 'active'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS teams_active_permanent_name_sequence_unique');
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropIndex('teams_normalized_name_status_index');
            $table->dropColumn(['base_name', 'normalized_name', 'name_sequence']);
        });
    }
};
