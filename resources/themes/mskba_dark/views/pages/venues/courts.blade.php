@php
    $venueDisplayName = preg_replace('/\s*[-–—]\s*\d+\s+зал(?:а|ов)?\s*$/ui', '', $venue->name) ?: $venue->name;
    $courtCount = $courts->count();
    $courtCountLabel = ($courtCount % 10 === 1 && $courtCount % 100 !== 11)
        ? 'зал'
        : (in_array($courtCount % 10, [2, 3, 4], true) && ! in_array($courtCount % 100, [12, 13, 14], true) ? 'зала' : 'залов');
    $title = 'Залы · '.$venueDisplayName.' · '.$courtCount.' '.$courtCountLabel;
    $venueSidebarActive = 'courts';
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Управление площадкой',
])

@section('section-sidebar')
    @include('theme::partials.venues.internal-sidebar')
@endsection

@section('section-content')
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h4 mb-2">Игровые залы площадки</h2>
            <p class="text-muted mb-0">
                Каждый зал — отдельное физическое игровое пространство. Количество колец определяет возможность деления на две половины. Новые залы по умолчанию наследуют разрешения на аренду от действующих условий площадки.
            </p>
        </div>
    </div>

    @foreach($courts as $court)
        <article class="card mb-3">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-3">
                    <div>
                        <strong>{{ $court->name }}</strong>
                        @if($court->is_primary)
                            <span class="badge text-bg-success ms-2">Основной</span>
                        @endif
                        <div class="text-muted small">/{{ $court->alias }}</div>
                    </div>
                    @unless($court->is_primary)
                        <form method="POST" action="{{ route('account.venues.courts.primary', [$venue->routeIdentifier(), $court->routeIdentifier()]) }}">
                            @csrf
                            <button type="submit" class="btn btn--secondary btn--sm">Сделать основным</button>
                        </form>
                    @endunless
                </div>

                <form method="POST" action="{{ route('account.venues.courts.update', [$venue->routeIdentifier(), $court->routeIdentifier()]) }}" data-court-form>
                    @csrf
                    @method('PUT')
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label" for="court-name-{{ $court->id }}">Название</label>
                            <input id="court-name-{{ $court->id }}" class="form-control" name="name" value="{{ $court->name }}" maxlength="120" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="court-alias-{{ $court->id }}">Alias</label>
                            <input id="court-alias-{{ $court->id }}" class="form-control" name="alias" value="{{ $court->alias }}" maxlength="120">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="court-order-{{ $court->id }}">Порядок</label>
                            <input id="court-order-{{ $court->id }}" class="form-control" type="number" min="0" max="65535" name="sort_order" value="{{ $court->sort_order }}" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="court-hoops-{{ $court->id }}">Количество колец</label>
                            <select id="court-hoops-{{ $court->id }}" class="form-select" name="hoops_count" data-court-hoops required>
                                <option value="1" @selected((int) ($court->hoops_count ?? 1) === 1)>1</option>
                                <option value="2" @selected((int) ($court->hoops_count ?? 1) === 2)>2</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="court-surface-{{ $court->id }}">Покрытие</label>
                            <select id="court-surface-{{ $court->id }}" class="form-select" name="surface_type">
                                <option value="">Не указано</option>
                                @foreach($surfaceTypes as $surfaceType)
                                    <option value="{{ $surfaceType->value }}" @selected($court->surface_type?->value === $surfaceType->value)>{{ $surfaceType->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="mt-3 d-flex flex-column gap-2" data-court-scope-options>
                        <label>
                            <input type="hidden" name="allows_whole" value="0">
                            <input type="checkbox" name="allows_whole" value="1" @checked($court->allows_whole)>
                            Разрешить аренду всего зала
                        </label>
                        <label data-court-half-option>
                            <input type="hidden" name="allows_halves" value="0">
                            <input type="checkbox" name="allows_halves" value="1" @checked($court->allows_halves)>
                            Разрешать аренду половин
                        </label>
                        <small class="text-muted" data-court-halves-hint>Аренда половин доступна только для зала с двумя кольцами.</small>
                    </div>
                    <button type="submit" class="btn btn--primary btn--sm mt-3">Сохранить зал</button>
                </form>

                @include('theme::partials.venues.court-gallery-editor', [
                    'court' => $court,
                    'photos' => $courtPhotos[$court->id] ?? [],
                ])

                @if($courts->count() > 1)
                    <form method="POST" action="{{ route('account.venues.courts.destroy', [$venue->routeIdentifier(), $court->routeIdentifier()]) }}" class="mt-3" onsubmit="return confirm('Удалить этот зал?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn--danger btn--sm">Удалить</button>
                    </form>
                @endif
            </div>
        </article>
    @endforeach

    <div class="card mt-4">
        <div class="card-body">
            <h2 class="h4 mb-3">Добавить зал</h2>
            <form method="POST" action="{{ route('account.venues.courts.store', $venue->routeIdentifier()) }}" data-court-form>
                @csrf
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="court-new-name">Название</label>
                        <input id="court-new-name" class="form-control" name="name" value="{{ old('name') }}" maxlength="120" placeholder="Например: Малый зал" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="court-new-alias">Alias <span class="text-muted">(необязательно)</span></label>
                        <input id="court-new-alias" class="form-control" name="alias" value="{{ old('alias') }}" maxlength="120" placeholder="maliy-zal">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="court-new-order">Порядок</label>
                        <input id="court-new-order" class="form-control" type="number" min="0" max="65535" name="sort_order" value="{{ old('sort_order') }}" placeholder="авто">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="court-new-hoops">Количество колец</label>
                        <select id="court-new-hoops" class="form-select" name="hoops_count" data-court-hoops required>
                            <option value="1" @selected((int) old('hoops_count', $courtDefaults['hoops_count']) === 1)>1</option>
                            <option value="2" @selected((int) old('hoops_count', $courtDefaults['hoops_count']) === 2)>2</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="court-new-surface">Покрытие</label>
                        <select id="court-new-surface" class="form-select" name="surface_type">
                            <option value="">Не указано</option>
                            @foreach($surfaceTypes as $surfaceType)
                                <option value="{{ $surfaceType->value }}" @selected(old('surface_type') === $surfaceType->value)>{{ $surfaceType->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="mt-3 d-flex flex-column gap-2" data-court-scope-options>
                    <label>
                        <input type="hidden" name="allows_whole" value="0">
                        <input type="checkbox" name="allows_whole" value="1" @checked((bool) old('allows_whole', $courtDefaults['allows_whole']))>
                        Разрешить аренду всего зала
                    </label>
                    <label data-court-half-option>
                        <input type="hidden" name="allows_halves" value="0">
                        <input type="checkbox" name="allows_halves" value="1" @checked((bool) old('allows_halves', $courtDefaults['allows_halves']))>
                        Разрешать аренду половин
                    </label>
                    <small class="text-muted" data-court-halves-hint>Аренда половин доступна только для зала с двумя кольцами.</small>
                </div>
                <button type="submit" class="btn btn--primary btn--sm mt-3">Добавить зал</button>
            </form>
        </div>
    </div>
@endsection
