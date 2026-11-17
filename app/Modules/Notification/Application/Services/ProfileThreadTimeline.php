<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Identity\Domain\Models\ProfileChangeRequest;
use App\Modules\Notification\Domain\Models\RequestThread;

/** Indexed, bounded keyset pagination over the three independent event streams. */
final class ProfileThreadTimeline
{
    public function page(ProfileChangeRequest $request, RequestThread $thread, ?string $before = null): array
    {
        $cursor = $this->decode($before);
        $streams = [
            ['kind' => 'request', 'query' => $request->events()],
            ['kind' => 'member', 'query' => $thread->events()],
            ['kind' => 'message', 'query' => $thread->messages()->with('sender')],
        ];
        $items = collect();
        foreach ($streams as $stream) {
            $query = $stream['query'];
            if ($cursor) {
                [$at, $kind, $id] = $cursor;
                $query->where(function ($q) use ($at, $kind, $id, $stream): void {
                    $q->where('created_at', '<', $at);
                    if ($stream['kind'] < $kind) {
                        $q->orWhere('created_at', '=', $at);
                    } elseif ($stream['kind'] === $kind) {
                        $q->orWhere(fn ($same) => $same->where('created_at', '=', $at)->where('id', '<', $id));
                    }
                });
            }
            foreach ($query->orderByDesc('created_at')->orderByDesc('id')->limit(11)->get() as $record) {
                $items->push($this->entry($request, $stream['kind'], $record));
            }
        }
        $sorted = $items->sort(function ($a, $b): int {
            return [$b['at']->format('Y-m-d H:i:s'), $b['kind'], $b['record_id']]
                <=> [$a['at']->format('Y-m-d H:i:s'), $a['kind'], $a['record_id']];
        })->values();
        $hasMore = $sorted->count() > 10;
        $page = $sorted->take(10)->reverse()->values();
        $oldest = $page->first();

        return ['entries' => $page, 'next_before' => $hasMore && $oldest
            ? $this->encode($oldest) : null];
    }

    private function entry(ProfileChangeRequest $request, string $kind, $item): array
    {
        $text = match ($kind) {
            'message' => $item->body,
            'request' => match ($item->action) {
                'submit' => 'Заявка отправлена на рассмотрение. Причина: '.$request->reason,
                'approve' => 'Заявка одобрена.', 'reject' => 'Заявка отклонена.',
                'block' => 'Заявка заблокирована.', 'unblock' => 'Заявка разблокирована.',
                'cancel' => 'Заявка отменена.', default => 'Статус заявки изменён.',
            }.($item->action !== 'submit' && filled($item->reason) ? ' Причина: '.$item->reason : ''),
            default => match ($item->action) {
                'lead_assigned' => 'Назначен главный администратор заявки.',
                'lead_transferred' => 'Главенство передано другому участнику.',
                'lead_rescued' => 'Назначен новый главный администратор вместо утратившего полномочия.',
                'invited' => 'Приглашён уполномоченный сотрудник.',
                'removed' => 'Администратор исключён из рассмотрения.',
                'permission_revoked' => 'Полномочия участника отозваны.',
                default => 'Состав участников изменился.',
            },
        };

        return [
            'kind' => $kind, 'record_id' => $item->id,
            'id' => $kind.'-'.$item->id, 'text' => $text, 'at' => $item->created_at,
            'sender' => $kind === 'message' ? $item->sender : null,
        ];
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
            && in_array($data[1], ['request', 'member', 'message'], true)
            && is_int($data[2]) && $data[2] > 0, 422);

        return $data;
    }
}
