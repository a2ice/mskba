<?php

namespace App\Modules\Media\Presentation\Http;

use App\Modules\Media\Application\Services\SiteMediaLqip;
use App\Modules\Media\Domain\Models\SiteMediaAsset;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Media\Domain\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class SiteMediaController
{
    public function show(Request $request, string $uuid): Response
    {
        $asset = SiteMediaAsset::query()->where('uuid', $uuid)->firstOrFail();
        return $this->serve($request, $asset->currentMedia);
    }

    public function lqip(Request $request, string $uuid): Response
    {
        $asset = SiteMediaAsset::query()->where('uuid', $uuid)->firstOrFail();
        return $this->serve($request, $asset->currentMedia, true);
    }

    public function previous(Request $request, string $uuid): Response
    {
        $user = auth('web')->user();
        abort_unless($user && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_ACCESS)
            && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_SITE_MEDIA_VIEW), 403);
        $asset = SiteMediaAsset::query()->where('uuid', $uuid)->firstOrFail();
        return $this->serve($request, $asset->previousMedia);
    }

    private function serve(Request $request, ?Media $media, bool $lqip = false): Response
    {
        abort_unless($media && $media->disk === 'public', 404);
        $path = $lqip ? SiteMediaLqip::path($media) : $media->path;
        abort_unless(Storage::disk('public')->exists($path), 404);
        $etag = '"site-media-'.$media->id.($lqip ? '-lqip-'.Storage::disk('public')->lastModified($path).'-'.Storage::disk('public')->size($path) : '').'"';
        $headers = [
            'Cache-Control' => 'public, no-cache, must-revalidate',
            'ETag' => $etag,
            'Content-Type' => $lqip ? 'image/webp' : $media->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ];
        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        $stream = Storage::disk('public')->readStream($path);
        abort_unless(is_resource($stream), 404);

        return response()->stream(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, $headers);
    }
}
