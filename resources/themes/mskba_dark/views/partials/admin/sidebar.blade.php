<div class="section-sidebar-block">
    @include('theme::partials.menu.sidebar', [
        'page' => 'admin',
        'sidebarTitle' => 'Админка',
    ])
</div>

<div class="section-sidebar-block">
    <h2 class="section-sidebar-block__title">Доступ</h2>
    <p class="section-sidebar-block__text">
        Раздел доступен подтвержденным пользователям с системной ролью администратора или выше.
    </p>
</div>
