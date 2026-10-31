<?php

namespace App\Modules\Media\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Media\Application\Services\EntityBannerAccess;
use App\Modules\Media\Application\Services\EntityBannerManager;
use App\Modules\Media\Application\Services\EntityBannerTargets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class EntityBannerController extends Controller
{
    private function entity(Request $request, string $context, string $type, int $id): \Illuminate\Database\Eloquent\Model
    {
        abort_unless(in_array($context, ['account', 'backoffice'], true), 404);
        $entity = app(EntityBannerTargets::class)->get($type, $id);
        abort_unless(app(EntityBannerAccess::class)->allows($request, $context, $entity), 403);

        return $entity;
    }

    public function source(Request $request, string $context, string $type, int $id): Response
    {
        $entity = $this->entity($request, $context, $type, $id);
        $banner = $entity->banner;
        abort_unless($banner?->original_disk === 'local' && $banner->original_path, 404);

        $contents = Storage::disk('local')->get($banner->original_path);
        $mime = (string) (@getimagesizefromstring($contents)['mime'] ?? '');
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(
        Request $request, string $context, string $type, int $id,
        EntityBannerManager $banners,
    ): JsonResponse {
        $entity = $this->entity($request, $context, $type, $id);
        $data = $request->validate([
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'crop' => ['required', 'array'],
            'crop.x' => ['required', 'numeric', 'between:0,1'],
            'crop.y' => ['required', 'numeric', 'between:0,1'],
            'crop.width' => ['required', 'numeric', 'gt:0', 'lte:1'],
            'crop.height' => ['required', 'numeric', 'gt:0', 'lte:1'],
        ]);

        if (! $request->hasFile('image') && $entity->banner === null) {
            throw ValidationException::withMessages(['image' => 'Сначала выберите изображение.']);
        }

        try {
            $banner = $request->hasFile('image')
                ? $banners->replace($entity, file_get_contents($request->file('image')->getRealPath()), $data['crop'])
                : $banners->recrop($entity, $data['crop']);
        } catch (InvalidArgumentException|RuntimeException $error) {
            throw ValidationException::withMessages(['image' => $error->getMessage()]);
        }

        return response()->json(['message' => 'Баннер сохранён.', 'url' => $banner->publicUrl()]);
    }

    public function destroy(
        Request $request, string $context, string $type, int $id,
        EntityBannerManager $banners,
    ): JsonResponse {
        $entity = $this->entity($request, $context, $type, $id);
        $banners->remove($entity);

        return response()->json(['message' => 'Баннер удалён.']);
    }
}
