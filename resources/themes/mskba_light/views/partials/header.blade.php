<div class="header-inner">
    <div class="container d-flex justify-content-between align-items-center py-3">
        <a href="{{ route('welcome') }}" class="site-logo h4 mb-0">MSKBA</a>
        <nav class="site-nav">
            <ul class="nav">
                <li class="nav-item">
                    <a href="{{ route('welcome') }}" class="nav-link">Главная</a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('venues') }}" class="nav-link">Площадки</a>
                </li>
            </ul>
        </nav>
        <nav class="account-nav">
            <ul class="nav">
                <li class="nav-item">
                    <a href="{{ route('account') }}" class="nav-link">
                        {{ $user ? $user->username : 'Аккаунт' }}
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</div>