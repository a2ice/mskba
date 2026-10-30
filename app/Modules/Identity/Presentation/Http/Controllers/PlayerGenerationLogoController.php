<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Domain\Enums\PlayerCharacterGenerationStatusEnum;
use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Media\Domain\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class PlayerGenerationLogoController extends Controller
{
    private const COLLECTION = 'player_generation_logos';

    public function index(Request $request): JsonResponse
    {
        return response()->json(['logos' => $this->owned($request)->latest('id')->get()
            ->map(fn (Media $m) => $this->present($m))->values()], 200,
            ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, WebpImageNormalizer $normalizer): JsonResponse
    {
        $request->validate(['logo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $file = $request->file('logo');
        $contents = $file?->get();
        abort_unless(is_string($contents) && $contents !== '', 422);
        try {
            $image = $normalizer->normalize($contents, 500);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $user = $request->user()->canonical();
        $media = Cache::lock('player-generation-logo:'.$user->id, 12)->block(5, function () use ($request, $user, $image): Media {
            abort_if($this->owned($request)->count() >= 3, 422, 'Можно сохранить не более трёх логотипов.');
            $path = 'player-generation-logos/'.$user->id.'/'.Str::uuid().'.webp';
            abort_unless(Storage::disk('local')->put($path, $image['contents']), 503);
            try {
                return Media::query()->create([
                    'mediable_type' => User::class, 'mediable_id' => $user->id,
                    'collection' => self::COLLECTION, 'disk' => 'local', 'path' => $path,
                    'title' => mb_substr((string) $request->file('logo')->getClientOriginalName(), 0, 200),
                    'mime' => 'image/webp', 'size' => strlen($image['contents']),
                    'width' => $image['width'], 'height' => $image['height'],
                ]);
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($path);
                throw $e;
            }
        });
        return response()->json(['logo' => $this->present($media)], 201,
            ['Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request, Media $media): Response
    {
        abort_unless($this->owned($request)->whereKey($media->id)->exists(), 404);
        $disk = Storage::disk($media->disk);
        abort_unless($disk->exists($media->path), 404);
        return response($disk->get($media->path), 200, [
            'Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        abort_unless($this->owned($request)->whereKey($media->id)->exists(), 404);
        abort_if(PlayerCharacterGeneration::query()
            ->whereIn('user_id', $request->user()->identityIds())
            ->whereIn('status', [
                PlayerCharacterGenerationStatusEnum::PENDING->value,
                PlayerCharacterGenerationStatusEnum::PROCESSING->value,
            ])->where('expires_at', '>', now())
            ->where('reference_media_ids->team_logo', $media->id)->exists(),
            409, 'Логотип используется текущей генерацией.');
        $disk = $media->disk; $path = $media->path;
        $media->delete(); Storage::disk($disk)->delete($path);
        return response()->json(['message' => 'Логотип удалён.']);
    }

    private function owned(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return Media::query()->where('mediable_type', User::class)
            ->whereIn('mediable_id', $request->user()->identityIds())
            ->where('collection', self::COLLECTION);
    }

    private function present(Media $m): array
    {
        return ['id' => (int) $m->id, 'name' => (string) $m->title,
            'url' => route('account.player-character.logos.show', ['media' => $m->id]),
            'width' => (int) $m->width, 'height' => (int) $m->height];
    }
}
