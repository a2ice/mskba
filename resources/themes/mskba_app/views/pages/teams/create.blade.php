@extends('theme::layouts.app', ['title' => 'Создать команду'])

@section('content')
    <section class="container app-team-section app-team-public" aria-labelledby="create-team-heading">
        <header class="app-account-overview__intro">
            <h1 id="create-team-heading">Создать команду</h1>
            <p>Укажи название, дисциплину и описание. Владельцем команды станешь ты.</p>
        </header>
        <div class="app-team-actions">
            <a class="button secondary" href="{{ route('account.teams') }}">Назад к моим командам</a>
        </div>
        <form class="panel app-team-create" method="POST" action="{{ route('teams.store') }}">
            @csrf
            <label class="app-team-field">
                <span>Название команды</span>
                <input name="name" value="{{ old('name') }}" maxlength="140" required>
                @error('name') <span class="app-team-error" role="alert">{{ $message }}</span> @enderror
            </label>
            <fieldset class="app-team-sports">
                <legend>Дисциплины</legend>
                <div class="app-team-sports__options">
                    @foreach ($sportTypes as $type)
                        <label class="app-team-checkbox">
                            <input type="checkbox" name="sport_types[]" value="{{ $type->value }}" @checked(in_array($type->value, old('sport_types', ['basketball']), true))>
                            <span>{{ $type->label() }}</span>
                        </label>
                    @endforeach
                </div>
                @error('sport_types') <span class="app-team-error" role="alert">{{ $message }}</span> @enderror
            </fieldset>
            <label class="app-team-field">
                <span>Описание (необязательно)</span>
                <textarea name="description" rows="4" maxlength="5000">{{ old('description') }}</textarea>
                @error('description') <span class="app-team-error" role="alert">{{ $message }}</span> @enderror
            </label>
            <button class="button primary" type="submit">Создать команду</button>
        </form>
    </section>
@endsection
