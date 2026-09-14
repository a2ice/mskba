@extends('theme::layouts.section-sidebar', [
    'title' => 'Команды · '.$section->name,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => 'Команды · '.$section->name,
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])

@section('section-heading-action')
    <a class="btn btn--secondary btn--sm" href="{{ route('account.sports-sections.edit', $section) }}">К управлению секцией</a>
@endsection

@section('section-content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

<div class="sports-section-editor">
    <section class="sports-section-editor__panel">
        <div class="sports-section-editor__panel-heading">
            <div>
                <h2>Связанные команды</h2>
                <p class="text-muted">Связь организационная: она не переносит права команды в секцию и права секции в команду.</p>
            </div>
        </div>

        <div class="sports-section-editor__items">
            @forelse($section->teams as $team)
                <article>
                    <div>
                        <strong>{{ $team->name }}</strong>
                        <span class="text-muted">{{ $team->status->label() }}</span>
                    </div>
                    <div class="sports-section-editor__actions">
                        <a class="btn btn--secondary btn--sm" href="{{ route('teams.show', $team->routeIdentifier()) }}">Открыть команду</a>
                        <form method="POST" action="{{ route('account.sports-sections.teams.destroy', [$section, $team]) }}">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn--secondary btn--sm" type="submit">Отвязать</button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="alert alert-info">У секции пока нет связанных команд.</div>
            @endforelse
        </div>
    </section>

    <section class="sports-section-editor__panel">
        <h2>Связать команду</h2>
        <p class="text-muted">Доступны только постоянные команды, для которых у вас есть право редактировать настройки.</p>
        @if($availableTeams->isNotEmpty())
            <form method="POST" action="{{ route('account.sports-sections.teams.store', $section) }}" class="sports-section-editor__inline">
                @csrf
                <select class="form-select" name="team_id" required>
                    <option value="">Выберите команду</option>
                    @foreach($availableTeams as $team)
                        <option value="{{ $team->id }}">{{ $team->name }} · {{ $team->status->label() }}</option>
                    @endforeach
                </select>
                <button class="btn btn--primary btn--sm" type="submit">Связать</button>
            </form>
        @else
            <div class="alert alert-info">Нет доступных команд для новой связи.</div>
        @endif
    </section>
</div>
@endsection
