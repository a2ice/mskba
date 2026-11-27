<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Match only the two audited editorial articles and their exact legacy
        // portal links. IDs differ between environments; never replace third-party links.
        $titles = [
            'Как заказать изготовление индивидуальной формы' => '',
            'Как стать партнёром MSKBA по экипировке' => 'partnership',
        ];

        foreach ($titles as $title => $purpose) {
            DB::table('content_items')->where('title', $title)
                ->where('content_format', 'safe_html')->whereNull('deleted_at')
                ->orderBy('id')->chunkById(100, function ($rows) use ($purpose): void {
                    foreach ($rows as $row) {
                        $original = (string) $row->full_description;
                        $updated = preg_replace_callback(
                            '~на <a href="mailto:support@mskba\.ru(?:\?subject=([^"]+))?">support@mskba\.ru</a>~u',
                            static function (array $match) use ($purpose): string {
                                $subject = rawurldecode(html_entity_decode($match[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                                if ($subject !== ($purpose === 'partnership' ? 'MSKBA - сотрудничество' : '')) {
                                    return $match[0];
                                }

                                return $purpose === 'partnership' ? '[portal-contact-email partnership]' : '[portal-contact-email]';
                            },
                            $original,
                        );
                        if ($updated !== null && $updated !== $original) {
                            DB::table('content_items')->where('id', $row->id)
                                ->where('full_description', $original)->update(['full_description' => $updated, 'updated_at' => now()]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        // Intentionally do not restore obsolete public addresses or overwrite
        // subsequent editorial changes. Preserve the migrated content.
    }
};
