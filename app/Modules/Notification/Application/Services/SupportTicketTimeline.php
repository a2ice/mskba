<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Notification\Domain\Enums\SupportClosureReasonEnum;
use App\Modules\Notification\Domain\Models\RequestThread;
use App\Modules\Notification\Presentation\Presenters\SupportMessagePresenter;

/** Keyset timeline of messages and immutable lifecycle events, without a large JOIN. */
final class SupportTicketTimeline
{
    public function page(RequestThread $thread, ?string $before = null, int $limit = 10): array
    {
        abort_unless($limit >= 1 && $limit <= 100, 422);
        $cursor = $this->decode($before);
        $streams = [
            ['kind' => 'message', 'query' => $thread->messages()->with('sender')],
            ['kind' => 'system', 'query' => $thread->events()->with('actor')
                ->whereIn('action', ['support_closed', 'support_reopened'])],
        ];
        $items = collect();

        foreach ($streams as $stream) {
            $query = $stream['query'];
            if ($cursor !== null) {
                [$date, $kind, $id] = $cursor;
                $query->where(function ($q) use ($stream, $date, $kind, $id): void {
                    $q->where('created_at', '<', $date);
                    if ($stream['kind'] < $kind) {
                        $q->orWhere('created_at', $date);
                    } elseif ($stream['kind'] === $kind) {
                        $q->orWhere(fn ($same) => $same->where('created_at', $date)
                            ->where('id', '<', $id));
                    }
                });
            }
            foreach ($query->orderByDesc('created_at')->orderByDesc('id')->limit($limit + 1)->get() as $row) {
                $items->push($this->entry($stream['kind'], $row));
            }
        }

        $sorted = $items->sort(fn ($a, $b) => [
            $b['at']->format('Y-m-d H:i:s'), $b['kind'], $b['record_id'],
        ] <=> [
            $a['at']->format('Y-m-d H:i:s'), $a['kind'], $a['record_id'],
        ])->values();
        $page = $sorted->take($limit)->reverse()->values();
        $oldest = $page->first();

        return ['entries' => $page,
            'next_before' => $sorted->count() > $limit && $oldest
                ? $this->encode($oldest) : null];
    }

    private function entry(string $kind, $row): array
    {
        if ($kind === 'message') {
            return [
                ...app(SupportMessagePresenter::class)->present($row),
                'record_id' => $row->id,
            ];
        }
        $name = $row->actor?->username ?? 'Сотрудник поддержки';
        $data = $row->metadata ?? [];
        if ($row->action === 'support_reopened') {
            $text = 'Обращение возобновлено. Возобновил: '.$name.'.';
        } else {
            $reason = SupportClosureReasonEnum::tryFrom((string) ($data['reason'] ?? ''));
            $text = 'Обращение закрыто. Закрыл: '.$name.'. Причина: '
                .($reason?->label() ?? 'Не указана').'.';
            if (filled($data['description'] ?? null)) {
                $text .= ' Описание причины: '.$data['description'];
            }
        }

        return ['kind' => 'system', 'id' => 'support-event-'.$row->id,
            'record_id' => $row->id, 'text' => $text,
            'at' => $row->created_at, 'sender' => null];
    }

    private function encode(array $entry): string
    {
        return rtrim(strtr(base64_encode(json_encode([
            $entry['at']->format('Y-m-d H:i:s'), $entry['kind'], $entry['record_id'],
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function decode(?string $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }
        abort_unless(strlen($cursor) <= 180 && preg_match('/^[A-Za-z0-9_-]+$/', $cursor), 422);
        $data = json_decode(base64_decode(strtr($cursor, '-_', '+/'), true) ?: '', true);
        abort_unless(is_array($data) && count($data) === 3
            && is_string($data[0]) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $data[0])
            && in_array($data[1], ['message', 'system'], true)
            && is_int($data[2]) && $data[2] > 0, 422);

        return $data;
    }
}
