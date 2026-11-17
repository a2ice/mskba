<?php

namespace App\Modules\Uniform\Application\Services;

use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Uniform\Domain\Models\UniformDesign;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class UniformLogoService
{
    public function __construct(private readonly WebpImageNormalizer $normalizer) {}

    public function upload(UniformDesign $design, UploadedFile $file): void
    {
        $image = $this->normalizer->normalize($file->get(), 500);
        $path = 'uniform-designs/'.$design->user_id.'/'.Str::uuid().'.webp';
        abort_unless(Storage::disk('local')->put($path, $image['contents']), 503, 'Не удалось сохранить логотип.');

        try {
            $old = $design->customLogo()->first();
            $new = Media::query()->create([
                'mediable_type' => $design->getMorphClass(),
                'mediable_id' => $design->id,
                'collection' => UniformDesign::LOGO_COLLECTION,
                'disk' => 'local',
                'path' => $path,
                'mime' => 'image/webp',
                'title' => mb_substr($file->getClientOriginalName(), 0, 190),
                'size' => strlen($image['contents']),
                'width' => $image['width'],
                'height' => $image['height'],
                'is_featured' => true,
            ]);
            if ($old !== null) {
                $old->forceDelete();
                Storage::disk($old->disk)->delete($old->path);
            }
            $design->setRelation('customLogo', $new);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function remove(UniformDesign $design): void
    {
        foreach ($design->customLogo()->get() as $logo) {
            $logo->forceDelete();
            Storage::disk($logo->disk)->delete($logo->path);
        }
    }
}
