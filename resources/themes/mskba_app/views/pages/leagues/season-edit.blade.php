@extends('theme::layouts.app', ['title' => $season === null ? 'Создать сезон' : 'Редактировать сезон'])

@section('content')
<section class="container app-league-section" aria-labelledby="league-season-manage-title">
    <nav class="app-league-breadcrumbs" aria-label="Навигация">
        <a href="{{ route('leagues.index') }}">Лиги</a><span aria-hidden="true">/</span>
        <span>{{ $league->name }}</span><span aria-hidden="true">/</span>
        <span>{{ $season === null ? 'Новый сезон' : $season->name }}</span>
    </nav>
    <header class="app-account-overview__intro">
        <h1 id="league-season-manage-title">{{ $season === null ? 'Создать сезон' : 'Редактировать сезон' }}</h1>
        <p>Лига: {{ $league->name }}</p>
    </header>
    <div class="panel app-league-block" style="margin-top:16px">
        @if(session('status')) <p role="status" style="margin-bottom:16px">{{ session('status') }}</p> @endif
        @if($errors->any())
            <div role="alert" style="margin-bottom:16px">
                @foreach($errors->all() as $error) <p>{{ $error }}</p> @endforeach
            </div>
        @endif
        <form method="POST" action="{{ $season === null ? route('leagues.seasons.store', $league->id) : route('leagues.seasons.update', [$league->id, $season->id]) }}"
              style="display:grid;gap:16px">
            @csrf
            @if($season !== null) @method('PUT') @endif
            <label class="app-team-field" style="display:grid;gap:8px">
                <span>Название сезона</span>
                <input type="text" name="name" value="{{ old('name', $season?->name) }}" minlength="2" maxlength="150" required>
            </label>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px">
                <label class="app-team-field" style="display:grid;gap:8px">
                    <span>Начало</span>
                    <input type="date" name="starts_on" value="{{ old('starts_on', $season?->starts_on?->format('Y-m-d')) }}">
                </label>
                <label class="app-team-field" style="display:grid;gap:8px">
                    <span>Окончание</span>
                    <input type="date" name="ends_on" value="{{ old('ends_on', $season?->ends_on?->format('Y-m-d')) }}">
                </label>
            </div>
            <label class="app-team-field" style="display:grid;gap:8px">
                <span>Описание</span>
                <textarea name="description" maxlength="10000" rows="5">{{ old('description', $season?->description) }}</textarea>
            </label>
            <div class="app-team-filter-actions" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <button class="button primary" type="submit">{{ $season === null ? 'Создать сезон' : 'Сохранить' }}</button>
                <a class="button secondary" href="{{ route('account.contracts') }}">Мои контракты</a>
                @if($season !== null && $season->status === 'active' && $league->status === 'active')
                    <a class="button secondary" href="{{ route('leagues.seasons.show', [$league->alias, $season->id]) }}">Открыть сезон</a>
                @endif
            </div>
        </form>
        @if($season !== null)
            <div class="app-league-banner-settings">
                @include('components.entity-banner-editor', ['model' => $season, 'context' => 'account'])
            </div>
        @endif
    </div>
</section>
@endsection
