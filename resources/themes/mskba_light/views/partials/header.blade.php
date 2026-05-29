<div class="header-inner">
    <div class="container d-flex justify-content-between align-items-center py-3">
        <a href="{{ route('welcome') }}" class="site-logo h4 mb-0">MSKBA</a>
        <nav class="site-nav">
            <ul class="nav">
                <li class="nav-item">
                    <a href="{{ route('welcome') }}" class="nav-link">Главная</a>
                </li>
                <li class="nav-item">
                    <a href="{{ route('profile') }}" class="nav-link">
                        {{ $user ? $user->username : 'Профиль' }}
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</div>