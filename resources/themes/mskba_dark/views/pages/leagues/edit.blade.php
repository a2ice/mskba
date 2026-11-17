@extends('theme::layouts.app', ['title' => $league === null ? 'Создать лигу' : 'Редактировать лигу'])

@section('content')
<section class="container app-league-section" aria-labelledby="league-manage-title">
    <nav class="app-league-breadcrumbs" aria-label="Навигация">
        <a href="{{ route('leagues.index') }}">Лиги</a><span aria-hidden="true">/</span>
        <span>{{ $league === null ? 'Новая лига' : $league->name }}</span>
    </nav>
    <header class="app-account-overview__intro">
        <h1 id="league-manage-title">{{ $league === null ? 'Создать лигу' : 'Редактировать лигу' }}</h1>
        <p>{{ $league === null ? 'Укажите название и описание новой лиги.' : 'Управляйте информацией о лиге.' }}</p>
    </header>
    <div class="panel app-league-block" style="margin-top:16px">
        @if(session('status')) <p role="status" style="margin-bottom:16px">{{ session('status') }}</p> @endif
        @if($errors->any())
            <div role="alert" style="margin-bottom:16px">
                @foreach($errors->all() as $error) <p>{{ $error }}</p> @endforeach
            </div>
        @endif
        <form method="POST" action="{{ $league === null ? route('leagues.store') : route('leagues.update', $league->id) }}"
              style="display:grid;gap:16px">
            @csrf
            @if($league !== null) @method('PUT') @endif
            <label class="app-team-field" style="display:grid;gap:8px">
                <span>Название лиги</span>
                <input type="text" name="name" value="{{ old('name', $league?->name) }}" minlength="2" maxlength="150" required>
            </label>
            <label class="app-team-field" style="display:grid;gap:8px">
                <span>Описание</span>
                <textarea name="description" rows="5" maxlength="10000">{{ old('description', $league?->description) }}</textarea>
            </label>
            <div class="app-team-filter-actions" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <button class="button primary" type="submit">{{ $league === null ? 'Создать лигу' : 'Сохранить' }}</button>
                <a class="button secondary" href="{{ route('account.contracts') }}">Мои контракты</a>
                @if($league !== null && $league->status === 'active')
                    <a class="button secondary" href="{{ route('leagues.show', $league->alias) }}">Открыть лигу</a>
                @endif
            </div>
        </form>
    </div>
</section>
@endsection
