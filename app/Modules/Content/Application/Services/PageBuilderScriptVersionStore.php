<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Models\PageBuilderScriptAsset;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Inert two-slot JS draft/publication storage. Never inject these scripts into
 * HTML here; execution awaits a separate secure editor and preview rollout.
 */
final class PageBuilderScriptVersionStore
{
    public function __construct(private readonly BackofficePermissionManager $permissions) {}

    private function authorize(User $actor, Permission $action): void
    {
        abort_unless(
            $this->permissions->allows($actor, Permission::BACKOFFICE_ACCESS)
            && $this->permissions->allows($actor, $action), 403,
        );
    }

    private function owner(Model $owner): array
    {
        return [PageBuilderPublisher::type($owner), (int) $owner->getKey()];
    }

    public function saveDraft(User $actor, Model $owner, ?string $source): PageBuilderScriptAsset
    {
        $this->authorize($actor, Permission::BACKOFFICE_BUILDER_JS_EDIT);
        abort_unless($this->permissions->allows($actor, Permission::BACKOFFICE_BUILDER_EDIT), 403);
        abort_if(strlen((string) $source) > 100_000, 422, 'JavaScript превышает лимит.');

        [$type, $id] = $this->owner($owner);

        return DB::transaction(static function () use ($type, $id, $source): PageBuilderScriptAsset {
            $script = PageBuilderScriptAsset::query()->firstOrCreate(
                ['entity_type' => $type, 'entity_id' => $id],
                ['is_enabled' => false],
            );
            $script->update(['draft_js' => $source]);

            return $script->refresh();
        });
    }

    public function publish(User $actor, Model $owner): PageBuilderScriptAsset
    {
        $this->authorize($actor, Permission::BACKOFFICE_BUILDER_JS_PUBLISH);
        abort_unless($this->permissions->allows($actor, Permission::BACKOFFICE_BUILDER_PUBLISH), 403);
        [$type, $id] = $this->owner($owner);

        return DB::transaction(static function () use ($type, $id, $actor): PageBuilderScriptAsset {
            $script = PageBuilderScriptAsset::query()
                ->where('entity_type', $type)->where('entity_id', $id)
                ->lockForUpdate()->firstOrFail();
            $next = (string) $script->draft_js;
            $hash = hash('sha256', $next);
            if ($hash !== $script->published_hash) {
                $script->previous_js = $script->published_js;
                $script->previous_hash = $script->published_hash;
                $script->published_js = $next;
                $script->published_hash = $hash;
                $script->published_at = now();
                $script->save();
                DB::table('page_builder_script_audit')->insert([
                    'entity_type' => $type,
                    'entity_id' => $id,
                    'action' => 'publish',
                    'user_id' => $actor->id,
                    'content_hash' => $hash,
                    'created_at' => now(),
                ]);
            }

            return $script->refresh();
        });
    }


    /**
     * Emergency kill switch. No physical asset is created or removed.
     * Fresh page responses immediately omit the script URL.
     */
    public function setEnabled(User $actor, Model $owner, bool $enabled): PageBuilderScriptAsset
    {
        $this->authorize($actor, Permission::BACKOFFICE_BUILDER_JS_PUBLISH);
        abort_unless($this->permissions->allows($actor, Permission::BACKOFFICE_BUILDER_PUBLISH), 403);
        [$type, $id] = $this->owner($owner);

        return DB::transaction(static function () use ($type, $id, $actor, $enabled): PageBuilderScriptAsset {
            $script = PageBuilderScriptAsset::query()
                ->where('entity_type', $type)->where('entity_id', $id)
                ->lockForUpdate()->firstOrFail();
            abort_if($enabled && ($script->published_hash === null || $script->published_js === null), 409,
                'Сначала опубликуй JavaScript.');
            if ((bool) $script->is_enabled !== $enabled) {
                $script->is_enabled = $enabled;
                $script->save();
                DB::table('page_builder_script_audit')->insert([
                    'entity_type' => $type,
                    'entity_id' => $id,
                    'action' => $enabled ? 'enable' : 'disable',
                    'user_id' => $actor->id,
                    'content_hash' => $script->published_hash,
                    'created_at' => now(),
                ]);
            }

            return $script->refresh();
        });
    }

    public function rollback(User $actor, Model $owner): PageBuilderScriptAsset
    {
        $this->authorize($actor, Permission::BACKOFFICE_BUILDER_JS_PUBLISH);
        abort_unless($this->permissions->allows($actor, Permission::BACKOFFICE_BUILDER_PUBLISH), 403);
        [$type, $id] = $this->owner($owner);

        return DB::transaction(static function () use ($type, $id, $actor): PageBuilderScriptAsset {
            $script = PageBuilderScriptAsset::query()
                ->where('entity_type', $type)->where('entity_id', $id)
                ->lockForUpdate()->firstOrFail();
            abort_if($script->previous_hash === null, 409, 'Предыдущая версия отсутствует.');
            [$script->published_js, $script->previous_js] = [$script->previous_js, $script->published_js];
            [$script->published_hash, $script->previous_hash] = [$script->previous_hash, $script->published_hash];
            $script->published_at = now();
            $script->save();
            DB::table('page_builder_script_audit')->insert([
                'entity_type' => $type,
                'entity_id' => $id,
                'action' => 'rollback',
                'user_id' => $actor->id,
                'content_hash' => $script->published_hash,
                'created_at' => now(),
            ]);

            return $script->refresh();
        });
    }
}
