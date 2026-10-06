@php
    $title = 'Вознаграждения';
@endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Каталог бонусных вознаграждений. Условия и номиналы версионируются, а начисление можно включить только для реализованного механизма.',
])

@section('section-content')
    <datalist id="reward-mechanism-codes">
        @foreach($implementedMechanisms as $code => $label)
            <option value="{{ $code }}">{{ $label }}</option>
        @endforeach
    </datalist>

    <div class="admin-settings-grid">
        <section class="admin-acquisition-card">
            <div>
                <h2>Новое вознаграждение</h2>
                <p class="admin-muted">
                    Запись можно создать заранее. Пока механизм не зарегистрирован в Rewards, реальное начисление включить нельзя.
                </p>
            </div>

            <form method="POST" action="{{ route('admin.rewards.store') }}" class="admin-acquisition-grid">
                @csrf
                <input type="hidden" name="is_enabled" value="0">

                <label class="admin-acquisition-field">
                    <span class="form-label">Стабильный код</span>
                    <input class="form-control" type="text" name="code" value="{{ old('code') }}" placeholder="profile_completed" required>
                </label>

                <label class="admin-acquisition-field">
                    <span class="form-label">Название</span>
                    <input class="form-control" type="text" name="name" value="{{ old('name') }}" required>
                </label>

                <label class="admin-acquisition-field">
                    <span class="form-label">Код механизма</span>
                    <input class="form-control" type="text" name="mechanism_code" value="{{ old('mechanism_code') }}" list="reward-mechanism-codes" placeholder="profile_completed">
                </label>

                <label class="admin-acquisition-field">
                    <span class="form-label">Номинал, ₽ bonus</span>
                    <input class="form-control" type="number" min="0.01" max="10000000" step="0.01" name="amount_rub" value="{{ old('amount_rub') }}" required>
                </label>

                <label class="admin-acquisition-field admin-acquisition-field--wide">
                    <span class="form-label">Описание</span>
                    <textarea class="form-control" rows="2" name="description">{{ old('description') }}</textarea>
                </label>

                <label class="admin-acquisition-field admin-acquisition-field--wide">
                    <span class="form-label">Кто получает</span>
                    <textarea class="form-control" rows="2" name="recipient_description" required>{{ old('recipient_description') }}</textarea>
                </label>

                <label class="admin-acquisition-field admin-acquisition-field--wide">
                    <span class="form-label">Когда возникает право</span>
                    <textarea class="form-control" rows="2" name="trigger_description" required>{{ old('trigger_description') }}</textarea>
                </label>

                <label class="admin-acquisition-field admin-acquisition-field--wide">
                    <span class="form-label">Условия</span>
                    <textarea class="form-control" rows="3" name="conditions">{{ old('conditions') }}</textarea>
                </label>

                <div class="admin-acquisition-field admin-acquisition-field--wide">
                    <button class="btn btn--primary" type="submit">Создать вознаграждение</button>
                </div>
            </form>
        </section>

        @forelse($rewards as $reward)
            @php
                $version = $reward->currentVersion;
                $mechanismImplemented = $reward->mechanism_code !== null
                    && array_key_exists($reward->mechanism_code, $implementedMechanisms);
            @endphp

            <section class="admin-acquisition-card">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <h2>{{ $reward->name }}</h2>
                        <p class="admin-muted">
                            <code>{{ $reward->code }}</code>
                            · механизм:
                            <strong>{{ $mechanismImplemented ? 'реализован' : 'не подключён' }}</strong>
                            · начисление:
                            <strong>{{ $reward->is_enabled ? 'включено' : 'выключено' }}</strong>
                        </p>
                    </div>

                    @if($version)
                        <strong>{{ $version->formattedAmount() }} bonus</strong>
                    @endif
                </div>

                <form method="POST" action="{{ route('admin.rewards.update', $reward) }}" class="admin-acquisition-grid">
                    @csrf
                    @method('PUT')

                    <label class="admin-acquisition-field">
                        <span class="form-label">Название</span>
                        <input class="form-control" type="text" name="name" value="{{ $reward->name }}" required>
                    </label>

                    <label class="admin-acquisition-field">
                        <span class="form-label">Код механизма</span>
                        <input class="form-control" type="text" name="mechanism_code" value="{{ $reward->mechanism_code }}" list="reward-mechanism-codes">
                    </label>

                    <label class="admin-acquisition-field">
                        <span class="form-label">Номинал, ₽ bonus</span>
                        <input
                            class="form-control"
                            type="number"
                            min="0.01"
                            max="10000000"
                            step="0.01"
                            name="amount_rub"
                            value="{{ $version ? number_format($version->amount_minor / 100, 2, '.', '') : '' }}"
                            required
                        >
                    </label>

                    <label class="admin-acquisition-field">
                        <span class="form-label">Начисление</span>
                        <input type="hidden" name="is_enabled" value="0">
                        <label class="form-check">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="is_enabled"
                                value="1"
                                @checked($reward->is_enabled)
                                @disabled(! $mechanismImplemented)
                            >
                            <span>{{ $mechanismImplemented ? 'Включено' : 'Недоступно до реализации механизма' }}</span>
                        </label>
                    </label>

                    <label class="admin-acquisition-field admin-acquisition-field--wide">
                        <span class="form-label">Описание</span>
                        <textarea class="form-control" rows="2" name="description">{{ $reward->description }}</textarea>
                    </label>

                    <label class="admin-acquisition-field admin-acquisition-field--wide">
                        <span class="form-label">Кто получает</span>
                        <textarea class="form-control" rows="2" name="recipient_description" required>{{ $version?->recipient_description }}</textarea>
                    </label>

                    <label class="admin-acquisition-field admin-acquisition-field--wide">
                        <span class="form-label">Когда возникает право</span>
                        <textarea class="form-control" rows="2" name="trigger_description" required>{{ $version?->trigger_description }}</textarea>
                    </label>

                    <label class="admin-acquisition-field admin-acquisition-field--wide">
                        <span class="form-label">Условия</span>
                        <textarea class="form-control" rows="3" name="conditions">{{ $version?->conditions }}</textarea>
                    </label>

                    <div class="admin-acquisition-field admin-acquisition-field--wide d-flex gap-2">
                        <button class="btn btn--primary" type="submit">Сохранить</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('admin.rewards.destroy', $reward) }}" onsubmit="return confirm('Удалить вознаграждение из активного каталога? История останется в системе.');">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn--secondary btn--sm" type="submit">Удалить из каталога</button>
                </form>

                @if($reward->versions->isNotEmpty())
                    <details class="mt-3">
                        <summary>История условий и номинала ({{ $reward->versions->count() }})</summary>
                        <div class="table-responsive mt-2">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Версия</th>
                                        <th>Номинал</th>
                                        <th>Получатель</th>
                                        <th>Условие / момент</th>
                                        <th>Период</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($reward->versions as $historyVersion)
                                        <tr>
                                            <td>v{{ $historyVersion->version_number }}</td>
                                            <td>{{ $historyVersion->formattedAmount() }} bonus</td>
                                            <td>{{ $historyVersion->recipient_description }}</td>
                                            <td>
                                                <div>{{ $historyVersion->trigger_description }}</div>
                                                @if($historyVersion->conditions)
                                                    <small class="admin-muted">{{ $historyVersion->conditions }}</small>
                                                @endif
                                            </td>
                                            <td>
                                                {{ $historyVersion->valid_from?->format('d.m.Y H:i') }}
                                                —
                                                {{ $historyVersion->valid_until?->format('d.m.Y H:i') ?? 'сейчас' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endif
            </section>
        @empty
            <section class="admin-acquisition-card">
                <p class="admin-muted">Каталог вознаграждений пока пуст.</p>
            </section>
        @endforelse
    </div>
@endsection
