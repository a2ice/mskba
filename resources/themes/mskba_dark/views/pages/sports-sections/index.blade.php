@extends('theme::layouts.app', ['title' => 'Секции'])

@section('content')
<section class="sports-sections first-screen"><div class="inner">
    <header class="sports-sections__heading">
        <div><span class="text-accent">Регулярные тренировки</span><h1>Секции</h1><p>Найди постоянную группу, тренера и подходящий формат.</p></div>
        @auth <a class="btn btn--primary" href="{{ route('account.sports-sections.create') }}">Создать секцию</a> @endauth
    </header>

    <form method="GET" action="{{ route('sports-sections.index') }}" class="sports-sections__filters">
        <label class="form-field sports-sections__search"><span>Поиск</span><input class="form-control" type="search" name="q" value="{{ request('q') }}" placeholder="Название или описание"></label>
        <label class="form-field"><span>Формат тренировок</span><select class="form-select" name="training_mode"><option value="">Любой</option>@foreach($trainingModes as $item)<option value="{{ $item->value }}" @selected(request('training_mode') === $item->value)>{{ $item->label() }}</option>@endforeach</select></label>
        <label class="form-field"><span>Игровой формат</span><select class="form-select" name="game_format"><option value="">Любой</option>@foreach($formats as $item)<option value="{{ $item->value }}" @selected(request('game_format') === $item->value)>{{ $item->label() }}</option>@endforeach</select></label>
        <label class="form-field"><span>Стоимость</span><select class="form-select" name="pricing_type"><option value="">Любая</option>@foreach($pricingTypes as $item)<option value="{{ $item->value }}" @selected(request('pricing_type') === $item->value)>{{ $item->label() }}</option>@endforeach</select></label>
        <label class="form-field"><span>Площадка</span><select class="form-select" name="venue_id"><option value="">Любая</option>@foreach($venues as $venue)<option value="{{ $venue->id }}" @selected((string) request('venue_id') === (string) $venue->id)>{{ $venue->name }}</option>@endforeach</select></label>
        <div class="sports-sections__filter-actions"><button class="btn btn--primary" type="submit">Найти</button>@if(request()->hasAny(['q','training_mode','game_format','pricing_type','venue_id']))<a class="btn btn--secondary" href="{{ route('sports-sections.index') }}">Сбросить</a>@endif</div>
    </form>

    <div class="sports-sections__grid">
        @forelse($sections as $section)
            <article class="sports-section-card">
                @if($section->featuredMedia)<a href="{{ route('sports-sections.show', $section) }}"><img src="{{ $section->featuredMedia->publicUrl() }}" alt="{{ $section->name }}"></a>@endif
                <div><span class="text-muted">{{ $section->game_format->label() }} · {{ $section->training_mode->label() }}</span><h2><a href="{{ route('sports-sections.show', $section) }}">{{ $section->name }}</a></h2>
                    @if($section->primaryVenue)<p><i class="ti ti-map-pin"></i> {{ $section->primaryVenue->name }}</p>@endif
                    <p>{{ $section->active_trainees_count }} активных участников</p><a class="btn btn--secondary btn--sm" href="{{ route('sports-sections.show', $section) }}">Подробнее</a>
                </div>
            </article>
        @empty <div class="alert alert-info sports-sections__empty">По выбранным условиям секций пока нет.</div> @endforelse
    </div>
    {{ $sections->links('theme::partials.pagination') }}
</div></section>
@endsection
