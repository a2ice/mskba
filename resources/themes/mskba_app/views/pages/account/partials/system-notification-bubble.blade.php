@php($actions = app(\App\Modules\Notification\Presentation\Presenters\UserNotificationPresenter::class)->present($notification))
<article class="app-messages__bubble app-messages__bubble--system app-messages__bubble--notification"
         data-chat-entry="system-{{ $notification->id }}"
         data-chat-date="{{ $notification->created_at?->format('Y-m-d') }}">
    <div class="app-messages__bubble-head">
        <strong>{{ $notification->title }}</strong>
    </div>
    <p class="app-messages__bubble-body">{{ $notification->body }}</p>
    @if ($actions['action_text'] && is_string($actions['href']) && str_starts_with($actions['href'], '/') && ! str_starts_with($actions['href'], '//'))
        <a class="app-messages__action" href="{{ $actions['href'] }}">{{ $actions['action_text'] }}</a>
    @endif
    @foreach ($actions['actions'] as $action)
        @if (str_starts_with($action['url'], '/') && ! str_starts_with($action['url'], '//'))
            <form method="POST" action="{{ $action['url'] }}" class="app-messages__action-form">
                @csrf @method($action['method'])
                <input type="hidden" name="decision" value="{{ $action['key'] }}">
                <button type="submit" class="button {{ $action['variant'] }}">{{ $action['label'] }}</button>
            </form>
        @endif
    @endforeach
    <time datetime="{{ $notification->created_at?->toIso8601String() }}" class="app-messages__timestamp">
        {{ $notification->created_at?->format('H:i') }}
    </time>
</article>
