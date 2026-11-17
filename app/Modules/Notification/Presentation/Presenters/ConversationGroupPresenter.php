<?php

namespace App\Modules\Notification\Presentation\Presenters;

use Illuminate\Support\Collection;

/**
 * Presentation-only grouping. The input was already filtered by the conversation
 * access policies; do not query or broaden visibility while grouping.
 */
final class ConversationGroupPresenter
{
    private const LABELS = [
        'support' => 'Техподдержка',
        'requests' => 'Заявки и обращения',
        'direct' => 'Личные сообщения',
        'system' => 'Система MSKBA',
    ];

    /** @param Collection<int, array<string, mixed>> $cards
     *  @return array<int, array{key: string, label: string, items: Collection, unread_count: int}>
     */
    public function groups(Collection $cards): array
    {
        $grouped = $cards->groupBy(fn (array $card): string => $this->keyFor($card));
        $groups = [];

        foreach (self::LABELS as $key => $label) {
            $items = $grouped->get($key, collect());
            if ($items->isEmpty()) {
                continue;
            }

            $groups[] = [
                'key' => $key,
                'label' => $label,
                'items' => $items->sortByDesc('date')->values(),
                'unread_count' => (int) $items->sum('unread_count'),
            ];
        }

        // The first group is the one with the newest activity, not a fixed
        // category. Grouping does not alter the underlying conversation order.
        return collect($groups)->sortByDesc(fn (array $group) => $group['items']->first()['date'])->values()->all();
    }

    private function keyFor(array $card): string
    {
        $id = (string) $card['id'];

        return match (true) {
            $id === 'system' => 'system',
            str_starts_with($id, 'support-') => 'support',
            str_starts_with($id, 'direct-') => 'direct',
            default => 'requests', // Team and profile requests, including legacy numeric IDs.
        };
    }
}
