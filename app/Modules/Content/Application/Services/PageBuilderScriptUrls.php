<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Models\PageBuilderScriptAsset;
use Illuminate\Database\Eloquent\Model;

final class PageBuilderScriptUrls
{
    /**
     * Resolve published, enabled resources by owner. The script is executable
     * code explicitly approved by an authorized publisher, not a draft.
     *
     * @param iterable<Model> $owners
     * @return list<string>
     */
    public function forOwners(iterable $owners): array
    {
        $seen = [];
        $urls = [];
        foreach ($owners as $owner) {
            $type = PageBuilderPublisher::type($owner);
            $key = $type.':'.$owner->getKey();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $script = PageBuilderScriptAsset::query()
                ->where('entity_type', $type)
                ->where('entity_id', $owner->getKey())
                ->where('is_enabled', true)
                ->first();
            if ($script === null || $script->published_js === null
                || ! preg_match('/\A[a-f0-9]{64}\z/D', (string) $script->published_hash)) {
                continue;
            }
            $urls[] = route('page-builder.script', [
                'type' => $type,
                'id' => $owner->getKey(),
                'hash' => $script->published_hash,
            ]);
        }

        return $urls;
    }
}
