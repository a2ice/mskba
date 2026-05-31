<div class="partial-wrapper partial-account-nav">
    <nav class="account-nav">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a href="{{ route('account') }}" class="nav-link {{ request()->routeIs('account') ? 'active' : '' }}">
                    Профиль
                </a>
            </li>
            @auth
                <li class="nav-item">
                    <a href="{{ route('account.venues') }}" class="nav-link {{ request()->routeIs('account.venues') ? 'active' : '' }}">
                        Мои площадки
                    </a>
                </li>
            @endauth
        </ul>
    </nav>


</div>