@extends('theme::layouts.app', ['title' => 'Секции'])

@section('content')
<section class="sports-sections first-screen"><div class="inner">
    <header class="sports-sections__heading"><div><span class="text-accent">Регулярные тренировки</span><h1>Секции</h1><p>Найди постоянную группу, тренера и подходящий формат.</p></div>
        @auth <a class="btn btn--primary" href="{{ route('account.sports-sections.create') }}">Создать секцию</a> @endauth
    </header>
    <div class="sports-sections__grid">
        @forelse($sections as $section)
            <article class="sports-section-card">
                @if($section->featuredMedia)<a href="{{ route('sports-sections.show', $section) }}"><img src="{{ $section->featuredMedia->publicUrl() }}" alt="{{ $section->name }}"></a>@endif
                <div><span class="text-muted">{{ $section->game_format->label() }} · {{ $section->training_mode->label() }}</span><h2><a href="{{ route('sports-sections.show', $section) }}">{{ $section->name }}</a></h2>
                    @if($section->primaryVenue)<p><i class="ti ti-map-pin"></i> {{ $section->primaryVenue->name }}</p>@endif
                    <p>{{ $section->active_trainees_count }} активных участников</p><a class="btn btn--secondary btn--sm" href="{{ route('sports-sections.show', $section) }}">Подробнее</a>
                </div>
            </article>
        @empty <div class="alert alert-info">Активных секций пока нет.</div> @endforelse
    </div>
    {{ $sections->links('theme::partials.pagination') }}
</div></section>
@endsection
