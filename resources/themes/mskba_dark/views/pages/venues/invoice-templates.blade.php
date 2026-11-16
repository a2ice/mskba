@php
    $title = 'Шаблоны счетов · '.$venue->name;
    $methods = ['bank_transfer' => 'Банковский перевод', 'cash' => 'Наличные', 'other' => 'Иной способ'];
@endphp
@extends('theme::layouts.app', ['title' => $title])

@section('content')
<div class="first-screen venue-management-page" style="padding-top:2rem;padding-bottom:2rem">
    <h1>{{ $title }}</h1>
    <p>Глобальные, площадочные и агентские шаблоны. Сумма берётся из зафиксированного расчёта бронирования, а не из текста шаблона.</p>
    <p><a href="{{ route('account.venues.booking-policy.edit', $venue->id) }}">К условиям аренды</a></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif

    <section class="card" style="padding:1rem;margin:1rem 0">
        <h2>Новый шаблон</h2>
        <form method="POST" action="{{ route('account.venues.invoice-templates.store', $venue->id) }}">
            @csrf
            <label>Название шаблона<input name="name" maxlength="120" required></label>
            <label>Тип
                <select name="scope" required>
                    @if($canGlobal)<option value="global">Глобальный MSKBA</option>@endif
                    @if($canVenue)<option value="venue">Для площадки</option>@endif
                    @if($canAgent)<option value="agent">Мой агентский</option>@endif
                </select>
            </label>
            <label>Заголовок счёта<input name="title" maxlength="190" required value="Аренда зала"></label>
            <label>Описание<textarea name="description" maxlength="2000" rows="2"></textarea></label>
            <p>Доступные способы оплаты</p>
            @foreach($methods as $method => $label)
                <label><input type="checkbox" name="payment_methods[]" value="{{ $method }}" @checked($method === 'bank_transfer')>{{ $label }}</label>
            @endforeach
            <label>Инструкция по оплате<textarea name="payment_instructions" maxlength="4000" rows="3"></textarea></label>
            <label>Срок оплаты (мин)<input type="number" name="due_minutes" value="30" min="1" max="1440" required></label>
            <label>Приоритет<input type="number" name="priority" value="100" min="0" max="1000" required></label>
            <label><input type="checkbox" name="auto_send" value="1">Выставлять автоматически после подтверждения холда</label>
            <input type="hidden" name="enabled" value="1">
            <button class="btn btn--primary btn--sm" type="submit">Создать шаблон</button>
        </form>
    </section>

    <h2>Сохранённые шаблоны</h2>
    @forelse($templates as $template)
        @php
            $canEdit = match($template->scope) {
                'global' => $canGlobal,
                'venue' => $canVenue,
                'agent' => $canAgent && (int) $template->owner_user_id === (int) auth()->id(),
                default => false,
            };
        @endphp
        <section class="card" style="padding:1rem;margin:1rem 0">
            <h3>{{ $template->name }} · {{ $template->scope }}
                @if(!$template->enabled)<span>(отключён)</span>@endif
            </h3>
            @if($canEdit)
                <form method="POST" action="{{ route('account.venues.invoice-templates.update', [$venue->id, $template]) }}">
                    @csrf @method('PUT')
                    <input type="hidden" name="scope" value="{{ $template->scope }}">
                    <label>Название<input name="name" value="{{ $template->name }}" maxlength="120" required></label>
                    <label>Заголовок<input name="title" value="{{ $template->title }}" maxlength="190" required></label>
                    <label>Описание<textarea name="description" maxlength="2000">{{ $template->description }}</textarea></label>
                    <p>Способы оплаты</p>
                    @foreach($methods as $method => $label)
                        <label><input type="checkbox" name="payment_methods[]" value="{{ $method }}"
                            @checked(in_array($method, $template->payment_methods ?? [], true))>{{ $label }}</label>
                    @endforeach
                    <label>Инструкция по оплате<textarea name="payment_instructions" maxlength="4000">{{ $template->payment_instructions }}</textarea></label>
                    <label>Срок оплаты, мин<input type="number" name="due_minutes" min="1" max="1440" required value="{{ $template->due_minutes }}"></label>
                    <label>Приоритет<input type="number" name="priority" min="0" max="1000" required value="{{ $template->priority }}"></label>
                    <label><input type="checkbox" name="auto_send" value="1" @checked($template->auto_send)>Автоматическое выставление</label>
                    <label><input type="checkbox" name="enabled" value="1" @checked($template->enabled)>Активен</label>
                    <button class="btn btn--primary btn--sm" type="submit">Сохранить</button>
                </form>
                <form method="POST" action="{{ route('account.venues.invoice-templates.destroy', [$venue->id, $template]) }}">
                    @csrf @method('DELETE')
                    <button class="btn btn--outline-secondary btn--sm" type="submit">Отключить</button>
                </form>
            @else
                <p>{{ $template->title }} · {{ $template->due_minutes }} мин · {{ $template->auto_send ? 'Автоматический' : 'Ручной' }}</p>
            @endif
        </section>
    @empty
        <p>Пока нет сохранённых шаблонов. Можно использовать стандартный счёт.</p>
    @endforelse
</div>
@endsection
