@extends('theme::layouts.account', ['title' => 'Сообщения'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Сообщения',
        'intro' => 'Системные уведомления, переписки по заявкам и личные сообщения.',
    ])
@endsection

@section('account-content')
    <section class="app-messages" aria-label="Диалоги">
        @if (isset($error))
            <p class="notice" role="alert">{{ $error['message'] }}</p>
        @else
            @php
                $conversationCards = collect($applicationConversations ?? []);
                if ($systemConversation) {
                    $preview = $systemConversation['preview'];
                    $conversationCards->push([
                        'id' => 'system',
                        'name' => 'Система MSKBA',
                        'preview' => $preview->title.' — '.$preview->body,
                        'unread_count' => $systemConversation['unread_count'],
                        'date' => $preview->created_at,
                        'status' => null,
                        'url' => route('account.notifications.threads.system'),
                    ]);
                }
                $conversationCards = $conversationCards->sortByDesc('date');
            @endphp
            @forelse ($conversationCards as $conversation)
                <a href="{{ $conversation['url'] }}"
                   class="app-messages__conversation {{ $conversation['unread_count'] > 0 ? 'is-unread' : '' }}"
                   data-conversation="{{ $conversation['id'] }}"
                   style="--conversation-accent: {{ $conversation['id'] === 'system' ? '#ff8a00' : (str_starts_with((string) $conversation['id'], 'direct-') ? '#a78bfa' : '#38bdf8') }}">
                    <span class="app-messages__avatar" aria-hidden="true">
                        <svg><use href="#message"/></svg>
                    </span>
                    <span class="app-messages__summary">
                        <span class="app-messages__heading">
                            <strong>{{ $conversation['name'] }}</strong>
                            <time datetime="{{ $conversation['date']?->toIso8601String() }}">
                                {{ $conversation['date']?->format('d.m.Y H:i') }}
                            </time>
                        </span>
                        @if ($conversation['status'])<span class="app-messages__subject">{{ $conversation['status'] }}</span>@endif
                        <span class="app-messages__excerpt">{{ \Illuminate\Support\Str::limit($conversation['preview'], 155) }}</span>
                    </span>
                    <span class="app-messages__meta">
                        @if ($conversation['unread_count'] > 0)
                            <span class="app-messages__unread" aria-label="Непрочитанных сообщений: {{ $conversation['unread_count'] }}">{{ $conversation['unread_count'] > 99 ? '99+' : $conversation['unread_count'] }}</span>
                        @endif
                        <svg aria-hidden="true"><use href="#chevron-down"/></svg>
                    </span>
                </a>
            @empty
                <section class="panel app-messages__empty">
                    <h2>Сообщений пока нет</h2>
                    <p>Когда появятся уведомления и переписки, они будут доступны здесь.</p>
                </section>
            @endforelse
        @endif
    </section>
@endsection
