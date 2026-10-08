@if($entry->messages->isNotEmpty())
    <div class="team-join-thread" aria-label="Переписка по заявке">
        <h3 class="team-join-thread__title">Переписка по заявке</h3>
        <ol class="team-join-thread__messages">
            @foreach($entry->messages as $message)
                @php
                    $sender = $message->sender;
                    $displayName = $sender === null ? 'Удалённый пользователь' : (
                        trim(($sender->profile?->first_name ?? '').' '.($sender->profile?->last_name ?? ''))
                            ?: ($sender->username ? '@'.$sender->username : 'Участник')
                    );
                @endphp
                <li class="team-join-thread__message">
                    <div class="team-join-thread__meta">
                        <strong>{{ $displayName }}</strong>
                        <time datetime="{{ $message->created_at?->toIso8601String() }}">{{ $message->created_at?->format('d.m.Y H:i') }}</time>
                    </div>
                    <p>{{ $message->body }}</p>
                </li>
            @endforeach
        </ol>
    </div>
@endif
