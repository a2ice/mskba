<nav class="admin-nav">
    <ul>
        <li><a href="{{ route('admin.index') }}" class="{{ request()->routeIs('admin.index') ? 'current' : '' }}">Дэшборд</a></li>

        @can('manage-users')
            <li><a href="{{ route('admin.users') }}" class="{{ request()->routeIs('admin.users') ? 'current' : '' }}">Пользователи</a></li>
        @endcan

        @can('manage-settings')
            <li><a href="#" class="{{ request()->routeIs('admin.settings') ? 'current' : '' }}">Настройки</a></li>
        @endcan

        @can('manage-tournaments')
            <li><a href="#" class="{{ request()->routeIs('admin.tournaments') ? 'current' : '' }}">Турниры</a></li>
        @endcan

        @can('manage-content')
            <li><a href="#" class="{{ request()->routeIs('admin.content') ? 'current' : '' }}">Контент</a></li>
        @endcan
    </ul>
</nav>