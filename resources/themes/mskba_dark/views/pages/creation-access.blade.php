@extends('theme::layouts.section-sidebar', [
    'title' => $creationGuide['title'],
    'contentTitle' => $creationGuide['title'],
    'contentSubtitle' => 'Подготовьте аккаунт, чтобы продолжить создание.',
    'sectionId' => 'creation-access',
])

@section('section-sidebar')
    <a class="nav-link" href="{{ route($creationGuide['catalog']) }}">{{ $creationGuide['catalog_label'] }}</a>
@endsection

@section('section-content')
    <div class="creation-access" data-creation-access>
        @guest
            <p class="mb-4">Чтобы продолжить, войдите в аккаунт или зарегистрируйтесь. После входа вы вернётесь к созданию.</p>
            @include('theme::partials.auth.inline-login', ['authRedirectTo' => request()->getRequestUri()])
        @else
            @foreach($requirements as $requirement)
                <div class="section-card mb-3">
                    <p>{{ $requirement['message'] }}</p>
                    @if($requirement['route'])
                        <a class="btn btn--secondary-bordered btn--sm" href="{{ route($requirement['route']) }}" target="_blank" rel="noopener" data-creation-resolve>{{ $requirement['label'] }} <i class="ti ti-external-link" aria-hidden="true"></i></a>
                    @endif
                </div>
            @endforeach
            <button type="button" class="btn btn--primary" data-creation-recheck hidden>Проверить</button>
        @endguest
    </div>
@endsection
