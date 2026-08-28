<?php

use App\Modules\Tournament\Domain\Enums\TournamentEnrollmentPolicyEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tournaments', 'enrollment_policy')) {
            Schema::table('tournaments', function (Blueprint $table): void {
                $table->enum('enrollment_policy', array_column(TournamentEnrollmentPolicyEnum::cases(), 'value'))
                    ->default(TournamentEnrollmentPolicyEnum::FIXED_POOL->value);
            });
        }

        if (! Schema::hasColumn('tournaments', 'round_robin_legs')) {
            Schema::table('tournaments', function (Blueprint $table): void {
                $table->unsignedSmallInteger('round_robin_legs')->default(1);
            });
        }

        if (! Schema::hasColumn('tournaments', 'recruitment_closed_at')) {
            Schema::table('tournaments', function (Blueprint $table): void {
                $table->timestampTz('recruitment_closed_at')->nullable();
            });
        }

        if (! Schema::hasColumn('tournaments', 'recruitment_closed_by_actor_id')) {
            Schema::table('tournaments', function (Blueprint $table): void {
                $table->unsignedBigInteger('recruitment_closed_by_actor_id')->nullable();
            });
        }

        if (! Schema::hasColumn('tournaments', 'tournament_closed_at')) {
            Schema::table('tournaments', function (Blueprint $table): void {
                $table->timestampTz('tournament_closed_at')->nullable();
            });
        }

        if (! Schema::hasColumn('tournaments', 'tournament_closed_by_actor_id')) {
            Schema::table('tournaments', function (Blueprint $table): void {
                $table->unsignedBigInteger('tournament_closed_by_actor_id')->nullable();
            });
        }

        if (! Schema::hasForeignKey('tournaments', ['recruitment_closed_by_actor_id'])) {
            Schema::table('tournaments', function (Blueprint $table): void {
                $table->foreign('recruitment_closed_by_actor_id', 'tournaments_recruitment_closed_actor_fk')
                    ->references('id')
                    ->on('actors')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasForeignKey('tournaments', ['tournament_closed_by_actor_id'])) {
            Schema::table('tournaments', function (Blueprint $table): void {
                $table->foreign('tournament_closed_by_actor_id', 'tournaments_tournament_closed_actor_fk')
                    ->references('id')
                    ->on('actors')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasIndex('tournaments', ['enrollment_policy', 'recruitment_closed_at'])) {
            Schema::table('tournaments', function (Blueprint $table): void {
                $table->index(['enrollment_policy', 'recruitment_closed_at'], 'tournaments_enrollment_open_idx');
            });
        }

        if (! Schema::hasIndex('tournaments', ['tournament_closed_at'])) {
            Schema::table('tournaments', function (Blueprint $table): void {
                $table->index('tournament_closed_at');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            ['enrollment_policy', 'recruitment_closed_at'],
            ['tournament_closed_at'],
        ] as $columns) {
            if ($indexName = $this->indexName($columns)) {
                Schema::table('tournaments', fn (Blueprint $table) => $table->dropIndex($indexName));
            }
        }

        foreach ([
            'recruitment_closed_by_actor_id',
            'tournament_closed_by_actor_id',
        ] as $column) {
            if ($foreignKeyName = $this->foreignKeyName($column)) {
                Schema::table('tournaments', fn (Blueprint $table) => $table->dropForeign($foreignKeyName));
            }
        }

        foreach ([
            'enrollment_policy',
            'round_robin_legs',
            'recruitment_closed_at',
            'recruitment_closed_by_actor_id',
            'tournament_closed_at',
            'tournament_closed_by_actor_id',
        ] as $column) {
            if (Schema::hasColumn('tournaments', $column)) {
                Schema::table('tournaments', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    /** @param list<string> $columns */
    private function indexName(array $columns): ?string
    {
        foreach (Schema::getIndexes('tournaments') as $index) {
            if ($index['columns'] === $columns) {
                return $index['name'];
            }
        }

        return null;
    }

    private function foreignKeyName(string $column): ?string
    {
        foreach (Schema::getForeignKeys('tournaments') as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                return $foreignKey['name'];
            }
        }

        return null;
    }
};
