<?php

namespace App\Modules\Portal\Application\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PortalContactContentAudit
{
    /** Report only identifiers and match types; never print stored content or contact values. */
    public function scan(): array
    {
        $tables = [
            'page_builder_pages' => ['draft_html', 'published_html', 'draft_css', 'published_css', 'meta_description', 'published_meta_description'],
            'page_builder_templates' => ['draft_html', 'published_html', 'draft_css', 'published_css'],
            'page_builder_components' => ['draft_html', 'published_html', 'draft_css', 'published_css'],
            'page_builder_script_assets' => ['draft_js', 'published_js'],
            'content_items' => ['short_description', 'full_description'],
        ];
        $patterns = [
            'email' => '~(?:mailto:|[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})~i',
            'phone' => '~(?:tel:|(?<!\d)\+\d[\d ()-]{6,20}\d(?!\d))~i',
            'social' => '~(?:https?://)?(?:t\.me|telegram\.me|vk\.ru|vk\.com)/[a-z0-9_.+-]+~i',
        ];
        $results = [];
        foreach ($tables as $table => $fields) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $fields = array_values(array_intersect($fields, Schema::getColumnListing($table)));
            if ($fields === []) {
                continue;
            }
            DB::table($table)->select(['id', ...$fields])->chunkById(100, function ($rows) use ($table, $fields, $patterns, &$results): void {
                foreach ($rows as $row) {
                    foreach ($fields as $field) {
                        $value = html_entity_decode((string) $row->{$field}, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $types = [];
                        foreach ($patterns as $type => $pattern) {
                            if (preg_match($pattern, $value) === 1) {
                                $types[] = $type;
                            }
                        }
                        if ($types !== []) {
                            $results[] = ['table' => $table, 'id' => $row->id, 'field' => $field, 'types' => implode(',', $types)];
                        }
                    }
                }
            });
        }

        return $results;
    }
}
