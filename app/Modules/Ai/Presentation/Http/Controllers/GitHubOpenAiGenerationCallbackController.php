<?php

namespace App\Modules\Ai\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Domain\Enums\PlayerCharacterGenerationStatusEnum;
use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class GitHubOpenAiGenerationCallbackController extends Controller
{
    public function __invoke(Request $request, PlayerCharacterGeneration $generation): JsonResponse
    {
        $status = (string) $request->input('status');
        abort_unless(in_array($status, ['processing', 'completed', 'failed'], true), 422);

        $file = $request->file('image');
        $contents = $file instanceof UploadedFile ? $file->get() : '';
        abort_unless(is_string($contents), 422);
        $contentHash = hash('sha256', $contents);
        $metadataHash = $this->metadataHash($request);

        $this->verifySignature($request, $generation->public_id, $status, $contentHash, $metadataHash);

        if ($status === 'processing') {
            PlayerCharacterGeneration::query()
                ->whereKey($generation->id)
                ->where('status', PlayerCharacterGenerationStatusEnum::PENDING->value)
                ->update([
                    'status' => PlayerCharacterGenerationStatusEnum::PROCESSING->value,
                    'provider_run_id' => $this->nullableString($request->input('provider_run_id')),
                    'started_at' => now(),
                    'updated_at' => now(),
                ]);

            return response()->json(['status' => 'accepted']);
        }

        if ($status === 'failed') {
            $this->markFailed($generation, $request);

            return response()->json(['status' => 'accepted']);
        }

        abort_unless($file instanceof UploadedFile, 422);
        abort_if(strlen($contents) > 15 * 1024 * 1024, 413);

        $imageInfo = @getimagesizefromstring($contents);
        abort_unless(is_array($imageInfo) && ($imageInfo['mime'] ?? null) === 'image/png', 422);
        abort_unless($this->hasTransparentCorners($contents), 422);

        $path = sprintf('player-character-generations/%d/%s.png', $generation->user_id, $generation->public_id);

        DB::transaction(function () use ($generation, $contents, $path, $request): void {
            $locked = PlayerCharacterGeneration::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PlayerCharacterGenerationStatusEnum::COMPLETED) {
                return;
            }

            abort_if($locked->status === PlayerCharacterGenerationStatusEnum::FAILED, 409);
            abort_unless(Storage::disk('local')->put($path, $contents), 500);

            $locked->forceFill([
                'status' => PlayerCharacterGenerationStatusEnum::COMPLETED,
                'result_disk' => 'local',
                'result_path' => $path,
                'result_mime' => 'image/png',
                'result_size' => strlen($contents),
                'provider_run_id' => $this->nullableString($request->input('provider_run_id')),
                'error_code' => null,
                'error_message' => null,
                'completed_at' => now(),
            ])->save();
        });

        Log::info('GitHub OpenAI player generation completed.', [
            'generation_id' => $generation->public_id,
            'bytes' => strlen($contents),
            'model' => $this->nullableString($request->input('model')),
        ]);

        return response()->json(['status' => 'accepted']);
    }

    private function verifySignature(
        Request $request,
        string $generationId,
        string $status,
        string $contentHash,
        string $metadataHash,
    ): void {
        $secret = trim((string) config('services.github_openai.callback_secret'));
        abort_if($secret === '', 503);

        $timestamp = (string) $request->header('X-MSKBA-Timestamp', '');
        $providedHash = mb_strtolower((string) $request->header('X-MSKBA-Content-SHA256', ''));
        $providedMetadataHash = mb_strtolower((string) $request->header('X-MSKBA-Metadata-SHA256', ''));
        $providedSignature = mb_strtolower((string) $request->header('X-MSKBA-Signature', ''));
        $tolerance = max(60, (int) config('services.github_openai.callback_tolerance_seconds', 300));

        abort_unless(ctype_digit($timestamp), 401);
        abort_if(abs(now()->timestamp - (int) $timestamp) > $tolerance, 401);
        abort_unless(hash_equals($contentHash, $providedHash), 401);
        abort_unless(hash_equals($metadataHash, $providedMetadataHash), 401);

        $message = implode("\n", [$timestamp, $generationId, $status, $contentHash, $metadataHash]);
        $expected = hash_hmac('sha256', $message, $secret);

        abort_unless(hash_equals($expected, $providedSignature), 401);
    }

    private function metadataHash(Request $request): string
    {
        return hash('sha256', implode("\n", [
            $this->nullableString($request->input('provider_run_id')) ?? '',
            $this->nullableString($request->input('error_code')) ?? '',
            $this->nullableString($request->input('error_message')) ?? '',
            $this->nullableString($request->input('model')) ?? '',
        ]));
    }

    private function markFailed(PlayerCharacterGeneration $generation, Request $request): void
    {
        DB::transaction(function () use ($generation, $request): void {
            $locked = PlayerCharacterGeneration::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal()) {
                return;
            }

            $locked->forceFill([
                'status' => PlayerCharacterGenerationStatusEnum::FAILED,
                'provider_run_id' => $this->nullableString($request->input('provider_run_id')),
                'error_code' => $this->nullableString($request->input('error_code')) ?: 'generation_failed',
                'error_message' => mb_substr(
                    $this->nullableString($request->input('error_message')) ?: 'Не удалось сгенерировать модель игрока.',
                    0,
                    500,
                ),
                'failed_at' => now(),
            ])->save();
        });
    }

    private function hasTransparentCorners(string $contents): bool
    {
        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $points = [[0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1]];

        foreach ($points as [$x, $y]) {
            $alpha = (imagecolorat($image, $x, $y) & 0x7F000000) >> 24;

            if ($alpha < 100) {
                imagedestroy($image);

                return false;
            }
        }

        imagedestroy($image);

        return true;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
