<?php

namespace App\Modules\Content\Presentation\Http;

use App\Http\Controllers\Controller;
use App\Modules\Content\Domain\Models\PageBuilderScriptAsset;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PageBuilderScriptController extends Controller
{
    private function asset(string $type, int $id): PageBuilderScriptAsset
    {
        abort_unless(in_array($type, ['page', 'template', 'component'], true), 404);

        return PageBuilderScriptAsset::query()
            ->where('entity_type', $type)
            ->where('entity_id', $id)->firstOrFail();
    }

    public function show(string $type, int $id, string $hash): Response
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/D', $hash) === 1, 404);
        $asset = $this->asset($type, $id);
        // Only the ACTIVE version is publicly served. Previous code remains
        // in the DB for rollback, but must not be callable from a public URL.
        abort_unless($asset->is_enabled
            && hash_equals((string) $asset->published_hash, $hash)
            && $asset->published_js !== null, 404);

        return response((string) $asset->published_js, 200, [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=0, must-revalidate',
            'ETag' => '"'.$hash.'"',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * Authenticated source is executed only within an opaque-origin iframe.
     * Neither normal page preview nor Filament's own origin executes drafts.
     */
    public function preview(Request $request, string $type, int $id): Response
    {
        $user = $request->user('web');
        $manager = app(BackofficePermissionManager::class);
        abort_unless($user
            && $manager->allows($user, Permission::BACKOFFICE_ACCESS)
            && $manager->allows($user, Permission::BACKOFFICE_BUILDER_VIEW)
            && $manager->allows($user, Permission::BACKOFFICE_BUILDER_JS_VIEW), 403);
        $asset = $this->asset($type, $id);
        // Base64 prevents script-tag termination and HTML injection.
        $encoded = base64_encode((string) ($asset->draft_js ?? ''));
        $html = '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            .'<title>Песочница JavaScript</title>'
            .'<style>body{font:16px system-ui;padding:20px;color:#18242d}'
            .'button{padding:10px 20px;border:1px solid #aaa;border-radius:8px;cursor:pointer}'
            .'#result{margin-top:12px}</style></head><body>'
            .'<h3>Изолированная песочница (не реальная страница)</h3>'
            .'<p>Скрипт выполняется без доступа к аккаунту, сети и DOM портала.</p>'
            .'<button id="demo-button">Тестовая кнопка</button><div id="result"></div>'
            .'<script>try {(0,eval)(new TextDecoder().decode(Uint8Array.from(atob("'.$encoded.'"), c => c.charCodeAt(0))));}'
            .'catch(e){document.getElementById("result").textContent=String(e);}</script>'
            .'</body></html>';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox allow-scripts; default-src 'none'; "
                ."script-src 'unsafe-inline' 'unsafe-eval'; style-src 'unsafe-inline'; "
                ."connect-src 'none'; img-src 'none'; font-src 'none'; media-src 'none'; "
                ."frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; "
                ."frame-ancestors 'self'",
        ]);
    }
}
