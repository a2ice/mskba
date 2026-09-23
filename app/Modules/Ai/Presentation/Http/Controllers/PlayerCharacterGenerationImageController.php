<?php

namespace App\Modules\Ai\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Domain\Enums\PlayerCharacterGenerationStatusEnum;
use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class PlayerCharacterGenerationImageController extends Controller
{
    public function __invoke(Request $request, PlayerCharacterGeneration $generation): Response
    {
        abort_unless(in_array((int) $generation->user_id, $request->user()->identityIds(), true), 404);
        abort_unless($generation->status === PlayerCharacterGenerationStatusEnum::COMPLETED, 404);
        abort_if($generation->result_disk === null || $generation->result_path === null, 404);

        $disk = Storage::disk($generation->result_disk);
        abort_unless($disk->exists($generation->result_path), 404);

        return response(
            $disk->get($generation->result_path),
            200,
            [
                'Content-Type' => $generation->result_mime ?: 'image/png',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
