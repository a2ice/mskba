@php
    $target = $getRecord();
    $viewer = auth('web')->user();
    $checker = app(\App\Modules\Identity\Application\Services\UserOperationalPermissionChecker::class);
    $canRead = $viewer && $checker->allows(
        $viewer,
        \App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum::OPERATOR_SESSION_HISTORY_VIEW
    );
    $activity = app(\App\Modules\Portal\Application\Services\OperatorActivityService::class);
    $state = $activity->state($target);
    $history = $canRead ? $activity->history($target) : [];
@endphp

<div class="space-y-3">
    <p class="text-sm text-gray-600 dark:text-gray-300">
        Статус:
        <strong>{{ ($state['online'] ?? false) ? 'Онлайн' : 'Офлайн' }}</strong>.
        Страница: <strong>{{ ($state['online'] ?? false) ? $state['path'] : '—' }}</strong>.
        @if ($state)
            Последняя активность: {{ \Carbon\Carbon::createFromTimestamp($state['at'])->format('d.m.Y H:i:s') }}.
        @endif
    </p>

    @if (! $canRead)
        <p class="text-sm text-gray-500">История посещений доступна только сотрудникам с отдельным разрешением.</p>
    @elseif ($history === [])
        <p class="text-sm text-gray-500">Нет переходов в доступной текущей сессии.</p>
    @else
        <ol class="space-y-2">
            @foreach ($history as $entry)
                <li class="rounded-lg border px-3 py-2 text-sm dark:border-gray-700">
                    <time class="text-gray-500">{{ \Carbon\Carbon::createFromTimestamp($entry['at'])->format('d.m.Y H:i:s') }}</time>
                    <span class="ml-3">{{ $entry['path'] }}</span>
                </li>
            @endforeach
        </ol>
    @endif
</div>
