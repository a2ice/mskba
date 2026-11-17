@extends('theme::layouts.account', ['title' => 'Дашборд пользователя — управление'])
@section('account-heading')
    @include('theme::pages.account.partials.section-heading', ['heading' => 'Дашборд пользователей',
        'intro' => 'Виджеты, рекламные размещения и адресное отображение в личных кабинетах.'])
@endsection
@section('account-content')
    <section class="app-dashboard-admin" aria-label="Управление виджетами">
        <p><a class="button secondary" href="{{ route('account') }}">Посмотреть свой дашборд</a></p>
        <p class="app-dashboard-admin__note">Сохранение сразу меняет карточки для всех подходящих пользователей через Reverb. Исключённые ID всегда имеют приоритет. Явно включённые ID видят виджет независимо от фильтров статуса и ролей. Пустые фильтры означают «всем».</p>
        @if (session('status'))<div class="panel app-dashboard-admin__flash" role="status">{{ session('status') }}</div>@endif
        @if ($errors->any())
            <div class="notice" role="alert"><strong>Не удалось сохранить:</strong>
                <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        <details class="panel app-dashboard-admin__item" open>
            <summary><strong>Создать виджет</strong></summary>
            @php
                $newWidget = new \App\Modules\Dashboard\Domain\Models\AccountDashboardWidget([
                    'kind' => 'information', 'size' => 'half', 'sort_order' => 100, 'enabled' => true,
                    'can_hide' => true, 'contact_filter' => 'any', 'iframe_height' => 260,
                ]);
            @endphp
            @include('theme::pages.admin.partials.account-dashboard-widget-form', ['widget' => $newWidget, 'isNew' => true])
        </details>
        <h2>Размещённые виджеты ({{ $widgets->count() }})</h2>
        @foreach ($widgets as $widget)
            <details class="panel app-dashboard-admin__item" id="widget-{{ $widget->id }}">
                <summary>
                    <span><strong>{{ $widget->title }}</strong> <small>{{ $kinds[$widget->kind] ?? $widget->kind }} · №{{ $widget->id }}</small></span>
                    <span>{{ $widget->enabled ? 'Активен' : 'Выключен' }} · {{ $widget->sort_order }}</span>
                </summary>
                @include('theme::pages.admin.partials.account-dashboard-widget-form', ['isNew' => false])
                @if ($canEditCode)
                    <form method="POST" action="{{ route('admin.account-dashboard.widgets.destroy', $widget) }}"
                          onsubmit="return confirm('Удалить виджет без возможности восстановления?')">
                        @csrf @method('DELETE')
                        <button class="button secondary" type="submit">Удалить виджет</button>
                    </form>
                @endif
            </details>
        @endforeach
    </section>
@endsection
