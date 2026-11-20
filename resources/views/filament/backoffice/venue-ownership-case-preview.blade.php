<div style="display:grid;gap:14px;line-height:1.5">
    <p><strong>Площадка:</strong> {{ $claim->venue?->name }}</p>
    <p><strong>Заявитель:</strong> {{ $claim->applicant?->username }}</p>
    <p><strong>Статус:</strong> {{ $claim->status->label() }}</p>
    <section style="padding:14px;border:1px solid #d8e0e9;border-radius:12px">
        <strong>Обоснование</strong>
        <p style="white-space:pre-wrap">{{ $claim->evidence }}</p>
        @if($claim->decision_reason)<p><strong>Предыдущее решение:</strong> {{ $claim->decision_reason }}</p>@endif
        @foreach($claim->documents as $document)
            <p>Документ: <a href="{{ route('account.venue-ownership.documents.download', [$claim, $document]) }}" target="_blank" rel="noopener">{{ $document->name }}</a></p>
        @endforeach
    </section>
    <section style="padding:14px;border:1px solid #d8e0e9;border-radius:12px">
        <strong>Общий диалог с заявителем</strong>
        @forelse($claim->conversation?->messages ?? [] as $message)
            <div style="padding:9px 0;border-bottom:1px solid #d8e0e9">
                <strong>{{ $message->authorActor?->user?->username ?? 'Администрация' }}</strong>
                <span>{{ $message->created_at?->format('d.m.Y H:i') }}</span>
                <p style="white-space:pre-wrap">{{ $message->body }}</p>
                @if($message->attachment_path)
                    <a href="{{ route('account.venue-ownership.conversation.attachment', [$claim, $message]) }}" target="_blank" rel="noopener">Скачать: {{ $message->attachment_name }}</a>
                @endif
            </div>
        @empty
            <p>Сообщений пока нет. Используйте действие «Написать» или «Запросить документы».</p>
        @endforelse
    </section>
    <p><a href="{{ route('account.venue-contracts.show', ['venue' => $claim->venue_id, 'claim' => $claim->public_id]) }}" target="_blank" rel="noopener" style="text-decoration:underline;color:#d45c0b">Открыть полную карточку, документы и переписку</a></p>
</div>
