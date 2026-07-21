<section id="welcome" class="welcome-section py-5">
    <div class="container">
        <h1 class="mb-4">Главная страница</h1>

        @auth
            <div class="alert alert-success d-flex justify-content-between align-items-center">
                <span>Вы вошли как {{ auth()->user()->username }}.</span>
                <form method="POST" action="{{ route('auth.logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary btn-sm">Выйти</button>
                </form>
            </div>
        @else
            <div class="auth-form-wrapper mx-auto" style="max-width: 400px;">
                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('auth.login') }}">
                    @csrf
                    <div class="form-group mb-3">
                        <label for="authLogin">Логин или подтверждённый контакт</label>
                        <input id="authLogin" type="text" name="login" placeholder="Логин, email, телефон или Telegram" class="form-control" value="{{ old('login') }}" required autocomplete="username">
                    </div>
                    <div class="form-group mb-3">
                        <label for="authPassword">Пароль</label>
                        <input id="authPassword" type="password" name="password" placeholder="Пароль" class="form-control" required autocomplete="current-password">
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="rememberMe" name="remember">
                        <label class="form-check-label" for="rememberMe">Запомнить меня</label>
                    </div>
                    <button type="submit" class="btn btn-primary">Войти</button>
                </form>
            </div>
        @endauth
    </div>
</section>
