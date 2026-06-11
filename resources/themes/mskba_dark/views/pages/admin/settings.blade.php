@php $title = 'Настройки'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Базовый read-only шаблон настроек. Сохранение будет отдельной задачей.',
])

@section('section-content')
    <div class="admin-settings-grid">
        @foreach($groups as $group)
            <section class="admin-settings-group">
                <h2 class="admin-settings-group__title">{{ $group['title'] }}</h2>
                <p class="admin-settings-group__description">{{ $group['description'] }}</p>

                <div class="admin-settings-fields">
                    @foreach($group['fields'] as $field)
                        <div class="admin-readonly-field">
                            <label>{{ $field['label'] }}</label>
                            @if($field['type'] === 'textarea')
                                <textarea class="form-control" rows="3" disabled>{{ $field['value'] }}</textarea>
                            @else
                                <input class="form-control" type="text" value="{{ $field['value'] }}" disabled>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endsection
