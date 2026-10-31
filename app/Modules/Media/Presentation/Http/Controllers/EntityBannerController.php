<?php

namespace App\Modules\Media\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Media\Application\Services\EntityBannerAccess;
use App\Modules\Media\Application\Services\EntityBannerManager;
use App\Modules\Media\Application\Services\EntityBannerTargets;
use App\Modules\Media\Application\Services\VenueBannerDraftManager;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class EntityBannerController extends Controller
{
    private function entity(Request $request, string $context, string $type, int $id): Model
    {
        abort_unless(in_array($context, ['account', 'backoffice'], true), 404);
        $entity = app(EntityBannerTargets::class)->get($type, $id);
        abort_unless(app(EntityBannerAccess::class)->allows($request, $context, $entity), 403);

        return $entity;
    }

    private function isModeratedVenue(Model $entity, string $context): bool
    {
        return $context === 'account'
            && $entity instanceof Venue
            && $entity->status === VenueStatusEnum::CONFIRMED;
    }

    public function source(Request $request, string $context, string $type, int $id): Response
    {
        $entity = $this->entity($request, $context, $type, $id);
        $banner = $this->isModeratedVenue($entity, $context)
            ? app(VenueBannerDraftManager::class)->preview($entity)
            : $entity->banner;
        abort_unless($banner?->original_disk === 'local' && $banner->original_path, 404);
        abort_unless(Storage::disk('local')->exists($banner->original_path), 404);

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
        EntityBannerManager $banners, VenueBannerDraftManager $drafts,
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

        $file = $request->file('image');
        $original = $file ? file_get_contents($file->getRealPath()) : null;
        if ($file !== null && ! is_string($original)) {
            throw ValidationException::withMessages(['image' => 'Не удалось прочитать загруженный файл.']);
        }

        $moderated = $this->isModeratedVenue($entity, $context);
        if (! $moderated && $file === null && $entity->banner === null) {
            throw ValidationException::withMessages(['image' => 'Сначала выберите изображение.']);
        }

        try {
            if ($moderated) {
                $banner = $drafts->update($entity, $request, $original, $data['crop']);
            } else {
                $banner = $original !== null
                    ? $banners->replace($entity, $original, $data['crop'])
                    : $banners->recrop($entity, $data['crop']);
            }
        } catch (InvalidArgumentException|RuntimeException $error) {
            throw ValidationException::withMessages(['image' => $error->getMessage()]);
        }

        return response()->json([
            'message' => $moderated
                ? 'Баннер сохранён в черновике. Отправьте изменения площадки на модерацию.'
                : 'Баннер сохранён.',
            'url' => $banner->publicUrl(),
        ]);
    }

    public function destroy(
        Request $request, string $context, string $type, int $id,
        EntityBannerManager $banners, VenueBannerDraftManager $drafts,
    ): JsonResponse {
        $entity = $this->entity($request, $context, $type, $id);
        $moderated = $this->isModeratedVenue($entity, $context);
        if ($moderated) {
            $drafts->remove($entity, $request);
        } else {
            $banners->remove($entity);
        }

        return response()->json([
            'message' => $moderated
                ? 'Удаление баннера сохранено в черновике. Отправьте изменения на модерацию.'
                : 'Баннер удалён.',
        ]);
    }
}
