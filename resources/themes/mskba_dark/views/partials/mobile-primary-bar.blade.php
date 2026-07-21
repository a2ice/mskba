<div class="mobile-primary-bar" data-mobile-primary-bar>
    <div class="mobile-primary-bar__stats" aria-label="Статистика сайта">
        <p class="mobile-primary-bar__stat">
            <span class="mobile-primary-bar__dot" aria-hidden="true"></span>
            <span>37 игр сегодня</span>
        </p>

        <p class="mobile-primary-bar__stat">
            <span class="mobile-primary-bar__dot mobile-primary-bar__dot--online" aria-hidden="true"></span>
            <span><span data-online-users-count>5</span>/<span data-online-total-count>10</span> онлайн</span>
        </p>
    </div>

    <nav class="mobile-primary-bar__actions" aria-label="Основные действия">
        <a class="mobile-primary-bar__action js-handler" href="#games" data-handler="modal" data-modal-action="open" data-modal-target="games">
            <i class="ti ti-ball-basketball" aria-hidden="true"></i>
            <span>Играть</span>
        </a>

        <a class="mobile-primary-bar__action" href="{{ route('venues') }}">
            <i class="ti ti-map-pin" aria-hidden="true"></i>
            <span>Площадки</span>
        </a>

        <a class="mobile-primary-bar__action js-handler" href="#trainings" data-handler="modal" data-modal-action="open" data-modal-target="trainings">
            <i class="ti ti-barbell" aria-hidden="true"></i>
            <span>Тренировки</span>
        </a>

        <a
            class="mobile-primary-bar__action mobile-primary-bar__menu js-handler"
            href="#menu"
            data-handler="toggleClass"
            data-params="nav-shown;body"
            data-nav-toggle
            aria-expanded="false"
            aria-label="Открыть основное меню"
        >
            <span class="mobile-primary-bar__menu-icon" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </span>
            <span class="mobile-primary-bar__menu-open">Меню</span>
            <span class="mobile-primary-bar__menu-close">Закрыть</span>
        </a>
    </nav>
</div>
