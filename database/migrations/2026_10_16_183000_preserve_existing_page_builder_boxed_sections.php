<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Before this change, the Page Builder canvas itself imposed a max width.
     * Preserve the visual width of pages written before the fluid canvas by
     * inserting an explicit, editor-visible .container in both snapshots.
     * Future content is never auto-wrapped.
     */
    public function up(): void
    {
        if (! Schema::hasTable('page_builder_pages')) {
            return;
        }

        DB::table('page_builder_pages')->orderBy('id')->chunkById(100, function ($pages): void {
            foreach ($pages as $page) {
                $draft = $this->boxed($page->draft_html);
                $published = $this->boxed($page->published_html);
                $changes = [];

                if ($draft !== $page->draft_html) {
                    $changes['draft_html'] = $draft;
                }
                if ($published !== $page->published_html) {
                    $changes['published_html'] = $published;
                }
                if ($changes !== []) {
                    DB::table('page_builder_pages')->where('id', $page->id)->update($changes);
                }
            }
        });
    }

    public function down(): void
    {
        // Do not destroy editor-authored markup on rollback. Explicit
        // .container wrappers are harmless with the previous CSS as well.
    }

    private function boxed(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        // Already explicitly boxed: do not introduce nested containers.
        if (preg_match('~^\s*<(?:div|section|article|main)\b[^>]*\bclass\s*=\s*["\'][^"\']*\bcontainer\b~i', $html) === 1) {
            return $html;
        }

        return '<div class="container">'.$html.'</div>';
    }
};
