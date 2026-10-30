<?php

namespace App\Filament\Backoffice\Resources\Teams;

use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class BackofficeTeamLogo
{
    public function store(Team $team, string $contents): void
    {
        abort_unless(TeamResource::canEdit($team), 403);
        try {
            $image = app(WebpImageNormalizer::class)->normalize($contents, 500);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['logo' => $e->getMessage()]);
        }
        $disk = 'public';
        $path = 'teams/'.$team->id.'/'.Str::uuid().'.webp';
        if (! Storage::disk($disk)->put($path, $image['contents'])) {
            throw ValidationException::withMessages(['logo' => 'Не удалось сохранить логотип.']);
        }
        try {
            DB::transaction(function () use ($team, $image, $disk, $path): void {
                $team = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
                $previous = $team->media()->where('collection', 'team_logo')
                    ->lockForUpdate()->get();
                $team->media()->create([
                    'collection' => 'team_logo',
                    'source' => 'backoffice_upload',
                    'disk' => $disk,
                    'path' => $path,
                    'mime' => 'image/webp',
                    'size' => strlen($image['contents']),
                    'width' => $image['width'],
                    'height' => $image['height'],
                    'is_featured' => true,
                ]);
                foreach ($previous as $old) {
                    $oldDisk = $old->disk;
                    $oldPath = $old->path;
                    $old->delete();
                    DB::afterCommit(fn () => Storage::disk($oldDisk)->delete($oldPath));
                }
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);
            throw $e;
        }
    }

    public function delete(Team $team): void
    {
        abort_unless(TeamResource::canEdit($team), 403);
        DB::transaction(function () use ($team): void {
            $team = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $media = $team->media()->where('collection', 'team_logo')
                ->lockForUpdate()->get();
            foreach ($media as $logo) {
                $disk = $logo->disk;
                $path = $logo->path;
                $logo->delete();
                DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
            }
        });
    }
}
