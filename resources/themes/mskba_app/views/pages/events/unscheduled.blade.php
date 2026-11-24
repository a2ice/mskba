@php
    $title = 'Мероприятие без даты: '.$event->title;
    $isApp = app(\App\Presentation\Theming\ThemeResolver::class)->active() === 'mskba_app';
    $button = $isApp ? 'button primary' : 'btn btn--primary';
    $timezone = $event->venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');
@endphp
@extends('theme::layouts.app', ['title' => $title])
@section('content')
<section class="{{ $isApp ? 'app-account-section' : 'container py-5' }}">
    <div class="{{ $isApp ? 'container app-account-section__inner' : '' }}">
        <header class="{{ $isApp ? 'app-account-section__heading' : '' }}">
            <p><a href="{{ route('venues.show', $event->venue->routeIdentifier()) }}">← К площадке</a></p>
            <h1>{{ $event->title }}</h1>
            <p>{{ $event->type->label() }} · {{ $event->venue->name }}</p>
            @include('components.event-participation-price', ['pricingEvent' => $event])
        </header>
        <section class="{{ $isApp ? 'panel' : 'card' }} event-draft-panel">
            <h2>Мероприятие сохранено как черновик</h2>
            <p>Дата и время пока не выбраны. Мероприятие не опубликовано, участники не получили уведомлений, аренда не оформлена.</p>
            <p>Когда выберешь желаемый интервал, мы отправим предварительную заявку представителю площадки. Это не гарантирует бронирование.</p>
            @if($errors->any())
                <div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
            @endif
            <form method="POST" action="{{ route('events.funnel.schedule', $event->routeIdentifier()) }}">
                @csrf
                <div class="field-group"><label for="draftStart">Желаемые дата и время</label>
                    <input class="{{ $isApp ? '' : 'form-control' }}" type="datetime-local" id="draftStart" name="starts_at" value="{{ old('starts_at') }}" required
                        min="{{ now($timezone)->format('Y-m-d\TH:i') }}"></div>
                <div class="field-group"><label for="draftDuration">Продолжительность</label>
                    <select class="{{ $isApp ? '' : 'form-control' }}" id="draftDuration" name="duration_minutes" required>
                        @foreach(range(30, 480, 30) as $duration)
                            <option value="{{ $duration }}" @selected($duration === (int) old('duration_minutes', 90))>{{ $duration }} мин.</option>
                        @endforeach
                    </select></div>
                <button type="submit" class="{{ $button }}">Опубликовать и отправить предзаявку</button>
            </form>
        </section>
    </div>
</section>
<style>
.event-draft-panel{box-sizing:border-box;min-width:0;padding:clamp(16px,3vw,24px);display:grid;gap:12px}
.event-draft-panel h2{font-size:clamp(20px,2.4vw,26px);line-height:1.25;margin:0;overflow-wrap:anywhere}
.event-draft-panel p{margin:0;line-height:1.5}
.event-draft-panel form{display:grid;grid-template-columns:minmax(0,1fr);gap:16px;min-width:0;margin-top:4px}
.event-draft-panel .field-group{display:grid;gap:8px;min-width:0;margin:0}
.event-draft-panel :is(input,select){box-sizing:border-box;width:100%;max-width:100%;min-width:0;font-size:16px}
.event-draft-panel input[type="datetime-local"]::-webkit-date-and-time-value{text-align:left;min-width:0}
.event-draft-panel button{max-width:100%;white-space:normal}
</style>
@endsection
