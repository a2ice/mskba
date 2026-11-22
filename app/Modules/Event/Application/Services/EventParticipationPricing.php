<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class EventParticipationPricing
{
    public const DEFAULT_COMMENT = 'Стоимость участия рассчитана исходя из общей стоимости мероприятия и планируемого числа участников. Если участников будет больше, разницу вернут; если меньше — стоимость может увеличиться.';

    public static function rules(): array
    {
        return [
            'is_paid' => ['sometimes', 'boolean'],
            'total_cost' => ['nullable', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'participant_cost' => ['nullable', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'participant_cost_auto' => ['sometimes', 'boolean'],
            'cost_comment' => ['nullable', 'string', 'max:2000'],
            'save_cost_comment_default' => ['sometimes', 'boolean'],
        ];
    }

    public static function defaultComment(?User $user): string
    {
        $user = $user?->canonical();

        return $user?->default_event_cost_comment ?? self::DEFAULT_COMMENT;
    }

    /** Price is an organizer's estimate; it is never derived from a rental quote. */
    public function attributes(Actor $actor, array $data, ?Event $event = null): array
    {
        if (! array_key_exists('is_paid', $data)) {
            if ($event?->is_paid && $event->participant_cost_auto && array_key_exists('max_participants', $data)) {
                return ['participant_cost_minor' => $this->share($event->total_cost_minor, $data['max_participants'])];
            }

            return [];
        }

        $validated = Validator::make($data, self::rules());
        if ($validated->fails()) {
            throw new ValidationException($validated);
        }
        if (! filter_var($data['is_paid'], FILTER_VALIDATE_BOOLEAN)) {
            return [
                'is_paid' => false, 'total_cost_minor' => null, 'participant_cost_minor' => null,
                'participant_cost_auto' => true, 'cost_comment' => null,
            ];
        }

        $total = $this->minor($data['total_cost'] ?? null, 'total_cost');
        $auto = filter_var($data['participant_cost_auto'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $participant = $auto
            ? $this->share($total, $data['max_participants'] ?? null)
            : $this->minor($data['participant_cost'] ?? null, 'participant_cost');
        $comment = trim((string) ($data['cost_comment'] ?? $event?->cost_comment ?? self::defaultComment($actor->user)));
        if ($comment === '') {
            $comment = self::defaultComment($actor->user);
        }
        if (filter_var($data['save_cost_comment_default'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $user = $actor->user?->canonical();
            if ($user !== null) {
                DB::table('users')->where('id', $user->id)->update(['default_event_cost_comment' => $comment]);
                $user->setAttribute('default_event_cost_comment', $comment);
            }
        }

        return [
            'is_paid' => true, 'total_cost_minor' => $total, 'participant_cost_minor' => $participant,
            'participant_cost_auto' => $auto, 'cost_comment' => $comment,
        ];
    }

    private function minor(mixed $amount, string $field): int
    {
        if (! is_scalar($amount) || ! preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', (string) $amount)) {
            throw ValidationException::withMessages([$field => 'Укажите корректную стоимость мероприятия и участия.']);
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');
        $minor = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($minor <= 0) {
            throw ValidationException::withMessages([$field => 'Стоимость платного мероприятия и участия должна быть больше нуля.']);
        }

        return $minor;
    }

    private function share(int $total, mixed $capacity): int
    {
        $count = max(1, (int) ($capacity ?: 10));

        // Round to the nearest kopeck without floating point arithmetic.
        return max(1, intdiv($total + intdiv($count, 2), $count));
    }
}
