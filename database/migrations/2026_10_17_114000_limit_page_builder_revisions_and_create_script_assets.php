<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Script storage is inert for now: JS is NOT rendered or executed until
        // the later dedicated permissioned editor + sandboxed preview rollout.
        // Exactly two published sources are stored, plus one editable draft.
        Schema::create('page_builder_script_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 16);
            $table->unsignedBigInteger('entity_id');
            $table->longText('draft_js')->nullable();
            $table->longText('published_js')->nullable();
            $table->longText('previous_js')->nullable();
            $table->char('published_hash', 64)->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['entity_type', 'entity_id'], 'pb_scripts_owner_unique');
        });

        Schema::create('page_builder_script_audit', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 16);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 16);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('content_hash', 64)->nullable();
            $table->timestamp('created_at');
            $table->index(['entity_type', 'entity_id', 'id'], 'pb_script_audit_owner_idx');
        });

        // CSS has its own previous published slot. HTML-only republishing must
        // never evict the CSS rollback point.
        foreach (['page_builder_pages', 'page_builder_templates', 'page_builder_components'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->longText('previous_css')->nullable();
                $table->boolean('has_previous_css')->default(false);
            });
        }

        // Recover the last different CSS version before historical full
        // snapshots are converted to compact audit records.
        $tableFor = ['page' => 'page_builder_pages',
            'template' => 'page_builder_templates',
            'component' => 'page_builder_components'];
        foreach ($tableFor as $kind => $tableName) {
            $rows = DB::table($tableName)->whereNotNull('published_at')->get(['id', 'published_css']);
            foreach ($rows as $owner) {
                $history = DB::table('page_builder_revisions')
                    ->where('entity_type', $kind)->where('entity_id', $owner->id)
                    ->where('action', 'publish')->orderByDesc('id')->get(['snapshot']);
                foreach ($history as $revision) {
                    $snapshot = json_decode((string) $revision->snapshot, true) ?: [];
                    if (array_key_exists('draft_css', $snapshot)
                        && (string) $snapshot['draft_css'] !== (string) $owner->published_css) {
                        DB::table($tableName)->where('id', $owner->id)->update([
                            'previous_css' => $snapshot['draft_css'],
                            'has_previous_css' => true,
                        ]);
                        break;
                    }
                }
            }
        }

        // Existing unlimited full snapshots become bounded, too. Preserve the
        // action/user/date and a hash for audit without retaining old code.
        $owners = DB::table('page_builder_revisions')
            ->select('entity_type', 'entity_id')->distinct()->get();
        foreach ($owners as $owner) {
            $latest = DB::table('page_builder_revisions')
                ->where('entity_type', $owner->entity_type)
                ->where('entity_id', $owner->entity_id)
                ->where('action', 'publish')
                ->orderByDesc('id')->limit(2)->pluck('id')->all();

            DB::table('page_builder_revisions')
                ->where('entity_type', $owner->entity_type)
                ->where('entity_id', $owner->entity_id)
                ->where('action', 'publish')
                ->whereNotIn('id', $latest)
                ->orderBy('id')->chunkById(100, function ($rows): void {
                    foreach ($rows as $row) {
                        DB::table('page_builder_revisions')->where('id', $row->id)->update([
                            'action' => 'publish_audit',
                            'snapshot' => json_encode([
                                'archived' => true,
                                'sha256' => hash('sha256', (string) $row->snapshot),
                            ], JSON_THROW_ON_ERROR),
                        ]);
                    }
                });

            DB::table('page_builder_revisions')
                ->where('entity_type', $owner->entity_type)
                ->where('entity_id', $owner->entity_id)
                ->whereIn('action', ['override_on', 'override_off'])
                ->orderBy('id')->chunkById(100, static function ($rows): void {
                    foreach ($rows as $row) {
                        $full = json_decode((string) $row->snapshot, true) ?: [];
                        DB::table('page_builder_revisions')->where('id', $row->id)->update([
                            'snapshot' => json_encode([
                                'route_name' => $full['route_name'] ?? null,
                                'cms_override_enabled' => $full['cms_override_enabled'] ?? null,
                            ], JSON_THROW_ON_ERROR),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Bounded history is irreversible by design; down only removes the
        // not-yet-executable script staging schema.
        Schema::dropIfExists('page_builder_script_audit');
        Schema::dropIfExists('page_builder_script_assets');
        foreach (['page_builder_pages', 'page_builder_templates', 'page_builder_components'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['previous_css', 'has_previous_css']);
            });
        }
    }
};
