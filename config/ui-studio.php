<?php

return [
    'enabled' => env('UI_STUDIO_ENABLED', false),
    'issuer_enabled' => env('UI_STUDIO_BRIDGE_ENABLED', false),
    'bridge_secret' => env('UI_STUDIO_BRIDGE_SECRET'),
    'parent_origins' => ['https://mskba.ru', 'https://dev.mskba.ru'],
    'host' => env('UI_STUDIO_HOST', 'ui.mskba.ru'),
    'user' => env('UI_STUDIO_USER'),
    'password_hash' => env('UI_STUDIO_PASSWORD_HASH'),
    'cases' => [
        'agent-agreement' => [
            'title' => 'Агентский договор — личный кабинет (#1061)',
            'issue' => 1061,
            'revision' => 1,
            'sources' => [
                'resources/views/account/partials/agent-agreement-card.blade.php',
                'resources/views/ui-studio/cases/agent-agreement.blade.php',
                'resources/views/ui-studio/frame.blade.php',
            ],
            'kit_changes' => [],
            'status' => 'На визуальной приёмке',
            'templates' => ['mskba_app', 'mskba_dark'],
            'states' => ['normal' => 'Предложение', 'accepted' => 'Акцептовано', 'verified' => 'Проверено', 'active' => 'Действует', 'closed' => 'Прекращено'],
        ],
        'account-invitation' => [
            'title' => 'Принятие приглашения — #1060',
            'issue' => 1060,
            'revision' => 1,
            'sources' => [
                'resources/views/invitations/onboarding.blade.php',
                'resources/views/ui-studio/cases/account-invitation.blade.php',
                'resources/views/ui-studio/frame.blade.php',
            ],
            'kit_changes' => [],
            'status' => 'На визуальной приёмке',
            'templates' => ['mskba_app', 'mskba_dark', 'mskba_streetball', 'blank'],
            'states' => ['normal' => 'Получено приглашение', 'register' => 'Новый аккаунт', 'custom' => 'Свои логин и пароль', 'collision' => 'Логин занят', 'existing' => 'Существующий аккаунт', 'expired' => 'Просрочено', 'revoked' => 'Аннулировано', 'accepted' => 'Принято'],
        ],
        'event-detail' => [
            'sources' => [
                'resources/views/ui-studio/cases/event-detail.blade.php',
                'resources/views/ui-studio/cases/event-detail-candidate.blade.php',
                'resources/views/ui-studio/cases/event-detail-actions.blade.php',
                'resources/views/ui-studio/cases/event-detail-dialogs.blade.php',
                'resources/views/ui-studio/cases/event-report-editor.blade.php',
            ],
            'kit_changes' => [

            ],
            'title' => 'Страница мероприятия',
            'issue' => 933,
            'revision' => 10,
            'status' => 'На визуальной приёмке',
            'templates' => ['mskba_app'],
            'states' => ['guest' => 'Гость', 'normal' => 'Авторизован, не участвует', 'confirmed' => 'Пойду', 'tentative' => 'Подумаю', 'booked' => 'Активная аренда', 'inactive' => 'Неактивная аренда', 'editing' => 'Редактирование сообщения', 'report' => 'Опубликованный отчёт', 'report-empty' => 'Завершено, без отчёта', 'report-owner' => 'Завершено, организатор', 'report-admin' => 'Завершено, суперадминистратор', 'report-guest' => 'Завершено, гость', 'report-outsider' => 'Завершено, не участник', 'report-closed' => 'Комментарии закрыты', 'discussion-owner' => 'Организатор, до мероприятия', 'discussion-closed' => 'Обсуждение закрыто'],
        ],
        'event-feedback-settings' => [
            'sources' => [
                'resources/views/ui-studio/cases/event-feedback-settings.blade.php',
                'resources/views/ui-studio/cases/event-feedback-fields.blade.php',
                'resources/views/ui-studio/cases/event-detail-candidate.blade.php',
            ],
            'kit_changes' => [

            ],
            'title' => 'Мероприятие — настройки обсуждения и комментариев',
            'issue' => 933,
            'revision' => 1,
            'status' => 'На визуальной приёмке',
            'templates' => ['mskba_app'],
            'states' => ['normal' => 'Создание мероприятия', 'editing' => 'Редактирование мероприятия'],
        ],
        'venue-map' => [
            'sources' => [
                'resources/views/ui-studio/cases/venue-map.blade.php',
                'resources/views/ui-studio/cases/venue-map-preview.blade.php',
                'resources/views/ui-studio/cases/venue-map-fixture-canvas.blade.php',
            ],
            'kit_changes' => [

            ],
            'title' => 'Карта площадки — общий компонент',
            'issue' => 933,
            'revision' => 1,
            'status' => 'На визуальной приёмке',
            'templates' => ['mskba_app'],
            'states' => ['normal' => 'Со страницы площадки', 'event' => 'Со страницы мероприятия'],
        ],
        'unified-chat' => [
            'sources' => [
                'resources/views/ui-studio/cases/unified-chat.blade.php',
                'resources/views/ui-studio/cases/unified-chat-candidate.blade.php',
                'resources/views/ui-studio/cases/unified-chat-fixture.blade.php',
                'resources/js/chat-composer-actions.js',
            ],
            'kit_changes' => [
                ['id' => 'chat-composer-focus', 'title' => 'Фокус редактора сообщений', 'description' => 'Единая рамка фокуса без двойного outline.'],
                ['id' => 'chat-actions-hit-area', 'title' => 'Кнопки управления чатом', 'description' => 'Компактные элементы с увеличенной областью касания.'],
                ['id' => 'chat-date-groups', 'title' => 'Группировка по датам', 'description' => 'Повторно используемые разделители дат сообщений.'],
                ['id' => 'chat-participant-mention', 'title' => 'Упоминание участника', 'description' => 'Клавиатурно-доступное действие выбора участника чата.'],
            ],
            'title' => 'Единый чат — редактор сообщения',
            'issue' => 933,
            'revision' => 6,
            'status' => 'На визуальной приёмке',
            'templates' => ['mskba_app'],
            'states' => ['normal' => 'Обычное', 'empty' => 'Пустое', 'editing' => 'Редактирование', 'disabled' => 'Недоступно'],
        ],
    ],
];
