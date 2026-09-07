@php $title = 'Города и районы'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Справочник городов, административных округов и районов, используемый адресами и поиском.',
])

@section('section-content')
    @if(session('status'))
        <div class="alert alert-success mb-3">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger mb-3">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="admin-settings-grid">
        <section class="admin-settings-group">
            <h2 class="admin-settings-group__title">Добавить город</h2>
            <p class="admin-settings-group__description">Alias используется как стабильный системный идентификатор и должен состоять из латинских строчных букв, цифр и дефисов.</p>

            <form method="POST" action="{{ route('admin.geography.cities.store') }}" class="admin-settings-fields">
                @csrf

                <div class="admin-readonly-field">
                    <label for="newCityName">Название</label>
                    <input id="newCityName" class="form-control" name="name" value="{{ old('name') }}" maxlength="255" placeholder="Москва" required>
                </div>

                <div class="admin-readonly-field">
                    <label for="newCityAlias">Alias</label>
                    <input id="newCityAlias" class="form-control" name="alias" value="{{ old('alias') }}" maxlength="255" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" placeholder="moscow" required>
                </div>

                <div class="admin-readonly-field">
                    <label for="newCityShortName">Сокращённое название</label>
                    <input id="newCityShortName" class="form-control" name="short_name" value="{{ old('short_name') }}" maxlength="64" placeholder="Мск">
                </div>

                <div class="admin-readonly-field">
                    <label for="newCityDescription">Описание</label>
                    <textarea id="newCityDescription" class="form-control" name="description" rows="3" maxlength="2000">{{ old('description') }}</textarea>
                </div>

                <button class="btn btn--primary btn--sm" type="submit">Добавить город</button>
            </form>
        </section>

        @forelse($cities as $city)
            <section class="admin-settings-group">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h2 class="admin-settings-group__title mb-1">{{ $city->name }}</h2>
                        <p class="admin-settings-group__description mb-0">
                            {{ $city->short_name ?: 'без сокращения' }} · {{ $city->alias }} ·
                            районов/округов: {{ $city->districts->count() }} · адресов: {{ $city->addresses_count }}
                        </p>
                    </div>

                    <form method="POST" action="{{ route('admin.geography.cities.destroy', $city) }}" onsubmit="return confirm('Удалить город {{ addslashes($city->name) }}?')">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn--danger btn--sm" type="submit" @disabled($city->districts->isNotEmpty() || $city->addresses_count > 0)>Удалить</button>
                    </form>
                </div>

                <form method="POST" action="{{ route('admin.geography.cities.update', $city) }}" class="admin-settings-fields mb-4">
                    @csrf
                    @method('PUT')

                    <div class="admin-readonly-field">
                        <label for="cityName{{ $city->id }}">Название</label>
                        <input id="cityName{{ $city->id }}" class="form-control" name="name" value="{{ $city->name }}" maxlength="255" required>
                    </div>

                    <div class="admin-readonly-field">
                        <label for="cityAlias{{ $city->id }}">Alias</label>
                        <input id="cityAlias{{ $city->id }}" class="form-control" name="alias" value="{{ $city->alias }}" maxlength="255" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required>
                    </div>

                    <div class="admin-readonly-field">
                        <label for="cityShortName{{ $city->id }}">Сокращённое название</label>
                        <input id="cityShortName{{ $city->id }}" class="form-control" name="short_name" value="{{ $city->short_name }}" maxlength="64">
                    </div>

                    <div class="admin-readonly-field">
                        <label for="cityDescription{{ $city->id }}">Описание</label>
                        <textarea id="cityDescription{{ $city->id }}" class="form-control" name="description" rows="3" maxlength="2000">{{ $city->description }}</textarea>
                    </div>

                    <button class="btn btn--secondary btn--sm" type="submit">Сохранить город</button>
                </form>

                <h3 class="admin-settings-group__title">Районы и округа</h3>
                <p class="admin-settings-group__description">Для Москвы здесь используются административные округа, для Химок — районы и микрорайоны.</p>

                <form method="POST" action="{{ route('admin.geography.districts.store') }}" class="admin-settings-fields mb-4">
                    @csrf
                    <input type="hidden" name="city_id" value="{{ $city->id }}">

                    <div class="admin-readonly-field">
                        <label for="newDistrictName{{ $city->id }}">Название</label>
                        <input id="newDistrictName{{ $city->id }}" class="form-control" name="name" maxlength="255" placeholder="Северный административный округ" required>
                    </div>

                    <div class="admin-readonly-field">
                        <label for="newDistrictAlias{{ $city->id }}">Alias</label>
                        <input id="newDistrictAlias{{ $city->id }}" class="form-control" name="alias" maxlength="255" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" placeholder="sao" required>
                    </div>

                    <div class="admin-readonly-field">
                        <label for="newDistrictShortName{{ $city->id }}">Сокращённое название</label>
                        <input id="newDistrictShortName{{ $city->id }}" class="form-control" name="short_name" maxlength="64" placeholder="САО">
                    </div>

                    <div class="admin-readonly-field">
                        <label for="newDistrictDescription{{ $city->id }}">Описание</label>
                        <textarea id="newDistrictDescription{{ $city->id }}" class="form-control" name="description" rows="2" maxlength="2000"></textarea>
                    </div>

                    <button class="btn btn--primary btn--sm" type="submit">Добавить район/округ</button>
                </form>

                <div class="d-grid gap-3">
                    @forelse($city->districts as $district)
                        <div class="section-card">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                                <div>
                                    <strong>{{ $district->short_name ?: $district->name }}</strong>
                                    <div class="form-text">{{ $district->alias }} · адресов: {{ $district->addresses_count }}</div>
                                </div>

                                <form method="POST" action="{{ route('admin.geography.districts.destroy', $district) }}" onsubmit="return confirm('Удалить район или округ {{ addslashes($district->name) }}?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn--danger btn--sm" type="submit" @disabled($district->addresses_count > 0)>Удалить</button>
                                </form>
                            </div>

                            <form method="POST" action="{{ route('admin.geography.districts.update', $district) }}" class="admin-settings-fields">
                                @csrf
                                @method('PUT')

                                <div class="admin-readonly-field">
                                    <label for="districtCity{{ $district->id }}">Город</label>
                                    <select id="districtCity{{ $district->id }}" class="form-select" name="city_id" required>
                                        @foreach($cities as $cityOption)
                                            <option value="{{ $cityOption->id }}" @selected($cityOption->id === $district->city_id)>{{ $cityOption->name }}</option>
                                        @endforeach
                                    </select>
                                    @if($district->addresses_count > 0)
                                        <div class="form-text">Перенос в другой город недоступен, пока район используется в адресах.</div>
                                    @endif
                                </div>

                                <div class="admin-readonly-field">
                                    <label for="districtName{{ $district->id }}">Название</label>
                                    <input id="districtName{{ $district->id }}" class="form-control" name="name" value="{{ $district->name }}" maxlength="255" required>
                                </div>

                                <div class="admin-readonly-field">
                                    <label for="districtAlias{{ $district->id }}">Alias</label>
                                    <input id="districtAlias{{ $district->id }}" class="form-control" name="alias" value="{{ $district->alias }}" maxlength="255" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required>
                                </div>

                                <div class="admin-readonly-field">
                                    <label for="districtShortName{{ $district->id }}">Сокращённое название</label>
                                    <input id="districtShortName{{ $district->id }}" class="form-control" name="short_name" value="{{ $district->short_name }}" maxlength="64">
                                </div>

                                <div class="admin-readonly-field">
                                    <label for="districtDescription{{ $district->id }}">Описание</label>
                                    <textarea id="districtDescription{{ $district->id }}" class="form-control" name="description" rows="2" maxlength="2000">{{ $district->description }}</textarea>
                                </div>

                                <button class="btn btn--secondary btn--sm" type="submit">Сохранить район/округ</button>
                            </form>
                        </div>
                    @empty
                        <p class="form-text mb-0">Для этого города районы или округа пока не заведены.</p>
                    @endforelse
                </div>
            </section>
        @empty
            <section class="admin-settings-group">
                <p class="mb-0">Города пока не добавлены.</p>
            </section>
        @endforelse
    </div>
@endsection
