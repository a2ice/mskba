<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Http\Controllers\Controller;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

final class BackofficeVenuePhotoOriginalController extends Controller
{
    public function __invoke(Venue $venue, int $photo): Response
    {
        abort_unless(VenueResource::canEdit($venue), 403);

        $media = $venue->media()
            ->where('collection', 'gallery')
            ->findOrFail($photo);

        $disk = $media->original_path ? ($media->original_disk ?: $media->disk) : $media->disk;
        $path = $media->original_path ?: $media->path;

        abort_unless(Storage::disk($disk)->exists($path), 404);

        $bytes = Storage::disk($disk)->get($path);
        $mime = getimagesizefromstring($bytes)['mime'] ?? null;
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
