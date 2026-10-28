
<details class="account-contracts-workspace__card" @if($errors->any()) open @endif>
    <summary>Создать промокод</summary>
    <p>Новый промокод сначала сохраняется в черновике. Для участников группы он станет виден после публикации.</p>
    <form class="account-contracts-promo-form" method="POST" action="{{ route('account.venues.group-offers.store', ['venue' => $venue->id]) }}">
        @csrf
        <label>Название <input name="title" required minlength="3" maxlength="120" value="{{ old('title') }}"></label>
        <label>Промокод <input name="code" required minlength="5" maxlength="32" pattern="[A-Za-z0-9_-]+" value="{{ old('code') }}"></label>
        <label>Тип скидки <select name="discount_type"><option value="percent">Проценты</option><option value="fixed">Сумма (копейки)</option></select></label>
        <label>Размер скидки <input name="discount_value" type="number" min="1" required value="{{ old('discount_value') }}"></label>
        <label>Начало ({{ $venueTimezone }}) <input name="valid_from" type="datetime-local" required value="{{ old('valid_from') }}"></label>
        <label>Окончание (пусто = бессрочно) <input name="valid_until" type="datetime-local" value="{{ old('valid_until') }}"></label>
        <label>Зона <select name="scope"><option value="">Любая</option>@foreach(\App\Modules\Event\Domain\Enums\VenueBookingScopeEnum::cases() as $scope)<option value="{{ $scope->value }}">{{ $scope->label() }}</option>@endforeach</select></label>
        <label>Зал <select name="venue_court_id"><option value="">Любой</option>@foreach($offerCourts as $court)<option value="{{ $court->id }}">{{ $court->name }}</option>@endforeach</select></label>
        <label>Минимальная сумма (копейки) <input name="minimum_amount_minor" type="number" min="0" value="{{ old('minimum_amount_minor') }}"></label>
        <label>Общий лимит использований <input name="max_total_uses" type="number" min="1" value="{{ old('max_total_uses') }}"></label>
        <label>Лимит на пользователя <input name="max_uses_per_account" type="number" min="1" value="{{ old('max_uses_per_account') }}"></label>
        <label class="account-contracts-promo-form__wide">Описание <textarea name="description" maxlength="1000" rows="3">{{ old('description') }}</textarea></label>
        <button class="account-contracts-workspace__primary" type="submit">Сохранить черновик</button>
    </form>
</details>
