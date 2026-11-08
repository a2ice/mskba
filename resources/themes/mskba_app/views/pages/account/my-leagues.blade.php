@extends('theme::layouts.account', ['title' => 'Мои лиги'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', ['heading' => 'Мои лиги'])
@endsection

@section('account-content')
    <section class="panel app-league-ownership-links" aria-label="Мои лиги">
        @if ($leagues->isEmpty())
            <p>У тебя пока нет своих лиг.</p>
        @else
            <div class="app-league-ownership-links__list">
                @foreach ($leagues as $league)
                    <a class="button secondary" href="{{ route('account.leagues.banner', $league->id) }}">
                        {{ $league->name }}
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@endsection
