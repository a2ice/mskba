<?php

namespace App\Modules\Ai\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Application\Services\PlayerCharacterGenerationBilling;
use App\Modules\Ai\Domain\Enums\PlayerCharacterGenerationStatusEnum;
use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlayerCharacterGenerationStatusController extends Controller
{
    public function __construct(private readonly PlayerCharacterGenerationBilling $billing) {}

    public function __invoke(Request $request, PlayerCharacterGeneration $generation): JsonResponse
    {
        abort_unless(in_array((int) $generation->user_id, $request->user()->identityIds(), true), 404);

        if (! $generation->status->isTerminal() && $generation->expires_at?->isPast()) {
            $updated = PlayerCharacterGeneration::query()
                ->whereKey($generation->id)
                ->whereIn('status', [
                    PlayerCharacterGenerationStatusEnum::PENDING->value,
                    PlayerCharacterGenerationStatusEnum::PROCESSING->value,
                ])
                ->update([
                    'status' => PlayerCharacterGenerationStatusEnum::FAILED->value,
                    'error_code' => 'generation_timeout',
                    'error_message' => 'Генерация не завершилась в отведённое время.',
                    'failed_at' => now(),
                    'updated_at' => now(),
                ]);

            $generation->refresh();

            if ($updated > 0) {
                $this->billing->refund($generation);
            }
        }

        $payload = [
            'generation_id' => $generation->public_id,
            'status' => $generation->status->value,
            'message' => $generation->status->label(),
        ];

        if ($generation->status === PlayerCharacterGenerationStatusEnum::COMPLETED) {
            $payload['image_url'] = route('account.player-character.generations.image', [
                'generation' => $generation->public_id,
            ]).'?v='.$generation->updated_at?->getTimestamp();
        }

        if ($generation->status === PlayerCharacterGenerationStatusEnum::FAILED) {
            $payload['code'] = $generation->error_code ?: 'generation_failed';
            $payload['message'] = $generation->error_message ?: 'Не удалось сгенерировать модель игрока.';
        }

        return response()->json($payload, 200, ['Cache-Control' => 'private, no-store']);
    }
}
