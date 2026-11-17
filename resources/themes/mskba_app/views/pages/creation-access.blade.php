@extends('theme::layouts.app', ['title' => $creationGuide['title'] ?? 'Создание'])

@section('content')
<section class="app-creation-access section" aria-labelledby="app-creation-access-title">
    <div class="container">
        <header class="app-creation-access__heading">
            <h1 id="app-creation-access-title">{{ $creationGuide['title'] ?? 'Создание' }}</h1>
            @if(!empty($creationGuide['intro']))
                <p>{{ $creationGuide['intro'] }}</p>
            @endif
        </header>
        <div class="panel app-creation-access__card" data-creation-access>
            @guest
                <h2>Для продолжения войдите или зарегистрируйтесь</h2>
                <p>После входа или регистрации вы автоматически вернётесь к созданию, без необходимости искать эту страницу снова.</p>
                <div class="app-creation-access__actions">
                    <button type="button" class="button primary" data-auth-trigger data-auth-mode="login"
                        data-auth-redirect="{{ request()->getRequestUri() }}">Войти</button>
                    <button type="button" class="button secondary" data-auth-trigger data-auth-mode="register"
                        data-auth-redirect="{{ request()->getRequestUri() }}">Зарегистрироваться</button>
                </div>
                @if($creationTopic === 'venues')
                    <p class="app-creation-access__hint">Добавить площадку может любой незаблокированный пользователь, даже без подтверждённого аккаунта. Публикация проходит отдельную модерацию.</p>
                @endif
            @else
                <h2>Создание пока недоступно</h2>
                @foreach($requirements ?? [] as $requirement)
                    <div class="app-creation-access__requirement" role="status">
                        <p>{{ $requirement['message'] }}</p>
                        @if(!empty($requirement['route']) && \Illuminate\Support\Facades\Route::has($requirement['route']))
                            <a class="button secondary" href="{{ route($requirement['route']) }}">{{ $requirement['label'] ?? 'Перейти к настройкам' }}</a>
                        @endif
                    </div>
                @endforeach
                @if(empty($requirements))
                    <p>Проверьте доступность создания для вашего аккаунта.</p>
                @endif
            @endguest
            @if(!empty($creationGuide['catalog']) && \Illuminate\Support\Facades\Route::has($creationGuide['catalog']))
                <a class="app-creation-access__catalog" href="{{ route($creationGuide['catalog']) }}">{{ $creationGuide['catalog_label'] ?? 'Вернуться в каталог' }}</a>
            @endif
        </div>
    </div>
</section>
@endsection
