<form class="app-dashboard-admin__form" method="POST" action="{{ $isNew ? route('admin.account-dashboard.widgets.store') : route('admin.account-dashboard.widgets.update', $widget) }}">
    @csrf
    @unless ($isNew) @method('PUT') @endunless
    <div class="app-dashboard-admin__fields">
        <label>Тип
            <select name="kind" required>
                @foreach ($kinds as $kind => $label)
                    @if ($canEditCode || $kind !== 'custom_html' || $widget->kind === 'custom_html')
                        <option value="{{ $kind }}" @selected($widget->kind === $kind)>{{ $label }}</option>
                    @endif
                @endforeach
            </select>
        </label>
        <label>Заголовок <input type="text" name="title" required maxlength="150" value="{{ $widget->title }}"></label>
        <label>Порядок <input type="number" name="sort_order" min="0" max="9999" required value="{{ $widget->sort_order }}"></label>
        <label>Ширина
            <select name="size">
                <option value="half" @selected($widget->size === 'half')>Половина</option>
                <option value="full" @selected($widget->size === 'full')>Вся ширина</option>
            </select>
        </label>
        <label>Цвет градиента (пусто — по умолчанию)
            <input type="text" name="gradient_color" maxlength="7" pattern="#[a-fA-F0-9]{6}" placeholder="#ff7900" value="{{ $widget->gradient_color }}">
        </label>
        <label>Показывать
            <select name="enabled"><option value="1" @selected($widget->enabled)>Да</option><option value="0" @selected(! $widget->enabled)>Нет</option></select>
        </label>
        <label>Пользователь сможет скрыть
            <select name="can_hide"><option value="1" @selected($widget->can_hide)>Да</option><option value="0" @selected(! $widget->can_hide)>Нет</option></select>
        </label>
        <label class="app-dashboard-admin__wide">Описание / содержание
            <textarea name="body" rows="3" maxlength="10000">{{ $widget->body }}</textarea>
        </label>
        @if ($widget->kind === 'account_status' || $isNew)
            <div class="app-dashboard-admin__wide app-dashboard-admin__sandbox-explain">
                Для типа «Статус аккаунта» доступны три HTML-текста. Пустое поле означает стандартный текст.
                Разрешены ссылки вида &lt;a class="button secondary" href="/account/roles"&gt;Мои роли&lt;/a&gt;.
            </div>
            @foreach (['status_confirmed_html' => 'Подтверждён', 'status_unconfirmed_html' => 'Не подтверждён', 'status_blocked_html' => 'Заблокирован'] as $htmlField => $htmlLabel)
                <label class="app-dashboard-admin__wide">HTML: {{ $htmlLabel }}
                    <textarea name="{{ $htmlField }}" rows="4" maxlength="10000">{{ $widget->{$htmlField} }}</textarea>
                </label>
            @endforeach
        @endif
        <label>Ссылка (внутренняя /... или внешняя HTTPS/HTTP)
            <input name="link_url" type="text" maxlength="500" value="{{ $widget->link_url }}" placeholder="/partners или https://example.com">
        </label>
        <label>Изображение для рекламной карточки (HTTPS/HTTP)
            <input name="image_url" type="url" maxlength="500" value="{{ $widget->image_url }}" placeholder="https://example.com/promo.jpg">
        </label>
        <label>Текст кнопки
            <input name="link_label" maxlength="100" value="{{ $widget->link_label }}" placeholder="Подробнее">
        </label>
        <fieldset class="app-dashboard-admin__wide">
            <legend>Статусы аккаунта (пусто = все)</legend>
            @foreach ($statuses as $status)
                <label class="app-dashboard-admin__check">
                    <input type="checkbox" name="account_statuses[]" value="{{ $status->value }}"
                           @checked(in_array($status->value, $widget->account_statuses ?? [], true))>{{ $status->label() }}
                </label>
            @endforeach
        </fieldset>
        <fieldset class="app-dashboard-admin__wide">
            <legend>Системные роли (пусто = все)</legend>
            @foreach ($systemRoles as $role)
                <label class="app-dashboard-admin__check"><input type="checkbox" name="system_roles[]" value="{{ $role->value }}"
                    @checked(in_array($role->value, $widget->system_roles ?? [], true))>{{ $role->label() }}</label>
            @endforeach
        </fieldset>
        <fieldset class="app-dashboard-admin__wide">
            <legend>Роли участия (пусто = все, иначе любая из выбранных)</legend>
            @foreach ($participationRoles as $role)
                <label class="app-dashboard-admin__check"><input type="checkbox" name="participation_roles[]" value="{{ $role->value }}"
                    @checked(in_array($role->value, $widget->participation_roles ?? [], true))>{{ $role->label() }}</label>
            @endforeach
        </fieldset>
        <label>Подтверждённый основной контакт
            <select name="contact_filter">
                <option value="any" @selected($widget->contact_filter === 'any')>Неважно</option>
                <option value="verified" @selected($widget->contact_filter === 'verified')>Есть</option>
                <option value="unverified" @selected($widget->contact_filter === 'unverified')>Нет</option>
            </select>
        </label>
        <label>Явно включить ID пользователей
            <input name="include_user_ids" value="{{ implode(', ', $widget->include_user_ids ?? []) }}" placeholder="12, 35, 91">
        </label>
        <label>Исключить ID пользователей
            <input name="exclude_user_ids" value="{{ implode(', ', $widget->exclude_user_ids ?? []) }}" placeholder="18, 44">
        </label>
        @if ($canEditCode)
            <div class="app-dashboard-admin__wide app-dashboard-admin__sandbox-explain">HTML/CSS/JS используется только для типа «HTML / CSS / JS». Исполняется в iframe без доступа к cookies, DOM портала и API. Внешние запросы из JavaScript запрещены.</div>
            <label class="app-dashboard-admin__wide">HTML-код <textarea name="html_code" rows="5" maxlength="40000">{{ $widget->html_code }}</textarea></label>
            <label class="app-dashboard-admin__wide">CSS <textarea name="css_code" rows="4" maxlength="20000">{{ $widget->css_code }}</textarea></label>
            <label class="app-dashboard-admin__wide">JavaScript <textarea name="js_code" rows="4" maxlength="20000">{{ $widget->js_code }}</textarea></label>
            <label>Высота HTML-виджета, px <input name="iframe_height" type="number" min="120" max="900" value="{{ $widget->iframe_height ?? 260 }}"></label>
        @else
            <input type="hidden" name="iframe_height" value="{{ $widget->iframe_height ?? 260 }}">
        @endif
    </div>
    <button class="button primary" type="submit">{{ $isNew ? 'Создать виджет' : 'Сохранить изменения' }}</button>
</form>
