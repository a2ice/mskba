<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserOperationalPermissionEnum: string
{
    case CONTENT_MANAGE = 'content.manage';
    case CREATE_COORDINATION = 'coordination.create';
    case CREATE_TEAM = 'team.create';
    case CREATE_EVENT = 'event.create';
    case CREATE_TOURNAMENT = 'tournament.create';
    case MANAGE_SYSTEM_ROLES = 'user.system_role.manage';
    case REVIEW_PROFILE_CHANGES = 'identity.profile_change.review';
    case HANDLE_SUPPORT_TICKETS = 'support.ticket.handle';
    case OPERATOR_PRESENCE_VIEW = 'operator.presence.view';
    case OPERATOR_SESSION_HISTORY_VIEW = 'operator.session_history.view';
    case OPERATOR_CONVERSATION_HANDLE = 'operator.conversation.handle';
    case OPERATOR_MARKETING_SEND = 'operator.marketing.send';

    case BACKOFFICE_ACCESS = 'backoffice.access';
    case BACKOFFICE_WIDGET_VIEW = 'backoffice.widget.view';
    case BACKOFFICE_WIDGET_CREATE = 'backoffice.widget.create';
    case BACKOFFICE_WIDGET_EDIT = 'backoffice.widget.edit';
    case BACKOFFICE_WIDGET_DELETE = 'backoffice.widget.delete';
    case BACKOFFICE_STYLE_VIEW = 'backoffice.button_style.view';
    case BACKOFFICE_STYLE_MANAGE = 'backoffice.button_style.manage';
    case BACKOFFICE_THEME_VIEW = 'backoffice.theme.view';
    case BACKOFFICE_THEME_MANAGE = 'backoffice.theme.manage';
    case BACKOFFICE_VENUE_MANAGE = 'backoffice.venue.manage';
    case BACKOFFICE_VENUE_MODERATE = 'backoffice.venue.moderate';
    case BACKOFFICE_EVENT_MANAGE = 'backoffice.event.manage';
    case BACKOFFICE_SITE_MEDIA_VIEW = 'backoffice.site_media.view';
    case BACKOFFICE_SITE_MEDIA_UPLOAD = 'backoffice.site_media.upload';
    case BACKOFFICE_SITE_MEDIA_EDIT = 'backoffice.site_media.edit';
    case BACKOFFICE_SITE_MEDIA_DELETE = 'backoffice.site_media.delete';
    case BACKOFFICE_SITE_MEDIA_RESTORE = 'backoffice.site_media.restore';
    case BACKOFFICE_SITE_MEDIA_FOLDER_MANAGE = 'backoffice.site_media.folder_manage';
    case BACKOFFICE_GRAPHQL_EXPLORER_VIEW = 'backoffice.graphql_explorer.view';
    case BACKOFFICE_REST_API_EXPLORER_VIEW = 'backoffice.rest_api_explorer.view';
    case BACKOFFICE_BUILDER_VIEW = 'backoffice.page_builder.view';
    case BACKOFFICE_BUILDER_EDIT = 'backoffice.page_builder.edit';
    case BACKOFFICE_BUILDER_PUBLISH = 'backoffice.page_builder.publish';
    case BACKOFFICE_BUILDER_CSS_VIEW = 'backoffice.page_builder.css.view';
    case BACKOFFICE_BUILDER_CSS_EDIT = 'backoffice.page_builder.css.edit';
    case BACKOFFICE_BUILDER_CSS_PUBLISH = 'backoffice.page_builder.css.publish';
    case BACKOFFICE_BUILDER_JS_VIEW = 'backoffice.page_builder.js.view';
    case BACKOFFICE_BUILDER_JS_EDIT = 'backoffice.page_builder.js.edit';
    case BACKOFFICE_BUILDER_JS_PUBLISH = 'backoffice.page_builder.js.publish';

    /** @return list<self> */
    public static function backofficeCases(): array
    {
        return [
            self::BACKOFFICE_ACCESS,
            self::BACKOFFICE_WIDGET_VIEW,
            self::BACKOFFICE_WIDGET_CREATE,
            self::BACKOFFICE_WIDGET_EDIT,
            self::BACKOFFICE_WIDGET_DELETE,
            self::BACKOFFICE_STYLE_VIEW,
            self::BACKOFFICE_STYLE_MANAGE,
            self::BACKOFFICE_THEME_VIEW,
            self::BACKOFFICE_THEME_MANAGE,
            self::BACKOFFICE_VENUE_MANAGE,
            self::BACKOFFICE_VENUE_MODERATE,
            self::BACKOFFICE_EVENT_MANAGE,
            self::BACKOFFICE_SITE_MEDIA_VIEW,
            self::BACKOFFICE_SITE_MEDIA_UPLOAD,
            self::BACKOFFICE_SITE_MEDIA_EDIT,
            self::BACKOFFICE_SITE_MEDIA_DELETE,
            self::BACKOFFICE_SITE_MEDIA_RESTORE,
            self::BACKOFFICE_SITE_MEDIA_FOLDER_MANAGE,
            self::BACKOFFICE_GRAPHQL_EXPLORER_VIEW,
            self::BACKOFFICE_REST_API_EXPLORER_VIEW,
            self::BACKOFFICE_BUILDER_VIEW,
            self::BACKOFFICE_BUILDER_EDIT,
            self::BACKOFFICE_BUILDER_PUBLISH,
            self::BACKOFFICE_BUILDER_CSS_VIEW,
            self::BACKOFFICE_BUILDER_CSS_EDIT,
            self::BACKOFFICE_BUILDER_CSS_PUBLISH,
            self::BACKOFFICE_BUILDER_JS_VIEW,
            self::BACKOFFICE_BUILDER_JS_EDIT,
            self::BACKOFFICE_BUILDER_JS_PUBLISH,
        ];
    }

    public function isBackoffice(): bool
    {
        return in_array($this, self::backofficeCases(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::CONTENT_MANAGE => 'Управление редакционным контентом',
            self::CREATE_COORDINATION => 'Создание опросов и согласований',
            self::CREATE_TEAM => 'Создание команд',
            self::CREATE_EVENT => 'Создание мероприятий',
            self::CREATE_TOURNAMENT => 'Создание турниров',
            self::MANAGE_SYSTEM_ROLES => 'Управление системными ролями',
            self::REVIEW_PROFILE_CHANGES => 'Рассматривать изменения подтверждённых данных',
            self::HANDLE_SUPPORT_TICKETS => 'Обрабатывать обращения в техподдержку',
            self::OPERATOR_PRESENCE_VIEW => 'Видеть текущую страницу пользователей',
            self::OPERATOR_SESSION_HISTORY_VIEW => 'Видеть историю переходов в текущей сессии',
            self::OPERATOR_CONVERSATION_HANDLE => 'Инициировать и вести операторские диалоги',
            self::OPERATOR_MARKETING_SEND => 'Отправлять разрешённые рекламные обращения',
            self::BACKOFFICE_ACCESS => 'Вход в Filament /backoffice',
            self::BACKOFFICE_WIDGET_VIEW => 'Просмотр виджетов дашборда',
            self::BACKOFFICE_WIDGET_CREATE => 'Создание виджетов дашборда',
            self::BACKOFFICE_WIDGET_EDIT => 'Изменение виджетов дашборда',
            self::BACKOFFICE_WIDGET_DELETE => 'Удаление виджетов дашборда',
            self::BACKOFFICE_STYLE_VIEW => 'Просмотр справочника CSS-классов',
            self::BACKOFFICE_STYLE_MANAGE => 'Создание, изменение и удаление CSS-классов',
            self::BACKOFFICE_THEME_VIEW => 'Просмотр тем оформления',
            self::BACKOFFICE_THEME_MANAGE => 'Создание, публикация и настройка тем оформления',
            self::BACKOFFICE_VENUE_MANAGE => 'Создание, изменение и удаление площадок и справочников в Filament',
            self::BACKOFFICE_VENUE_MODERATE => 'Рассмотрение заявок модерации площадок и разрешение дублей в Filament',
            self::BACKOFFICE_EVENT_MANAGE => 'Управление мероприятиями, участниками и их бронированиями в Filament',
            self::BACKOFFICE_SITE_MEDIA_VIEW => 'Просмотр медиабиблиотеки сайта',
            self::BACKOFFICE_SITE_MEDIA_UPLOAD => 'Загрузка изображений сайта',
            self::BACKOFFICE_SITE_MEDIA_EDIT => 'Редактирование и замена изображений сайта',
            self::BACKOFFICE_SITE_MEDIA_DELETE => 'Удаление изображений сайта',
            self::BACKOFFICE_SITE_MEDIA_RESTORE => 'Восстановление предыдущей версии изображения',
            self::BACKOFFICE_SITE_MEDIA_FOLDER_MANAGE => 'Управление папками медиабиблиотеки',
            self::BACKOFFICE_GRAPHQL_EXPLORER_VIEW => 'Доступ к GraphQL Explorer в Filament',
            self::BACKOFFICE_REST_API_EXPLORER_VIEW => 'Доступ к REST API / Swagger UI в Filament',
            self::BACKOFFICE_BUILDER_VIEW => 'Просмотр страниц, шаблонов и компонентов',
            self::BACKOFFICE_BUILDER_EDIT => 'Редактирование черновиков конструктора страниц',
            self::BACKOFFICE_BUILDER_PUBLISH => 'Публикация и восстановление страниц и компонентов',
            self::BACKOFFICE_BUILDER_CSS_VIEW => 'Просмотр CSS конструктора страниц',
            self::BACKOFFICE_BUILDER_CSS_EDIT => 'Редактирование CSS конструктора страниц',
            self::BACKOFFICE_BUILDER_CSS_PUBLISH => 'Публикация CSS конструктора страниц',
            self::BACKOFFICE_BUILDER_JS_VIEW => 'Просмотр JS конструктора страниц (будущий редактор)',
            self::BACKOFFICE_BUILDER_JS_EDIT => 'Редактирование JS конструктора страниц (будущий редактор)',
            self::BACKOFFICE_BUILDER_JS_PUBLISH => 'Публикация JS конструктора страниц (будущий редактор)',
        };
    }

    public function defaultAllowed(): bool
    {
        if ($this === self::CONTENT_MANAGE) {
            return false;
        }

        if ($this->isBackoffice()) {
            return false;
        }

        return match ($this) {
            self::CREATE_EVENT, self::CREATE_TOURNAMENT, self::MANAGE_SYSTEM_ROLES, self::REVIEW_PROFILE_CHANGES, self::HANDLE_SUPPORT_TICKETS,
            self::OPERATOR_PRESENCE_VIEW, self::OPERATOR_SESSION_HISTORY_VIEW,
            self::OPERATOR_CONVERSATION_HANDLE, self::OPERATOR_MARKETING_SEND => false,
            default => true,
        };
    }

    public function defaultAllowedFor(UserSystemRoleEnum $systemRole): bool
    {
        // Legacy accounts remain readable until old references are retired.
        if ($this === self::CONTENT_MANAGE) {
            return $systemRole->atLeast(UserSystemRoleEnum::ADMIN)
                || in_array($systemRole, [UserSystemRoleEnum::MODERATOR, UserSystemRoleEnum::EDITOR], true);
        }

        if ($this->isBackoffice()) {
            if ($systemRole === UserSystemRoleEnum::SUPERADMIN) {
                return true;
            }

            // Existing confirmed admins retain their previous entry, widget
            // viewing/creation/editing permissions. Deletions/styles are opt-in.
            return $systemRole === UserSystemRoleEnum::ADMIN
                && in_array($this, [
                    self::BACKOFFICE_ACCESS, self::BACKOFFICE_WIDGET_VIEW,
                    self::BACKOFFICE_WIDGET_CREATE, self::BACKOFFICE_WIDGET_EDIT,
                ], true);
        }

        if (in_array($this, [self::MANAGE_SYSTEM_ROLES, self::REVIEW_PROFILE_CHANGES, self::HANDLE_SUPPORT_TICKETS,
            self::OPERATOR_PRESENCE_VIEW, self::OPERATOR_SESSION_HISTORY_VIEW,
            self::OPERATOR_CONVERSATION_HANDLE, self::OPERATOR_MARKETING_SEND], true)) {
            return $systemRole === UserSystemRoleEnum::SUPERADMIN;
        }

        return $systemRole->atLeast(UserSystemRoleEnum::ADMIN) || $this->defaultAllowed();
    }
}
