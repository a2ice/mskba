@php
    $title = 'Мероприятие без даты: '.$event->title;
    $isApp = app(\App\Presentation\Theming\ThemeResolver::class)->active() === 'mskba_app';
    $button = $isApp ? 'button primary' : 'btn btn--primary';
@endphp
@extends('theme::layouts.app', ['title' => $title])
@section('content')
<section class="{{ $isApp ? 'app-account-section' : 'container py-5' }}">
    <div class="{{ $isApp ? 'container app-account-section__inner' : '' }}">
        <header class="{{ $isApp ? 'app-account-section__heading' : '' }}">
            <p><a href="{{ route('venues.show', $event->venue->routeIdentifier()) }}">← К площадке</a></p>
            <h1>{{ $event->title }}</h1>
            <p>{{ $event->type->label() }} · {{ $event->venue->name }}</p>
        </header>
        <section class="{{ $isApp ? 'panel' : 'card p-4' }}">
            <h2>Мероприятие сохранено как черновик</h2>
            <p>Дата и время пока не выбраны. Мероприятие не опубликовано, участники не получили уведомлений, аренда не оформлена.</p>
            <p>Когда выберешь желаемый интервал, мы отправим предварительную заявку представителю площадки. Это не гарантирует бронирование.</p>
            @if($errors->any())
                <div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
            @endif
            <form method="POST" action="{{ route('events.funnel.schedule', $event->routeIdentifier()) }}">
                @csrf
                <div class="field-group"><label for="draftStart">Желаемые дата и время</label>
                    <input type="datetime-local" id="draftStart" name="starts_at" required
                        min="{{ now(config('app.timezone', 'Europe/Moscow'))->format('Y-m-d\TH:i') }}"></div>
                <div class="field-group"><label for="draftDuration">Продолжительность</label>
                    <select id="draftDuration" name="duration_minutes" required>
                        @foreach(range(30, 480, 30) as $duration)
                            <option value="{{ $duration }}" @selected($duration === 90)>{{ $duration }} мин.</option>
                        @endforeach
                    </select></div>
                <button type="submit" class="{{ $button }}">Опубликовать и отправить предзаявку</button>
            </form>
        </section>
    </div>
</section>
@endsection
