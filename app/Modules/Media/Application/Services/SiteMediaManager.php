<?php

namespace App\Modules\Media\Application\Services;

use App\Modules\Media\Domain\Models\Media;
use App\Modules\Media\Domain\Models\SiteMediaAsset;
use App\Modules\Media\Domain\Models\SiteMediaFolder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class SiteMediaManager
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_DIMENSION = 6000;

    public function __construct(private readonly WebpImageNormalizer $normalizer, private readonly SiteMediaUsage $usage, private readonly SiteMediaLqip $lqip) {}

    public function upload(UploadedFile $file, ?int $folderId = null, ?string $title = null, bool $optimize = false): SiteMediaAsset
    {
        $contents = file_get_contents($file->getRealPath());
        if (! is_string($contents)) {
            throw ValidationException::withMessages(['file' => 'Не удалось прочитать файл.']);
        }

        return $this->importBytes($contents, $title ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            $file->getClientOriginalName(), $folderId, $optimize);
    }

    public function importBytes(
        string $contents,
        string $title,
        string $originalName = '',
        ?int $folderId = null,
        bool $optimize = false,
        ?string $legacyPath = null,
    ): SiteMediaAsset {
        if ($legacyPath !== null && ($existing = SiteMediaAsset::query()->where('legacy_path', $legacyPath)->first())) {
            return $existing;
        }
        if ($folderId !== null && ! SiteMediaFolder::query()->whereKey($folderId)->exists()) {
            throw ValidationException::withMessages(['folder_id' => 'Папка не найдена.']);
        }
        $uuid = (string) Str::uuid();
        $prepared = $this->prepare($contents, $optimize);
        $path = $this->pathFor($uuid, $prepared['extension']);
        $this->write($path, $prepared['contents']);

        try {
            return DB::transaction(function () use ($uuid, $folderId, $title, $originalName, $legacyPath, $path, $prepared): SiteMediaAsset {
                $asset = SiteMediaAsset::query()->create([
                    'uuid' => $uuid, 'folder_id' => $folderId, 'created_by' => auth('web')->id(),
                    'title' => trim($title) ?: 'Изображение', 'original_name' => mb_substr($originalName, 0, 255),
                    'legacy_path' => $legacyPath,
                ]);
                $media = $this->version($asset, $path, $prepared);
                $this->generatePreviewWithoutBlockingUpload($media);
                $asset->update(['current_media_id' => $media->id]);

                return $asset->refresh();
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete([$path, $this->lqipPathFromSource($path)]);
            if ($legacyPath !== null && ($existing = SiteMediaAsset::query()->where('legacy_path', $legacyPath)->first())) {
                return $existing;
            }
            throw $exception;
        }
    }

    public function replace(SiteMediaAsset $asset, UploadedFile $file, bool $optimize = false): SiteMediaAsset
    {
        $contents = file_get_contents($file->getRealPath());
        if (! is_string($contents)) {
            throw ValidationException::withMessages(['file' => 'Не удалось прочитать файл.']);
        }

        return $this->replaceBytes($asset, $contents, $optimize);
    }

    public function optimize(SiteMediaAsset $asset): SiteMediaAsset
    {
        $asset->load('currentMedia');
        abort_unless($asset->currentMedia, 404);

        $contents = Storage::disk('public')->get($asset->currentMedia->path);
        if (! is_string($contents)) {
            throw new RuntimeException('Текущая версия файла недоступна.');
        }

        return $this->replaceBytes($asset, $contents, true);
    }

    public function replaceBytes(SiteMediaAsset $asset, string $contents, bool $optimize = false): SiteMediaAsset
    {
        $prepared = $this->prepare($contents, $optimize);
        $path = $this->pathFor($asset->uuid, $prepared['extension']);
        $this->write($path, $prepared['contents']);

        try {
            return DB::transaction(function () use ($asset, $path, $prepared): SiteMediaAsset {
                $locked = SiteMediaAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
                $oldPrevious = $locked->previousMedia;
                $new = $this->version($locked, $path, $prepared);
                $this->generatePreviewWithoutBlockingUpload($new);
                $locked->update([
                    'current_media_id' => $new->id,
                    'previous_media_id' => $locked->current_media_id,
                ]);

                if ($oldPrevious) {
                    $oldPrevious->delete();
                    DB::afterCommit(static function () use ($oldPrevious): void {
                        Storage::disk('public')->delete([$oldPrevious->path, SiteMediaLqip::path($oldPrevious)]);
                    });
                }

                return $locked->refresh();
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete([$path, $this->lqipPathFromSource($path)]);
            throw $exception;
        }
    }

    public function restore(SiteMediaAsset $asset): SiteMediaAsset
    {
        return DB::transaction(function () use ($asset): SiteMediaAsset {
            $locked = SiteMediaAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if (! $locked->previous_media_id) {
                throw ValidationException::withMessages(['previous_media_id' => 'Предыдущей версии нет.']);
            }
            $locked->update([
                'current_media_id' => $locked->previous_media_id,
                'previous_media_id' => $locked->current_media_id,
            ]);

            return $locked->refresh();
        });
    }

    public function delete(SiteMediaAsset $asset): void
    {
        DB::transaction(function () use ($asset): void {
            $locked = SiteMediaAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $uses = $this->usage->references($locked, true);
            if ($uses !== []) {
                throw ValidationException::withMessages([
                    'asset' => 'Изображение используется в опубликованном контенте: '.implode(', ', $uses),
                ]);
            }
            $versions = $locked->versions()->get();
            $paths = $versions->flatMap(fn (Media $media): array => [$media->path, SiteMediaLqip::path($media)])->all();
            $versions->each->delete();
            $locked->delete();
            DB::afterCommit(static function () use ($paths): void {
                Storage::disk('public')->delete($paths);
            });
        });
    }

    /** Rebuild a missing/obsolete derivative without changing either Media version. */
    public function generateLqip(SiteMediaAsset $asset, bool $force = false): bool
    {
        $asset->load('currentMedia');

        return $asset->currentMedia ? $this->lqip->generate($asset->currentMedia, $force) : false;
    }

    private function generatePreviewWithoutBlockingUpload(Media $media): void
    {
        try {
            $this->lqip->generate($media);
        } catch (Throwable $exception) {
            // Original upload/replace must remain usable on hosts without GD.
            report($exception);
        }
    }

    private function lqipPathFromSource(string $source): string
    {
        return dirname($source).'/'.pathinfo($source, PATHINFO_FILENAME).'.lqip.webp';
    }

    /** @return array{contents:string,mime:string,extension:string,width:int,height:int} */
    private function prepare(string $contents, bool $optimize): array
    {
        if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Размер изображения не должен превышать 10 МБ.']);
        }
        $info = @getimagesizefromstring($contents);
        $mime = $info['mime'] ?? null;
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1
            || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            throw ValidationException::withMessages(['file' => 'Допускаются JPEG, PNG, WebP размером до 6000×6000.']);
        }
        if ($optimize) {
            $image = $this->normalizer->normalize($contents, self::MAX_DIMENSION, self::MAX_BYTES);
            return ['contents' => $image['contents'], 'mime' => 'image/webp', 'extension' => 'webp',
                'width' => $image['width'], 'height' => $image['height']];
        }

        return ['contents' => $contents, 'mime' => $mime, 'width' => $info[0], 'height' => $info[1], 'extension' => match ($mime) {
            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg',
        }];
    }

    private function pathFor(string $uuid, string $extension): string
    {
        return 'site-media/'.$uuid.'/'.Str::uuid().'.'.$extension;
    }

    private function write(string $path, string $contents): void
    {
        if (! Storage::disk('public')->put($path, $contents)) {
            throw new RuntimeException('Не удалось записать изображение в постоянное хранилище.');
        }
    }

    /** @param array{contents:string,mime:string,extension:string,width:int,height:int} $data */
    private function version(SiteMediaAsset $asset, string $path, array $data): Media
    {
        return $asset->versions()->create([
            'collection' => 'site_assets', 'source' => 'upload', 'disk' => 'public',
            'path' => $path, 'mime' => $data['mime'], 'size' => strlen($data['contents']),
            'title' => $asset->title, 'width' => $data['width'], 'height' => $data['height'],
        ]);
    }
}
