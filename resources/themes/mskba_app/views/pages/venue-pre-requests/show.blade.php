@php
    $title = 'Предзаявка на аренду';
    $timezone = $pre->venue->schedule()->value('timezone') ?: config('app.timezone');
    $active = $pre->status === \App\Modules\VenueBooking\Domain\Enums\VenueRentalPreRequestStatus::ACTIVE;
@endphp
@extends('theme::layouts.app', ['title' => $title])
@section('content')
<section class="app-account-section">
    <div class="container app-account-section__inner app-rental-pricing">
        <header class="app-account-section__heading">
            <a href="{{ route('account.venue-pre-requests.index') }}">Предзаявки</a>
            <h1>{{ $title }}</h1>
            <p>{{ $pre->venue->name }} · {{ $pre->court->publicName() }}</p>
        </header>
        @if(session('error'))<p role="alert">{{ session('error') }}</p>@endif
        @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
        <section class="panel app-rental-pricing__panel">
            <h2>{{ $pre->status->label() }}</h2>
            <p>{{ $pre->starts_at->setTimezone($timezone)->format('d.m.Y H:i') }}–{{ $pre->ends_at->setTimezone($timezone)->format('d.m.Y H:i') }} · {{ (int) $pre->starts_at->diffInMinutes($pre->ends_at) }} мин · {{ $pre->scope->label() }}</p>
            <p>Заявитель: {{ $pre->requester->username }} · {{ $pre->created_at->setTimezone($timezone)->format('d.m.Y H:i') }}</p>
            <p>Предзаявка не удерживает время и не требует оплаты. Для аренды нужно согласовать отдельное предложение.</p>
            @if($pre->resolved_at)<p>Согласована: {{ $pre->resolved_at->setTimezone($timezone)->format('d.m.Y H:i') }}</p>@endif
            @if($pre->withdrawn_at)<p>Отозвана: {{ $pre->withdrawn_at->setTimezone($timezone)->format('d.m.Y H:i') }}</p>@endif
            <div class="app-rental-pricing__actions">
                @if($active && $canOffer)
                    <form method="POST" action="{{ route('account.venue-pre-requests.offer', $pre) }}">@csrf
                        <button class="button primary" type="submit">Предложить бронирование</button>
                    </form>
                @endif
                @if($active && $isCustomer)
                    <form method="POST" action="{{ route('account.venue-pre-requests.withdraw', $pre) }}">@csrf
                        <button class="button secondary" type="submit">Отозвать предзаявку</button>
                    </form>
                @endif
            </div>
        </section>
        @foreach($pre->bookings->sortByDesc('id') as $booking)
            <section class="panel app-rental-pricing__panel">
                <h2>Предложение №{{ $booking->id }}</h2>
                <p>{{ $booking->status->label() }} · {{ app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class)->format((int) data_get($booking->quote_snapshot, 'pricing.amount_minor'), (string) data_get($booking->quote_snapshot, 'pricing.currency')) }} {{ data_get($booking->quote_snapshot, 'pricing.currency') }}</p>
                <p>Ответить до {{ $booking->proposal_expires_at->setTimezone($timezone)->format('d.m.Y H:i') }}.</p>
                @if($booking->proposal_accepted_at === null && $booking->proposal_expires_at->isPast())<p>Срок предложения истёк.</p>@endif
                @if($booking->proposal_accepted_at)<p>Принято: {{ $booking->proposal_accepted_at->setTimezone($timezone)->format('d.m.Y H:i') }}</p>@endif
                <div class="app-rental-pricing__actions">
                    @if($active && $isCustomer && $booking->status->value === 'requested' && $booking->proposal_expires_at->isFuture())
                        @foreach(['accept' => 'Принять предложение', 'decline' => 'Отклонить'] as $decision => $label)
                            <form method="POST" action="{{ route('account.venue-pre-requests.respond', $pre) }}">@csrf
                                <input type="hidden" name="booking_id" value="{{ $booking->id }}">
                                <button class="button {{ $decision === 'accept' ? 'primary' : 'secondary' }}" name="decision" value="{{ $decision }}">{{ $label }}</button>
                            </form>
                        @endforeach
                    @endif
                    <a class="button secondary" href="{{ route('account.venue-bookings.show', $booking) }}">Бронь и дальнейшее оформление</a>
                </div>
            </section>
        @endforeach
        <section class="panel app-rental-pricing__panel" aria-labelledby="pre-request-messages">
            <h2 id="pre-request-messages">Переписка</h2>
            <p><a href="{{ request()->fullUrl() }}">Обновить сообщения</a></p>
            @foreach($messages->getCollection()->reverse() as $message)
                <article>
                    <p><strong>{{ $message->authorActor?->user?->username ?? 'Система' }}</strong>
                        <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->setTimezone($timezone)->format('d.m.Y H:i') }}</time></p>
                    <p>{{ $message->body }}</p>
                    @if($message->attachment_path)<a href="{{ route('account.venue-pre-requests.download', [$pre, $message]) }}">{{ $message->attachment_name }}</a>@endif
                </article>
            @endforeach
            {{ $messages->links() }}
            @if($canWrite && config('features.venue_rental.conversations'))
                <form method="POST" enctype="multipart/form-data" class="app-rental-pricing__form" action="{{ route('account.venue-pre-requests.send', $pre) }}">@csrf
                    <input type="hidden" name="client_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <label class="field-group">Сообщение<textarea name="body" maxlength="4000" rows="4"></textarea></label>
                    <label class="field-group">Файл (JPG, PNG, PDF, TXT, до 10 МБ)<input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.txt"></label>
                    <button class="button primary" type="submit">Отправить</button>
                </form>
            @endif
        </section>
    </div>
</section>
@endsection
