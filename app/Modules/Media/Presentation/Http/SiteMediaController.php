<?php

namespace App\Modules\Media\Presentation\Http;

use App\Modules\Media\Domain\Models\SiteMediaAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class SiteMediaController
{
    public function show(Request $request, string $uuid): Response
    {
        $asset = SiteMediaAsset::query()->where('uuid', $uuid)->firstOrFail();
        $media = $asset->currentMedia;
        abort_unless($media && $media->disk === 'public' && Storage::disk('public')->exists($media->path), 404);
        $etag = '"site-media-'.$media->id.'"';
        $headers = [
            'Cache-Control' => 'public, no-cache, must-revalidate',
            'ETag' => $etag,
            'Content-Type' => $media->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ];
        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        $stream = Storage::disk('public')->readStream($media->path);
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
